<?php
/**
 * cron/process_milestones.php
 *
 * Har 15 minute mein run karo.
 * Active users ke liye milestone check karo aur grant karo.
 *
 * Crontab entry:
 *   */15 * * * * php /path/to/cron/process_milestones.php >> /var/log/milestones.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/reward_config.php';
require_once __DIR__ . '/../helpers/milestone_engine.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " process_milestones starting...\n";

// ── Redis init ────────────────────────────────────────────────────────────────
$redis = null;
try {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: '127.0.0.1',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );
    if ($redisPwd = getenv('REDIS_PASSWORD')) {
        $redis->auth($redisPwd);
    }
} catch (Throwable $e) {
    error_log('process_milestones: Redis connect failed — ' . $e->getMessage());
    $redis = null;
}

RewardConfig::init($pdo, $redis);
MilestoneEngine::init($pdo, $redis);

// ── Reward system active check ─────────────────────────────────────────────────
if (!(bool)RewardConfig::get('milestone_rewards_active', true)) {
    echo "Milestone rewards disabled — exiting.\n";
    exit(0);
}

// ── Active users fetch karo (last 30 days active) ────────────────────────────
$stmt = $pdo->query(
    'SELECT DISTINCT urp.user_id
     FROM user_reward_progress urp
     WHERE urp.last_active_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       AND (urp.milestone_7day_done  = 0
            OR urp.milestone_30day_done = 0
            OR urp.milestone_90day_done = 0)'
);
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($users)) {
    echo "No active users to process.\n";
    exit(0);
}

echo "Processing " . count($users) . " users...\n";

$totalGranted = 0;
$totalSkipped = 0;
$errors       = 0;

foreach ($users as $userId) {
    try {
        $result = MilestoneEngine::checkAndGrant((string)$userId);

        if (!empty($result['granted'])) {
            $totalGranted += count($result['granted']);
            echo "  ✓ User {$userId} — granted: " . implode(', ', $result['granted']) . "\n";
        }
        $totalSkipped += count($result['skipped']);
    } catch (Throwable $e) {
        $errors++;
        error_log("process_milestones: Error for user {$userId} — " . $e->getMessage());
        echo "  ✗ User {$userId} — ERROR: " . $e->getMessage() . "\n";
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " process_milestones done."
    . " Granted={$totalGranted} Skipped={$totalSkipped} Errors={$errors}"
    . " Time={$elapsed}s\n";
