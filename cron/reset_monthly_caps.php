<?php
/**
 * cron/reset_monthly_caps.php
 *
 * Mahine ki 1 tarikh ko run karo.
 * Article rewards + monthly caps reset karo.
 *
 * Crontab entry:
 *   0 0 1 * * php /path/to/cron/reset_monthly_caps.php >> /var/log/reset_caps.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/reward_config.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " reset_monthly_caps starting...\n";

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
    error_log('reset_monthly_caps: Redis connect failed — ' . $e->getMessage());
    $redis = null;
}

RewardConfig::init($pdo, $redis);

$currentMonth = date('Y-m');
$prevMonth    = date('Y-m', strtotime('first day of last month'));

echo "Resetting caps for month: {$currentMonth} (previous: {$prevMonth})\n";

// ── user_reward_progress — nayi month start karo ─────────────────────────────
$updateStmt = $pdo->prepare(
    "UPDATE user_reward_progress
     SET article_rewards_this_month = 0,
         total_rewards_this_month   = 0,
         reward_month               = ?,
         updated_at                 = NOW()
     WHERE reward_month != ? OR reward_month IS NULL"
);
$updateStmt->execute([$currentMonth, $currentMonth]);
$affectedRows = $updateStmt->rowCount();
echo "  Reset {$affectedRows} user progress records.\n";

// ── reward_budget_tracking — nayi month ki row ensure karo ───────────────────
// Naya monthly entry create karo (agar nahi hai toh)
$pdo->prepare(
    "INSERT INTO reward_budget_tracking (period_type, period_key, amount_spent)
     VALUES ('monthly', ?, 0.00)
     ON DUPLICATE KEY UPDATE updated_at = updated_at"
)->execute([$currentMonth]);

// Aaj ka daily entry bhi ensure karo
$today = date('Y-m-d');
$pdo->prepare(
    "INSERT INTO reward_budget_tracking (period_type, period_key, amount_spent)
     VALUES ('daily', ?, 0.00)
     ON DUPLICATE KEY UPDATE updated_at = updated_at"
)->execute([$today]);

echo "  Budget tracking rows ensured for {$currentMonth} and {$today}.\n";

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " reset_monthly_caps done. Time={$elapsed}s\n";
