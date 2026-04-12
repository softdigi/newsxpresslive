<?php
/**
 * web/api/verification/status.php
 *
 * GET  → Return the current verification status for the authenticated user.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *
 * Response:
 *   {
 *     success,
 *     verification_status,   // unverified | pending | approved | rejected
 *     account_type,          // user | reporter | agency
 *     is_blue_tick,
 *     blue_tick_type,        // null | free | paid | agency_assigned
 *     blue_tick_granted_at,
 *     profile_complete,
 *     submission?: {
 *       id, status, account_type, submitted_at,
 *       rejection_reason?
 *     }
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['GET', 'OPTIONS']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Authentication ─────────────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}
$payload = verifyFirebaseToken($idToken);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$uid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Load user blue-tick fields ─────────────────────────────────────────────
$userStmt = $pdo->prepare(
    "SELECT account_type, verification_status, is_blue_tick,
            blue_tick_type, blue_tick_granted_at, profile_complete
     FROM users WHERE firebase_uid = ? LIMIT 1"
);
$userStmt->execute([$uid]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // User not yet in DB — return defaults
    echo json_encode([
        'success'             => true,
        'verification_status' => 'unverified',
        'account_type'        => 'user',
        'is_blue_tick'        => false,
        'blue_tick_type'      => null,
        'blue_tick_granted_at'=> null,
        'profile_complete'    => false,
        'submission'          => null,
    ]);
    exit;
}

// ── Load latest submission ─────────────────────────────────────────────────
$subStmt = $pdo->prepare(
    "SELECT id, status, account_type, submitted_at, rejection_reason
     FROM verification_documents
     WHERE user_id = ?
     ORDER BY submitted_at DESC LIMIT 1"
);
$subStmt->execute([$uid]);
$submission = $subStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($submission) {
    // Mask rejection_reason unless rejected
    if ($submission['status'] !== 'rejected') {
        unset($submission['rejection_reason']);
    }
}

echo json_encode([
    'success'              => true,
    'verification_status'  => $user['verification_status']  ?? 'unverified',
    'account_type'         => $user['account_type']         ?? 'user',
    'is_blue_tick'         => (bool)($user['is_blue_tick']  ?? false),
    'blue_tick_type'       => $user['blue_tick_type']        ?? null,
    'blue_tick_granted_at' => $user['blue_tick_granted_at']  ?? null,
    'profile_complete'     => (bool)($user['profile_complete'] ?? false),
    'submission'           => $submission,
]);
