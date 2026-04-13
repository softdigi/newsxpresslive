<?php
/**
 * cron/lifetime_revenue_share.php
 *
 * Daily cron — har referee ki yesterday ki INR earnings pe
 * referrer ko lifetime share bhejo.
 *
 * Crontab entry:
 *   0 2 * * * php /path/to/cron/lifetime_revenue_share.php >> /var/log/lifetime_share.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/reward_config.php';
require_once __DIR__ . '/../helpers/referral_engine.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " lifetime_revenue_share starting...\n";

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
    error_log('lifetime_revenue_share: Redis connect failed — ' . $e->getMessage());
    $redis = null;
}

RewardConfig::init($pdo, $redis);
ReferralEngine::init($pdo, $redis);

// Referral system active check
if (!(bool)RewardConfig::get('referral_system_active', true)) {
    echo "Referral system disabled — exiting.\n";
    exit(0);
}

$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── reward_paid status referrals fetch karo ───────────────────────────────────
$stmt = $pdo->query(
    "SELECT r.referee_uid
     FROM referrals r
     WHERE r.status = 'reward_paid'"
);
$referees = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($referees)) {
    echo "No reward_paid referrals found.\n";
    exit(0);
}

echo "Processing " . count($referees) . " referees for lifetime share...\n";

$processed = 0;
$skipped   = 0;
$errors    = 0;

foreach ($referees as $refereeUid) {
    try {
        // Referee ki yesterday ki INR earnings calculate karo
        $earningStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(rt.amount), 0) AS total
             FROM reward_transactions rt
             WHERE rt.user_id      = ?
               AND rt.wallet_type  = 'inr'
               AND rt.status       = 'completed'
               AND DATE(rt.created_at) = ?"
        );
        $earningStmt->execute([$refereeUid, $yesterday]);
        $earnings = (float)($earningStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        if ($earnings <= 0.0) {
            $skipped++;
            continue;
        }

        ReferralEngine::processLifetimeShare((string)$refereeUid, $earnings);
        $processed++;

        echo "  ✓ Referee {$refereeUid} — earnings ₹{$earnings} processed\n";
    } catch (Throwable $e) {
        $errors++;
        error_log("lifetime_revenue_share: Error for referee {$refereeUid} — " . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " lifetime_revenue_share done."
    . " Processed={$processed} Skipped={$skipped} Errors={$errors}"
    . " Time={$elapsed}s\n";
