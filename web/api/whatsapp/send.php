<?php
/**
 * web/api/whatsapp/send.php
 * Admin broadcast WhatsApp messages
 * POST — requires admin auth
 *
 * Body: {
 *   "broadcast": "all" | "city" | "category",
 *   "city": "Lucknow",
 *   "category": "breaking",
 *   "message": "Custom message text"
 * }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Admin auth
$admin_token = getenv('ADMIN_API_TOKEN') ?: '';
$bearer = '';
$ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($ah, 'Bearer ')) $bearer = substr($ah, 7);
session_start();
$is_admin = !empty($_SESSION['admin_logged_in']) || ($admin_token && hash_equals($admin_token, $bearer));

if (!$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$broadcast = $input['broadcast'] ?? 'all';
$city      = trim($input['city'] ?? '');
$category  = trim($input['category'] ?? '');
$message   = trim($input['message'] ?? '');

if (empty($message)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

$full_message = $message . "\n\n--\nReply STOP to unsubscribe | " . SITE_URL;

// Fetch subscribers
if ($broadcast === 'city' && $city) {
    $stmt = $pdo->prepare('SELECT phone FROM whatsapp_subscribers WHERE is_active=1 AND LOWER(city)=LOWER(?) LIMIT 1000');
    $stmt->execute([$city]);
} elseif ($broadcast === 'category' && $category) {
    $stmt = $pdo->prepare("SELECT phone FROM whatsapp_subscribers WHERE is_active=1 AND JSON_CONTAINS(subscribed_categories, JSON_QUOTE(?)) LIMIT 1000");
    $stmt->execute([$category]);
} else {
    $stmt = $pdo->query('SELECT phone FROM whatsapp_subscribers WHERE is_active=1 LIMIT 1000');
}
$subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sent   = 0;
$failed = 0;
$phone_id = getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
$token    = getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

if (!$phone_id || !$token) {
    echo json_encode(['success' => false, 'error' => 'WhatsApp not configured', 'recipients' => count($subscribers)]);
    exit;
}

foreach ($subscribers as $phone) {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'text',
        'text'              => ['body' => $full_message],
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
    if ($code === 200) $sent++;
    else $failed++;
    usleep(50000); // 50ms rate limit between sends
}

echo json_encode([
    'success' => true,
    'sent'    => $sent,
    'failed'  => $failed,
    'total'   => count($subscribers),
]);
