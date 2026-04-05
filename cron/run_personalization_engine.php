<?php
/**
 * cron/run_personalization_engine.php
 * Feature 3 — Cron wrapper for the Rule-Based Personalization Engine
 *
 * Processes all active personalization rules for every user who has
 * interests stored, then logs the result.
 *
 * Recommended schedule: once per hour
 *
 *   0 * * * * php /path/to/newsxpresslive/cron/run_personalization_engine.php >> /path/to/logs/personalization.log 2>&1
 *
 * Optional argument to process a single user:
 *   php cron/run_personalization_engine.php --user_id=<firebase_uid>
 *
 * CLI ONLY — will exit immediately if called via HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$start = microtime(true);
echo '[' . date('Y-m-d H:i:s') . '] Personalization engine started' . PHP_EOL;

// Delegate to the main engine script (which supports CLI mode natively)
$enginePath = __DIR__ . '/../web/api/personalization_engine.php';

if (!file_exists($enginePath)) {
    echo '[ERROR] Engine script not found: ' . $enginePath . PHP_EOL;
    exit(1);
}

// Pass through any --user_id argument
$args = implode(' ', array_slice($argv, 1));
passthru("php " . escapeshellarg($enginePath) . " " . $args, $exitCode);

$elapsed = round(microtime(true) - $start, 2);
echo '[' . date('Y-m-d H:i:s') . '] Engine finished in ' . $elapsed . 's' . PHP_EOL;

exit($exitCode ?? 0);
