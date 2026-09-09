<?php
/**
 * web/api/horoscope/save_sign.php
 * Authenticated API — save user's zodiac sign preference.
 *
 * POST — Body (JSON): { "zodiac_sign": "aries", "birth_date": "1995-03-25" }
 * Header: Authorization: Bearer <firebase_uid>
 *
 * Response: { "success": true, "message": "Zodiac sign saved" }
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

// ── Auth ──────────────────────────────────────────────────────────────────────
$uid = trim($_SERVER['HTTP_X_USER_UID'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$uid = preg_replace('/^Bearer\s+/i', '', $uid);
if ($uid === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$valid_signs = [
    'aries','taurus','gemini','cancer','leo','virgo',
    'libra','scorpio','sagittarius','capricorn','aquarius','pisces',
];

$zodiac_sign = strtolower(trim($body['zodiac_sign'] ?? ''));
$birth_date  = trim($body['birth_date'] ?? '');

if (!in_array($zodiac_sign, $valid_signs, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid zodiac sign']);
    exit;
}

// Validate birth_date if provided
$bd_value = null;
if ($birth_date !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $birth_date);
    if (!$dt || $dt->format('Y-m-d') !== $birth_date) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid birth_date format. Use YYYY-MM-DD']);
        exit;
    }
    $bd_value = $birth_date;
}

// Sanitize uid — only allow firebase-style UIDs
if (!preg_match('/^[a-zA-Z0-9_\-]{10,128}$/', $uid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user identifier']);
    exit;
}

// ── Upsert ────────────────────────────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO user_zodiac (user_uid, zodiac_sign, birth_date)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE zodiac_sign = VALUES(zodiac_sign),
       birth_date = COALESCE(VALUES(birth_date), birth_date)'
)->execute([$uid, $zodiac_sign, $bd_value]);

echo json_encode(['success' => true, 'message' => 'Zodiac sign saved']);
