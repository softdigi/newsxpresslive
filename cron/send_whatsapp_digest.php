<?php
/**
 * cron/send_whatsapp_digest.php
 * Daily WhatsApp digest — run at 8:00 AM
 * Crontab: 0 8 * * * php /path/to/cron/send_whatsapp_digest.php
 *
 * Sends top 3 city news to each subscriber based on their city preference.
 */
declare(strict_types=1);
require_once __DIR__ . '/../web/includes/config.php';

$phone_id = getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
$token    = getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

if (!$phone_id || !$token) {
    echo "[" . date('Y-m-d H:i:s') . "] WhatsApp not configured. Exiting.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting WhatsApp digest...\n";

$stmt = $pdo->query("SELECT phone, name, city FROM whatsapp_subscribers WHERE is_active=1 AND city IS NOT NULL ORDER BY city LIMIT 10000");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$city_news_cache = [];
$sent   = 0;
$failed = 0;

foreach ($subscribers as $sub) {
    $city = $sub['city'];

    if (!isset($city_news_cache[$city])) {
        $stmt2 = $pdo->prepare("SELECT title, short_url FROM news WHERE LOWER(city)=LOWER(?) AND status='approved' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY view_count DESC LIMIT 3");
        $stmt2->execute([$city]);
        $city_news_cache[$city] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    $news = $city_news_cache[$city];
    if (empty($news)) continue;

    $name_greeting = $sub['name'] ? "Namaste {$sub['name']}! 🙏\n\n" : '';
    $msg  = "🌅 *Good Morning from NewsXpressLive!*\n\n";
    $msg .= $name_greeting;
    $msg .= "📰 *Top News from " . ucfirst(strtolower($city)) . " today:*\n\n";
    foreach ($news as $i => $n) {
        $msg .= ($i + 1) . ". " . $n['title'] . "\n";
        if (!empty($n['short_url'])) $msg .= "   " . $n['short_url'] . "\n";
        $msg .= "\n";
    }
    $msg .= "--\nReply STOP to unsubscribe | " . SITE_URL;

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $sub['phone'],
        'type'              => 'text',
        'text'              => ['body' => $msg],
    ];

    $ch = curl_init("https://graph.facebook.com/v19.0/{$phone_id}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $sent++;
    } else {
        $failed++;
        echo "[WARN] Failed to send to {$sub['phone']}: $res\n";
    }

    usleep(100000); // 100ms between sends to respect rate limits
}

echo "[" . date('Y-m-d H:i:s') . "] Digest complete. Sent: $sent, Failed: $failed\n";
