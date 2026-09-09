<?php
/**
 * web/api/live/start.php
 *
 * POST /api/live/start.php
 *      Reporter goes live: transitions stream from "scheduled" to "live".
 *      Returns a fresh Agora publisher token.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type:  application/json
 *
 * Body (JSON):
 * {
 *   stream_id : int  (required)
 * }
 *
 * Response 200:
 * {
 *   success             : true,
 *   stream_id           : int,
 *   agora_channel_name  : string,
 *   agora_token         : string,
 *   agora_token_expires : string  (ISO-8601 UTC),
 *   agora_app_id        : string,
 *   started_at          : string
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../helpers/agora_token.php';

corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
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

$reporterUid = $payload['sub'] ?? $payload['uid'] ?? '';

// ── Input ─────────────────────────────────────────────────────────────────────
$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$streamId = isset($input['stream_id']) ? (int)$input['stream_id'] : 0;

if ($streamId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'stream_id is required']);
    exit;
}

// ── Agora config ──────────────────────────────────────────────────────────────
$agoraAppId   = getenv('AGORA_APP_ID')   ?: '';
$agoraAppCert = getenv('AGORA_APP_CERT') ?: '';

if (empty($agoraAppId) || empty($agoraAppCert)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Live streaming not configured']);
    exit;
}

try {
    // ── Fetch and verify ownership ────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, reporter_uid, agora_channel_name, status
         FROM live_streams WHERE id = :id'
    );
    $stmt->execute([':id' => $streamId]);
    $stream = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stream) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Stream not found']);
        exit;
    }

    if ($stream['reporter_uid'] !== $reporterUid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not your stream']);
        exit;
    }

    if ($stream['status'] === 'live') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Stream already live']);
        exit;
    }

    if (!in_array($stream['status'], ['scheduled', 'failed'], true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot start a stream with status: ' . $stream['status'],
        ]);
        exit;
    }

    // ── Generate fresh publisher token ────────────────────────────────────────
    $tokenTtl     = 3600 * 6; // 6 hours
    $agoraToken   = AgoraTokenBuilder::buildTokenWithUid(
        $agoraAppId,
        $agoraAppCert,
        $stream['agora_channel_name'],
        0,
        AgoraTokenBuilder::ROLE_PUBLISHER,
        $tokenTtl
    );
    $tokenExpires = date('Y-m-d H:i:s', time() + $tokenTtl);
    $startedAt    = date('Y-m-d H:i:s');

    // ── Update stream ─────────────────────────────────────────────────────────
    $pdo->prepare(
        "UPDATE live_streams
         SET status              = 'live',
             started_at          = :started,
             agora_token         = :token,
             agora_token_expires = :token_exp
         WHERE id = :id"
    )->execute([
        ':started'   => $startedAt,
        ':token'     => $agoraToken,
        ':token_exp' => $tokenExpires,
        ':id'        => $streamId,
    ]);

} catch (\PDOException $e) {
    error_log('live/start.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

echo json_encode([
    'success'             => true,
    'stream_id'           => $streamId,
    'agora_channel_name'  => $stream['agora_channel_name'],
    'agora_token'         => $agoraToken,
    'agora_token_expires' => $tokenExpires,
    'agora_app_id'        => $agoraAppId,
    'started_at'          => $startedAt,
]);
