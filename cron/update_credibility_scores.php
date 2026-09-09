<?php
/**
 * cron/update_credibility_scores.php
 * NewsXpressLive — Daily Reporter Credibility Score Updater
 *
 * Recalculates credibility scores for all active reporters and rebuilds
 * leaderboard ranks.  Run daily at midnight:
 *
 *   0 0 * * * php /path/to/newsxpresslive/cron/update_credibility_scores.php >> /path/to/logs/credibility.log 2>&1
 *
 * CLI ONLY — cannot be called via HTTP.
 *
 * Options:
 *   --dry-run          Print calculated scores without saving to DB
 *   --user=<id>        Recalculate only one reporter
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$opts   = getopt('', ['dry-run', 'user:']);
$dryRun = isset($opts['dry-run']);
$single = isset($opts['user']) ? (int)$opts['user'] : null;

$root = dirname(__DIR__);
require_once $root . '/web/includes/config.php';
require_once $root . '/helpers/credibility_score.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " Starting credibility score update" . ($dryRun ? ' (DRY RUN)' : '') . "\n";

if ($single !== null) {
    echo "Single reporter mode: user_id={$single}\n";
    $score = CredibilityScoreService::recalculateOne($pdo, $single);
    echo "Score for user {$single}: {$score}\n";
} else {
    $result = CredibilityScoreService::recalculateAll($pdo);
    echo "Processed: {$result['processed']}, Errors: {$result['errors']}\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s\n";
