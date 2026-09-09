<?php
/**
 * cron/update_viral_scores.php
 * NewsXpressLive — Batch Viral Score Updater
 *
 * Recalculates viral_score and is_trending for every published article.
 * Designed to run hourly via cron:
 *
 *   0 * * * * php /path/to/newsxpresslive/cron/update_viral_scores.php >> /path/to/logs/viral_scores.log 2>&1
 *
 * CLI ONLY — cannot be called via HTTP.
 *
 * Options:
 *   --hours=24   Only recalculate articles published/updated in the last N hours (default: all)
 *   --dry-run    Print scores without saving
 *   --limit=500  Max articles per run (default: 0 = unlimited)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// ── Parse CLI options ─────────────────────────────────────────────────────

$opts    = getopt('', ['hours:', 'dry-run', 'limit:']);
$dryRun  = isset($opts['dry-run']);
$hours   = isset($opts['hours'])  ? max(1, (int)$opts['hours'])  : 0;
$limit   = isset($opts['limit'])  ? max(1, (int)$opts['limit'])  : 0;

// ── Bootstrap ─────────────────────────────────────────────────────────────

// Try both possible config locations (web app vs CLI working directory)
$configCandidates = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../web/includes/config.php',
];
$loaded = false;
foreach ($configCandidates as $cfg) {
    if (file_exists($cfg)) {
        require_once $cfg;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "ERROR: Could not find database config.\n");
    exit(1);
}

require_once __DIR__ . '/../web/includes/viral_score.php';

$started = date('Y-m-d H:i:s');
echo "[{$started}] update_viral_scores.php started"
    . ($dryRun ? ' (DRY RUN)' : '')
    . ($hours  ? " (last {$hours}h only)" : '')
    . "\n";

// ── Fetch articles to process ─────────────────────────────────────────────

$where  = "status = 'published'";
$params = [];

if ($hours > 0) {
    $where   .= ' AND (viral_score_updated_at IS NULL OR viral_score_updated_at <= NOW() - INTERVAL :decay HOUR)';
    $params[':decay'] = $hours;
}

$sql = "SELECT id, views, shares_count, created_at FROM news WHERE {$where} ORDER BY id ASC";
if ($limit > 0) {
    $sql .= " LIMIT {$limit}";
}

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (empty($articles)) {
    echo "[" . date('H:i:s') . "] No articles to process.\n";
    exit(0);
}

echo "[" . date('H:i:s') . "] Processing " . count($articles) . " articles…\n";

// ── Preload weights once ──────────────────────────────────────────────────

$weights   = getViralWeights($pdo);
$threshold = $weights['threshold'];

// ── Process each article ──────────────────────────────────────────────────

$updated    = 0;
$nowTrending = 0;
$errors     = 0;

foreach ($articles as $article) {
    $newsId = (int)$article['id'];

    try {
        // Approved comment count
        $cmtStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM comments WHERE news_id = :id AND status = 'approved'"
        );
        $cmtStmt->execute([':id' => $newsId]);
        $comments = (int)$cmtStmt->fetchColumn();

        // Total watch minutes
        $watchStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(time_spent), 0) / 60.0 FROM user_behavior WHERE news_id = :id'
        );
        $watchStmt->execute([':id' => $newsId]);
        $watchMin = (float)$watchStmt->fetchColumn();

        $score      = computeViralScore($article, $comments, $watchMin, $weights);
        $isTrending = ($score >= $threshold) ? 1 : 0;

        if ($dryRun) {
            echo sprintf(
                "  [DRY] id=%-6d score=%-8.2f trending=%d  (views=%d shares=%d comments=%d watch=%.1fmin)\n",
                $newsId, $score, $isTrending,
                (int)$article['views'], (int)($article['shares_count'] ?? 0),
                $comments, $watchMin
            );
        } else {
            $pdo->prepare(
                'UPDATE news
                 SET viral_score            = :score,
                     is_trending            = :trending,
                     viral_score_updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                ':score'    => $score,
                ':trending' => $isTrending,
                ':id'       => $newsId,
            ]);
        }

        $updated++;
        if ($isTrending) {
            $nowTrending++;
        }

    } catch (PDOException $e) {
        $errors++;
        error_log("update_viral_scores: article $newsId failed: " . $e->getMessage());
    }
}

$finished = date('Y-m-d H:i:s');
echo "[{$finished}] Done. updated={$updated} trending={$nowTrending} errors={$errors}\n";

// Non-zero exit code if there were errors so cron can alert
exit($errors > 0 ? 1 : 0);
