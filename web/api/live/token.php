<?php
/**
 * web/api/live/token.php
 *
 * POST /api/live/token.php
 *      Generate (or refresh) an Agora RTC token for a viewer or the
 *      stream's reporter.  Called when:
 *        - A viewer joins the stream (role=subscriber)
 *        - The reporter's token is about to expire (role=publisher)
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>   (optional for viewers,
 *                                                 required for publishers)
 *   Content-Type:  application/json
 *
 * Body (JSON):
 * {
 *   stream_id : int     (required)
 *   role      : string  "publisher" | "subscriber"  (default: "subscriber")
 *   uid       : int     (optional Agora UID, default 0)
 * }
 *
 * Response 200:
 * {
 *   success             : true,
 *   agora_token         : string,
 *   agora_token_expires : string  (ISO-8601 UTC),
 *   agora_app_id        : string,
 *   agora_channel_name  : string
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../helpers/cors.php';
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

// ── Agora config ──────────────────────────────────────────────────────────────
$agoraAppId   = getenv('AGORA_APP_ID')   ?: '';
$agoraAppCert = getenv('AGORA_APP_CERT') ?: '';

if (empty($agoraAppId) || empty($agoraAppCert)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Live streaming not configured']);
    exit;
}

// ── Optional auth (required for publisher role) ───────────────────────────────
$requestUid = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    require_once __DIR__ . '/../../../auth/firebase.php';
    $pld = verifyFirebaseToken(trim($m[1]));
    if ($pld) {
        $requestUid = $pld['sub'] ?? $pld['uid'] ?? null;
    }
}

// ── Input ─────────────────────────────────────────────────────────────────────
$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$streamId = isset($input['stream_id']) ? (int)$input['stream_id'] : 0;
$roleStr  = strtolower(trim($input['role'] ?? 'subscriber'));
$uid      = isset($input['uid']) ? (int)$input['uid'] : 0;

if ($streamId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'stream_id is required']);
    exit;
}

$role = ($roleStr === 'publisher')
    ? AgoraTokenBuilder::ROLE_PUBLISHER
    : AgoraTokenBuilder::ROLE_SUBSCRIBER;

// Publisher role requires authenticated reporter who owns the stream
if ($role === AgoraTokenBuilder::ROLE_PUBLISHER) {
    if ($requestUid === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authorization required for publisher token']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT reporter_uid, status FROM live_streams WHERE id = :id'
        );
        $stmt->execute([':id' => $streamId]);
        $stream = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }

    if (!$stream) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Stream not found']);
        exit;
    }

    if ($stream['reporter_uid'] !== $requestUid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not your stream']);
        exit;
    }
}

// ── Fetch channel name ────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'SELECT agora_channel_name, status FROM live_streams WHERE id = :id'
    );
    $stmt->execute([':id' => $streamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if (!$row || empty($row['agora_channel_name'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Stream not found or not Agora-enabled']);
    exit;
}

if ($row['status'] === 'ended' || $row['status'] === 'failed') {
    http_response_code(410);
    echo json_encode(['success' => false, 'message' => 'Stream has ended']);
    exit;
}

// ── Generate token ────────────────────────────────────────────────────────────
$tokenTtl    = ($role === AgoraTokenBuilder::ROLE_PUBLISHER) ? 3600 * 6 : 3600 * 2;
$agoraToken  = AgoraTokenBuilder::buildTokenWithUid(
    $agoraAppId,
    $agoraAppCert,
    $row['agora_channel_name'],
    $uid,
    $role,
    $tokenTtl
);
$tokenExpires = date('Y-m-d\TH:i:s\Z', time() + $tokenTtl);

// For publisher tokens: store the refreshed token in DB
if ($role === AgoraTokenBuilder::ROLE_PUBLISHER) {
    try {
        $pdo->prepare(
            "UPDATE live_streams
             SET agora_token = :token, agora_token_expires = :exp
             WHERE id = :id"
        )->execute([
            ':token' => $agoraToken,
            ':exp'   => date('Y-m-d H:i:s', time() + $tokenTtl),
            ':id'    => $streamId,
        ]);
    } catch (\PDOException $e) {
        // Non-fatal — token is still returned
    }
}

echo json_encode([
    'success'             => true,
    'agora_token'         => $agoraToken,
    'agora_token_expires' => $tokenExpires,
    'agora_app_id'        => $agoraAppId,
    'agora_channel_name'  => $row['agora_channel_name'],
]);
