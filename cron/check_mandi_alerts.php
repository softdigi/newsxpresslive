<?php
/**
 * cron/check_mandi_alerts.php
 *
 * Daily cron: check all active mandi price alerts and fire FCM notifications
 * when the price condition is met.
 *
 * Schedule: 0 10 * * *  (10:00 AM daily, after market data is fresh)
 * CLI usage: php cron/check_mandi_alerts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';

define('FCM_SERVER_KEY', getenv('FCM_SERVER_KEY') ?: '');

$today = date('Y-m-d');
echo "[{$today}] Checking mandi alerts…\n";

/* ── load active alerts with today's modal price ─────────── */

$stmt = $pdo->prepare(
    'SELECT
         a.id, a.user_id, a.alert_type, a.target_price,
         a.commodity_id, a.mandi_id,
         c.name_hi AS commodity_hi,
         m.name    AS mandi_name,
         r.modal_price
     FROM mandi_alerts a
     JOIN commodities c  ON c.id = a.commodity_id
     JOIN mandis      m  ON m.id = a.mandi_id
     LEFT JOIN mandi_rates r
         ON r.mandi_id = a.mandi_id
        AND r.commodity_id = a.commodity_id
        AND r.rate_date = :today
     WHERE a.is_active = 1'
);
$stmt->execute([':today' => $today]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$triggered = 0;

foreach ($alerts as $alert) {
    if ($alert['modal_price'] === null) continue;

    $price      = (float)$alert['modal_price'];
    $target     = (float)$alert['target_price'];
    $alertType  = $alert['alert_type'];

    $conditionMet = ($alertType === 'above' && $price >= $target)
                 || ($alertType === 'below' && $price <= $target);

    if (!$conditionMet) continue;

    /* ── build FCM notification ──────────────────────────── */
    $symbol     = $alertType === 'above' ? '↑' : '↓';
    $priceStr   = '₹' . number_format($price, 0);
    $targetStr  = '₹' . number_format($target, 0);
    $body       = "{$alert['commodity_hi']} का भाव {$priceStr} पहुंच गया — {$alert['mandi_name']} में";
    $title      = "Mandi Alert {$symbol} {$alert['commodity_hi']}";

    sendFcmToUser($alert['user_id'], $title, $body, [
        'type'         => 'mandi_alert',
        'commodity_id' => (string)$alert['commodity_id'],
        'mandi_id'     => (string)$alert['mandi_id'],
        'price'        => (string)$price,
    ]);

    /* ── update last_triggered ───────────────────────────── */
    $update = $pdo->prepare(
        'UPDATE mandi_alerts SET last_triggered = NOW() WHERE id = ?'
    );
    $update->execute([$alert['id']]);

    $triggered++;
    echo "  ✓ Alert #{$alert['id']}: {$alert['commodity_hi']} @ {$price} ({$alertType} {$target})\n";
}

echo "Done. {$triggered} alert(s) triggered.\n";

/* ── FCM helper ───────────────────────────────────────────── */

function sendFcmToUser(string $userId, string $title, string $body, array $data = []): void {
    if (FCM_SERVER_KEY === '') return;

    // Fetch FCM token for user from push_subscriptions table
    global $pdo;
    $tokenStmt = $pdo->prepare(
        'SELECT fcm_token FROM push_subscriptions WHERE firebase_uid = ? AND fcm_token IS NOT NULL LIMIT 1'
    );
    $tokenStmt->execute([$userId]);
    $row = $tokenStmt->fetch();
    if (!$row) return;

    $payload = json_encode([
        'to'           => $row['fcm_token'],
        'notification' => ['title' => $title, 'body' => $body],
        'data'         => $data,
    ]);

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: key=' . FCM_SERVER_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
