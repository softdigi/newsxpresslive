<?php
/**
 * web/includes/viral_score.php
 * NewsXpressLive — Viral Score System
 *
 * Score formula (all weights configurable via settings table):
 *
 *   raw_score = (views  * w_view)
 *             + (shares * w_share)
 *             + (comments * w_comment)
 *             + (total_watch_minutes * w_watch_min)
 *
 *   age_factor = published_hours < decay_hours
 *                  ? 1.0
 *                  : 0.5                 (50% penalty after decay window)
 *
 *   viral_score = raw_score * age_factor
 *
 * is_trending is set to 1 when viral_score >= viral_trending_threshold.
 *
 * Usage:
 *   require_once __DIR__ . '/viral_score.php';
 *   updateViralScore($pdo, $newsId);
 */

/**
 * Return all viral-score weights from the settings table.
 * Values are cached in a static variable for the request lifetime.
 *
 * @param PDO $pdo
 * @return array{w_view:float, w_share:float, w_comment:float,
 *               w_watch_min:float, threshold:float, decay_hours:int}
 */
function getViralWeights(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $defaults = [
        'viral_weight_view'        => 1.0,
        'viral_weight_share'       => 3.0,
        'viral_weight_comment'     => 2.0,
        'viral_weight_watch_min'   => 1.5,
        'viral_trending_threshold' => 50.0,
        'viral_decay_hours'        => 72,
    ];

    try {
        $keys  = implode(',', array_fill(0, count($defaults), '?'));
        $stmt  = $pdo->prepare(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($keys)"
        );
        $stmt->execute(array_keys($defaults));
        foreach ($stmt->fetchAll() as $row) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        // settings table may not exist on first deploy — use defaults
        error_log('getViralWeights: ' . $e->getMessage());
    }

    $cache = [
        'w_view'       => (float)$defaults['viral_weight_view'],
        'w_share'      => (float)$defaults['viral_weight_share'],
        'w_comment'    => (float)$defaults['viral_weight_comment'],
        'w_watch_min'  => (float)$defaults['viral_weight_watch_min'],
        'threshold'    => (float)$defaults['viral_trending_threshold'],
        'decay_hours'  => (int)$defaults['viral_decay_hours'],
    ];

    return $cache;
}

/**
 * Compute the viral score for a single article without writing to the DB.
 *
 * @param array  $news     Row with keys: views, shares_count, created_at
 * @param int    $comments Approved comment count
 * @param float  $watchMin Total watch/read minutes (from user_behavior)
 * @param array  $weights  From getViralWeights()
 * @return float
 */
function computeViralScore(
    array  $news,
    int    $comments,
    float  $watchMin,
    array  $weights
): float {
    $views  = max(0, (int)($news['views']        ?? 0));
    $shares = max(0, (int)($news['shares_count'] ?? 0));

    $raw = ($views   * $weights['w_view'])
         + ($shares  * $weights['w_share'])
         + ($comments * $weights['w_comment'])
         + ($watchMin * $weights['w_watch_min']);

    // Age decay: articles older than decay_hours receive a 50% score penalty
    $createdAt    = strtotime($news['created_at'] ?? 'now');
    $ageHours     = (time() - $createdAt) / 3600.0;
    $ageFactor    = ($ageHours <= $weights['decay_hours']) ? 1.0 : 0.5;

    return round($raw * $ageFactor, 4);
}

/**
 * Recalculate, persist, and return the viral score for a single article.
 * Also toggles is_trending based on the threshold.
 *
 * @param PDO $pdo
 * @param int $newsId
 * @return float  The new viral score, or 0.0 on failure.
 */
function updateViralScore(PDO $pdo, int $newsId): float
{
    if ($newsId <= 0) {
        return 0.0;
    }

    try {
        // ── Fetch article base stats ──────────────────────────────────────
        $stmt = $pdo->prepare(
            'SELECT views, shares_count, created_at
             FROM news WHERE id = :id AND status = :st LIMIT 1'
        );
        $stmt->execute([':id' => $newsId, ':st' => 'approved']);
        $news = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$news) {
            return 0.0;
        }

        // ── Approved comment count ────────────────────────────────────────
        $cmtStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM comments
             WHERE news_id = :id AND status = 'approved'"
        );
        $cmtStmt->execute([':id' => $newsId]);
        $comments = (int)$cmtStmt->fetchColumn();

        // ── Total watch / read minutes from user_behavior ─────────────────
        $watchStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(time_spent), 0) / 60.0 AS watch_min
             FROM user_behavior
             WHERE news_id = :id"
        );
        $watchStmt->execute([':id' => $newsId]);
        $watchMin = (float)$watchStmt->fetchColumn();

        // ── Compute score ─────────────────────────────────────────────────
        $weights    = getViralWeights($pdo);
        $score      = computeViralScore($news, $comments, $watchMin, $weights);
        $isTrending = ($score >= $weights['threshold']) ? 1 : 0;

        // ── Persist ───────────────────────────────────────────────────────
        $pdo->prepare(
            'UPDATE news
             SET viral_score              = :score,
                 is_trending              = :trending,
                 viral_score_updated_at   = NOW()
             WHERE id = :id'
        )->execute([
            ':score'    => $score,
            ':trending' => $isTrending,
            ':id'       => $newsId,
        ]);

        return $score;

    } catch (PDOException $e) {
        error_log("updateViralScore($newsId): " . $e->getMessage());
        return 0.0;
    }
}
