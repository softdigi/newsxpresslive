<?php
/**
 * cron/fraud_daily_report.php
 *
 * Daily 9am — fraud patterns detect karo aur admin ko email report bhejo.
 *
 * Crontab entry:
 *   0 9 * * * php /path/to/cron/fraud_daily_report.php >> /var/log/fraud_report.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/reward_config.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " fraud_daily_report starting...\n";

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
    $redis = null;
}

RewardConfig::init($pdo, $redis);

$alertEmail  = getenv('REWARD_ALERT_EMAIL') ?: 'admin@newsxpresslive.com';
$currentMonth= date('Y-m');
$today       = date('Y-m-d');

// ── Is mahine flagged + blocked referrals count ──────────────────────────────
$countsStmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt
     FROM referrals
     WHERE YEAR(created_at)  = YEAR(NOW())
       AND MONTH(created_at) = MONTH(NOW())
       AND status IN ('flagged', 'blocked')
     GROUP BY status"
);
$countsStmt->execute();
$countsByStatus = ['flagged' => 0, 'blocked' => 0];
foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $countsByStatus[$row['status']] = (int)$row['cnt'];
}

// ── Aaj ke naye fraud flags ───────────────────────────────────────────────────
$todayFraudStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt FROM referral_fraud_flags
     WHERE DATE(created_at) = ?"
);
$todayFraudStmt->execute([$today]);
$todayFraudCount = (int)($todayFraudStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

// ── Unusual patterns detect karo ─────────────────────────────────────────────

// Pattern 1: Same referrer se zyada flagged referrals
$topFraudStmt = $pdo->prepare(
    "SELECT referrer_uid, COUNT(*) AS cnt, AVG(fraud_score) AS avg_score
     FROM referrals
     WHERE status IN ('flagged', 'blocked')
       AND YEAR(created_at)  = YEAR(NOW())
       AND MONTH(created_at) = MONTH(NOW())
     GROUP BY referrer_uid
     HAVING cnt >= 3
     ORDER BY cnt DESC
     LIMIT 10"
);
$topFraudStmt->execute();
$suspiciousReferrers = $topFraudStmt->fetchAll(PDO::FETCH_ASSOC);

// Pattern 2: Same IP se multiple referrals aaj
$ipAbuseStmt = $pdo->prepare(
    "SELECT referee_ip_hash, COUNT(*) AS cnt
     FROM referrals
     WHERE DATE(created_at) = ?
     GROUP BY referee_ip_hash
     HAVING cnt >= 3
     ORDER BY cnt DESC
     LIMIT 10"
);
$ipAbuseStmt->execute([$today]);
$ipAbusePatterns = $ipAbuseStmt->fetchAll(PDO::FETCH_ASSOC);

// Pattern 3: VPN pattern — vpn_detected flags aaj
$vpnFlagsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt FROM referral_fraud_flags
     WHERE DATE(created_at) = ?
       AND JSON_CONTAINS(fraud_flags, '\"vpn_detected\"')"
);
$vpnFlagsStmt->execute([$today]);
$vpnFlagsToday = (int)($vpnFlagsStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

// Pattern 4: Device reuse aaj
$deviceReuseStmt = $pdo->prepare(
    "SELECT device_fingerprint, COUNT(DISTINCT referee_uid) AS cnt
     FROM referrals
     WHERE DATE(created_at) = ?
       AND device_fingerprint IS NOT NULL
       AND device_fingerprint != ''
     GROUP BY device_fingerprint
     HAVING cnt > 1
     ORDER BY cnt DESC
     LIMIT 5"
);
$deviceReuseStmt->execute([$today]);
$deviceReusePatterns = $deviceReuseStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Pending review count ──────────────────────────────────────────────────────
$pendingReviewStmt = $pdo->query(
    "SELECT COUNT(*) AS cnt FROM referral_fraud_flags WHERE status = 'pending_review'"
);
$pendingReview = (int)($pendingReviewStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

// ── Report banao ──────────────────────────────────────────────────────────────
$reportLines = [
    "NewsXpressLive — Daily Fraud Report",
    "Date: {$today}",
    str_repeat('=', 50),
    "",
    "THIS MONTH SUMMARY ({$currentMonth}):",
    "  Flagged referrals:  {$countsByStatus['flagged']}",
    "  Blocked referrals:  {$countsByStatus['blocked']}",
    "",
    "TODAY'S ACTIVITY:",
    "  New fraud flags:    {$todayFraudCount}",
    "  VPN detections:     {$vpnFlagsToday}",
    "",
    "PENDING REVIEW:       {$pendingReview}",
    "",
];

if (!empty($suspiciousReferrers)) {
    $reportLines[] = "⚠ SUSPICIOUS REFERRERS (3+ fraud referrals this month):";
    foreach ($suspiciousReferrers as $row) {
        $reportLines[] = sprintf(
            "  User: %s | Count: %d | Avg Score: %.1f",
            $row['referrer_uid'],
            $row['cnt'],
            $row['avg_score']
        );
    }
    $reportLines[] = '';
}

if (!empty($ipAbusePatterns)) {
    $reportLines[] = "⚠ IP ABUSE PATTERNS TODAY (3+ referrals from same IP):";
    foreach ($ipAbusePatterns as $row) {
        $reportLines[] = "  IP Hash: " . substr($row['referee_ip_hash'], 0, 16) . "... | Count: {$row['cnt']}";
    }
    $reportLines[] = '';
}

if (!empty($deviceReusePatterns)) {
    $reportLines[] = "⚠ DEVICE REUSE TODAY (same device, multiple referees):";
    foreach ($deviceReusePatterns as $row) {
        $fp = strlen($row['device_fingerprint']) > 16
              ? substr($row['device_fingerprint'], 0, 16) . '...'
              : $row['device_fingerprint'];
        $reportLines[] = "  Device: {$fp} | Referees: {$row['cnt']}";
    }
    $reportLines[] = '';
}

$reportLines[] = str_repeat('-', 50);
$reportLines[] = "Generated: " . date('Y-m-d H:i:s');
$reportLines[] = "Admin Panel: " . (getenv('SITE_URL') ?: 'https://newsxpresslive.com') . "/admin_panel/";

$reportBody = implode("\n", $reportLines);

echo $reportBody . "\n";

// ── Email bhejo ───────────────────────────────────────────────────────────────
$emailSent = false;
try {
    $apiKey    = getenv('SENDGRID_API_KEY') ?: '';
    $fromEmail = getenv('MAIL_FROM') ?: 'noreply@newsxpresslive.com';
    $subject   = "[NewsXpressLive] Daily Fraud Report — {$today}";

    if ($apiKey) {
        $payload = [
            'personalizations' => [['to' => [['email' => $alertEmail]]]],
            'from'             => ['email' => $fromEmail, 'name' => 'NewsXpressLive'],
            'subject'          => $subject,
            'content'          => [['type' => 'text/plain', 'value' => $reportBody]],
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $emailSent = ($code >= 200 && $code < 300);
    }
} catch (Throwable $e) {
    error_log('fraud_daily_report: Email send failed — ' . $e->getMessage());
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " fraud_daily_report done."
    . " Email=" . ($emailSent ? 'sent' : 'failed/skipped')
    . " Time={$elapsed}s\n";
