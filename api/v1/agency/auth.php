<?php
// ============================================================
// api/v1/agency/auth.php
// POST /api/v1/agency/auth   — Login with email + password
// POST /api/v1/agency/auth?action=rotate — Rotate API keys
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';
require_once __DIR__ . '/../../../auth/rate_limit.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action = trim($_GET['action'] ?? 'login');

// ── Key rotation (requires existing valid credentials) ────────────────────────
if ($action === 'rotate') {
    $agency = requireAgency($pdo);

    $newKey    = _generateUUID();
    $newSecret = bin2hex(random_bytes(24));
    $newHash   = password_hash($newSecret, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'UPDATE agencies SET api_key = ?, api_secret = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$newKey, $newHash, $agency['id']]);

    echo json_encode([
        'success'    => true,
        'message'    => 'API credentials rotated successfully',
        'api_key'    => $newKey,
        'api_secret' => $newSecret,   // shown once; store securely
    ]);
    exit;
}

// ── Login ─────────────────────────────────────────────────────────────────────
$email    = trim($input['email']    ?? '');
$password = trim($input['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'email and password required']);
    exit;
}

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';
$ip = explode(',', $ip)[0];

// Rate limit login attempts: 10 per 15 minutes per IP
rateLimit($pdo, 'agency_login', $ip, 10, 900);

$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, logo_url, status,
            api_key, api_secret,
            revenue_share_percent, wallet_balance, total_earned
     FROM   agencies
     WHERE  email = ?
     LIMIT  1'
);
$stmt->execute([$email]);
$agency = $stmt->fetch();

if (!$agency) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    exit;
}

// Verify password
$valid = false;
if (str_starts_with($agency['api_secret'], '$2y$') || str_starts_with($agency['api_secret'], '$argon')) {
    $valid = password_verify($password, $agency['api_secret']);
} else {
    $valid = hash_equals($agency['api_secret'], hash('sha256', $password));
}

if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    exit;
}

if ($agency['status'] === 'pending') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account pending approval']);
    exit;
}
if ($agency['status'] === 'suspended') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account suspended']);
    exit;
}

resetRateLimit($pdo, 'agency_login', $ip);

echo json_encode([
    'success'    => true,
    'api_key'    => $agency['api_key'],
    'agency'     => [
        'id'                   => (int)$agency['id'],
        'name'                 => $agency['name'],
        'email'                => $agency['email'],
        'phone'                => $agency['phone'],
        'logo_url'             => $agency['logo_url'],
        'status'               => $agency['status'],
        'revenue_share_percent'=> (float)$agency['revenue_share_percent'],
        'wallet_balance'       => (float)$agency['wallet_balance'],
        'total_earned'         => (float)$agency['total_earned'],
    ],
    'instructions' => 'Include X-Agency-Key and X-Agency-Secret headers in all subsequent requests',
]);

// ── Utility ───────────────────────────────────────────────────────────────────

function _generateUUID(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
