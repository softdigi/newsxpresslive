<?php
/**
 * web/api/whatsapp/webhook.php
 * Meta WhatsApp Business API webhook
 *
 * GET  — Webhook verification (Meta hub.challenge)
 * POST — Handle incoming messages
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../web/includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Meta webhook verification ──────────────────────────────────
if ($method === 'GET') {
    $verify_token = getenv('WHATSAPP_VERIFY_TOKEN') ?: 'newsxpresslive_verify';
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode === 'subscribe' && hash_equals($verify_token, $token)) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Verification failed';
    }
    exit;
}

// ── POST: Incoming message ───────────────────────────────────────────
if ($method === 'POST') {
    $payload    = file_get_contents('php://input');
    $sig        = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $app_secret = getenv('WHATSAPP_APP_SECRET') ?: '';

    if ($app_secret && $sig) {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $app_secret);
        if (!hash_equals($expected, $sig)) {
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }
    }

    $data = json_decode($payload, true);

    foreach ($data['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];
            foreach ($value['messages'] ?? [] as $message) {
                if ($message['type'] !== 'text') continue;
                $phone   = $message['from'] ?? '';
                $text    = strtoupper(trim($message['text']['body'] ?? ''));
                $contact = $value['contacts'][0] ?? [];
                $name    = $contact['profile']['name'] ?? null;

                processWhatsAppMessage($pdo, $phone, $text, $name);
            }
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

http_response_code(405);
echo 'Method not allowed';

// ── Message processor ─────────────────────────────────────────────────
function processWhatsAppMessage(PDO $pdo, string $phone, string $text, ?string $name): void
{
    // Register subscriber if new
    $pdo->prepare('INSERT IGNORE INTO whatsapp_subscribers (phone, name) VALUES (?,?)')->execute([$phone, $name]);

    if ($text === 'STOP') {
        $pdo->prepare('UPDATE whatsapp_subscribers SET is_active=0 WHERE phone=?')->execute([$phone]);
        sendWhatsAppMessage($phone, "✅ You have been unsubscribed from NewsXpressLive.\n\nReply START to resubscribe.");
        return;
    }

    if ($text === 'START') {
        $pdo->prepare('UPDATE whatsapp_subscribers SET is_active=1 WHERE phone=?')->execute([$phone]);
        sendWhatsAppMessage($phone, "🎉 Welcome back to NewsXpressLive!\n\nCommands:\n📰 SUBSCRIBE [CITY] — City news\n🔴 BREAKING — Breaking news\n🌾 MANDI — Mandi rates\n💼 JOBS [CITY] — Job listings\n❌ STOP — Unsubscribe");
        return;
    }

    if ($text === 'BREAKING') {
        $stmt = $pdo->query("SELECT title, short_url FROM news WHERE is_breaking=1 AND status='approved' ORDER BY created_at DESC LIMIT 3");
        $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($news)) {
            sendWhatsAppMessage($phone, "No breaking news at the moment. Stay tuned!");
        } else {
            $msg = "🔴 *Breaking News — NewsXpressLive*\n\n";
            foreach ($news as $i => $n) {
                $msg .= ($i + 1) . ". " . $n['title'] . "\n";
                if (!empty($n['short_url'])) $msg .= "   " . $n['short_url'] . "\n";
                $msg .= "\n";
            }
            $msg .= "--\nReply STOP to unsubscribe";
            sendWhatsAppMessage($phone, $msg);
        }
        return;
    }

    if ($text === 'MANDI') {
        $stmt = $pdo->query("SELECT commodity, price, unit, mandi_name FROM mandi_rates WHERE date=CURDATE() ORDER BY updated_at DESC LIMIT 5");
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rates)) {
            sendWhatsAppMessage($phone, "Mandi rates not yet updated today. Try again later.");
        } else {
            $msg = "🌾 *Today's Mandi Rates — NewsXpressLive*\n\n";
            foreach ($rates as $r) {
                $msg .= "• {$r['commodity']}: ₹{$r['price']}/{$r['unit']} ({$r['mandi_name']})\n";
            }
            $msg .= "\n--\nReply STOP to unsubscribe";
            sendWhatsAppMessage($phone, $msg);
        }
        return;
    }

    if (str_starts_with($text, 'SUBSCRIBE')) {
        $city = trim(substr($text, 9));
        if ($city) {
            $pdo->prepare('UPDATE whatsapp_subscribers SET city=?, is_active=1 WHERE phone=?')->execute([$city, $phone]);
            $stmt = $pdo->prepare("SELECT title, short_url FROM news WHERE LOWER(city)=LOWER(?) AND status='approved' ORDER BY created_at DESC LIMIT 3");
            $stmt->execute([$city]);
            $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $msg  = "✅ Subscribed to *" . ucfirst(strtolower($city)) . "* news!\n\n";
            if ($news) {
                $msg .= "📰 *Latest from " . ucfirst(strtolower($city)) . ":*\n\n";
                foreach ($news as $i => $n) {
                    $msg .= ($i + 1) . ". " . $n['title'] . "\n";
                    if (!empty($n['short_url'])) $msg .= "   " . $n['short_url'] . "\n\n";
                }
            } else {
                $msg .= "No recent news for this city yet.";
            }
            $msg .= "\n--\nReply STOP to unsubscribe";
        } else {
            $pdo->prepare('UPDATE whatsapp_subscribers SET is_active=1 WHERE phone=?')->execute([$phone]);
            $msg = "✅ Subscribed to NewsXpressLive!\n\nSend SUBSCRIBE LUCKNOW for city-specific news.";
        }
        sendWhatsAppMessage($phone, $msg);
        return;
    }

    if (str_starts_with($text, 'JOBS')) {
        $city = trim(substr($text, 4));
        if ($city) {
            $stmt = $pdo->prepare("SELECT title, company, location FROM job_listings WHERE LOWER(location) LIKE LOWER(?) AND is_active=1 ORDER BY created_at DESC LIMIT 3");
            $stmt->execute(['%' . $city . '%']);
        } else {
            $stmt = $pdo->query("SELECT title, company, location FROM job_listings WHERE is_active=1 ORDER BY created_at DESC LIMIT 3");
        }
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($jobs)) {
            sendWhatsAppMessage($phone, "No job listings found" . ($city ? " for $city" : "") . ". Check newsxpresslive.com for more.");
        } else {
            $msg = "💼 *Job Listings — NewsXpressLive*\n\n";
            foreach ($jobs as $i => $j) {
                $msg .= ($i + 1) . ". *{$j['title']}*\n   {$j['company']} | {$j['location']}\n\n";
            }
            $msg .= "\n--\nReply STOP to unsubscribe";
            sendWhatsAppMessage($phone, $msg);
        }
        return;
    }

    // Default help message
    sendWhatsAppMessage($phone, "👋 Welcome to *NewsXpressLive*!\n\n📱 *Commands:*\n🔴 BREAKING — Breaking news\n📰 SUBSCRIBE LUCKNOW — City news\n🌾 MANDI — Mandi rates\n💼 JOBS LUCKNOW — Job listings\n❌ STOP — Unsubscribe\n\n🌐 " . SITE_URL);
}

function sendWhatsAppMessage(string $to, string $body): void
{
    $phone_id = getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
    $token    = getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

    if (!$phone_id || !$token) return;

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $body],
    ];

    $ch = curl_init("https://graph.facebook.com/v19.0/{$phone_id}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
