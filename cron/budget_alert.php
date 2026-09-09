<?php
/**
 * cron/budget_alert.php
 *
 * Har ghante run karo — budget thresholds alert karo.
 * 80% → admin email
 * 95% → high-priority alert (WhatsApp / Slack fallback)
 *
 * Crontab entry:
 *   0 * * * * php /path/to/cron/budget_alert.php >> /var/log/budget_alert.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/reward_config.php';
require_once __DIR__ . '/../helpers/email_service.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " budget_alert starting...\n";

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
    error_log('budget_alert: Redis connect failed — ' . $e->getMessage());
    $redis = null;
}

RewardConfig::init($pdo, $redis);

$alertEmail  = getenv('REWARD_ALERT_EMAIL') ?: 'admin@newsxpresslive.com';
$today       = date('Y-m-d');
$currentMonth= date('Y-m');

// ── Config limits ─────────────────────────────────────────────────────────────
$dailyLimit   = (float)RewardConfig::get('daily_reward_budget', 2000.0);
$monthlyLimit = (float)RewardConfig::get('monthly_reward_budget', 20000.0);

// ── Redis se spend fetch karo ─────────────────────────────────────────────────
$dailySpent   = 0.0;
$monthlySpent = 0.0;

if ($redis !== null) {
    try {
        $dailyVal   = $redis->get("reward_budget:daily:{$today}");
        $monthlyVal = $redis->get("reward_budget:monthly:{$currentMonth}");
        $dailySpent   = $dailyVal   !== false ? (float)$dailyVal   : 0.0;
        $monthlySpent = $monthlyVal !== false ? (float)$monthlyVal : 0.0;
    } catch (Throwable) {
        // Redis fail — DB se fallback
    }
}

// Redis fail hone par DB se fallback
if ($dailySpent === 0.0 || $monthlySpent === 0.0) {
    $dbStmt = $pdo->prepare(
        "SELECT period_type, period_key, amount_spent
         FROM reward_budget_tracking
         WHERE (period_type = 'daily' AND period_key = ?)
            OR (period_type = 'monthly' AND period_key = ?)"
    );
    $dbStmt->execute([$today, $currentMonth]);
    foreach ($dbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['period_type'] === 'daily') {
            $dailySpent = (float)$row['amount_spent'];
        } else {
            $monthlySpent = (float)$row['amount_spent'];
        }
    }
}

$dailyPct   = $dailyLimit   > 0 ? round(($dailySpent   / $dailyLimit)   * 100, 1) : 0.0;
$monthlyPct = $monthlyLimit > 0 ? round(($monthlySpent / $monthlyLimit) * 100, 1) : 0.0;

echo "  Daily:   ₹{$dailySpent} / ₹{$dailyLimit} ({$dailyPct}%)\n";
echo "  Monthly: ₹{$monthlySpent} / ₹{$monthlyLimit} ({$monthlyPct}%)\n";

// ── Alert logic ───────────────────────────────────────────────────────────────
$alertsSent = 0;

// Alert key prefix — duplicate alert avoid karo (same hour mein)
$alertHour = date('Y-m-d-H');

$sendAlert = function(
    string $level,
    string $period,
    float  $spent,
    float  $limit,
    float  $pct
) use ($redis, $alertHour, $alertEmail, $pdo, &$alertsSent): void {
    $alertKey = "budget_alert:{$level}:{$period}:{$alertHour}";

    // Pehle check karo — is hour mein already alert gaya kya?
    if ($redis !== null) {
        try {
            if ($redis->exists($alertKey)) {
                return;
            }
        } catch (Throwable) {}
    }

    $subject = "[NewsXpressLive] Budget Alert — {$pct}% {$period} budget used ({$level})";
    $body    = sprintf(
        "Budget Alert!\n\n"
        . "Level:    %s\n"
        . "Period:   %s\n"
        . "Spent:    ₹%.2f\n"
        . "Limit:    ₹%.2f\n"
        . "Used:     %.1f%%\n"
        . "Remaining: ₹%.2f\n\n"
        . "Time: %s\n",
        strtoupper($level),
        $period,
        $spent,
        $limit,
        $pct,
        max(0.0, $limit - $spent),
        date('Y-m-d H:i:s')
    );

    // Email alert
    $emailSent = false;
    try {
        $apiKey    = getenv('SENDGRID_API_KEY') ?: '';
        $fromEmail = getenv('MAIL_FROM') ?: 'noreply@newsxpresslive.com';

        if ($apiKey) {
            $payload = [
                'personalizations' => [['to' => [['email' => $alertEmail]]]],
                'from'             => ['email' => $fromEmail, 'name' => 'NewsXpressLive'],
                'subject'          => $subject,
                'content'          => [['type' => 'text/plain', 'value' => $body]],
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
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $emailSent = ($code >= 200 && $code < 300);
        }
    } catch (Throwable $e) {
        error_log('budget_alert: Email send failed — ' . $e->getMessage());
    }

    // 95%+ pe WhatsApp/Slack fallback
    if ($level === 'critical') {
        $slackWebhook = getenv('SLACK_WEBHOOK_URL') ?: '';
        if ($slackWebhook) {
            try {
                $slackPayload = json_encode([
                    'text' => "🚨 *{$subject}*\n```{$body}```",
                ]);
                $ch = curl_init($slackWebhook);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $slackPayload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 10,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (Throwable) {}
        }
    }

    // Redis mein mark karo (1 hour TTL)
    if ($redis !== null) {
        try {
            $redis->setex($alertKey, 3600, '1');
        } catch (Throwable) {}
    }

    $alertsSent++;
    echo "  → Alert sent ({$level}, {$period}): email=" . ($emailSent ? 'yes' : 'failed') . "\n";
};

// Daily budget alerts
if ($dailyPct >= 95.0) {
    $sendAlert('critical', 'daily', $dailySpent, $dailyLimit, $dailyPct);
} elseif ($dailyPct >= 80.0) {
    $sendAlert('warning', 'daily', $dailySpent, $dailyLimit, $dailyPct);
}

// Monthly budget alerts
if ($monthlyPct >= 95.0) {
    $sendAlert('critical', 'monthly', $monthlySpent, $monthlyLimit, $monthlyPct);
} elseif ($monthlyPct >= 80.0) {
    $sendAlert('warning', 'monthly', $monthlySpent, $monthlyLimit, $monthlyPct);
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " budget_alert done."
    . " Alerts={$alertsSent} Time={$elapsed}s\n";
