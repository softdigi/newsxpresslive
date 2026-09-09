<?php
/**
 * web/api/live/create.php
 *
 * POST /api/live/create.php
 *      Reporter creates (schedules) a new live stream.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type:  application/json
 *
 * Body (JSON):
 * {
 *   title         : string   (required, max 300)
 *   description   : string   (optional)
 *   thumbnail_url : string   (optional)
 *   category_id   : int      (optional)
 *   state_id      : int      (optional)
 *   district_id   : int      (optional)
 *   scheduled_at  : string   (optional ISO-8601; null = start immediately)
 *   agency_id     : int      (optional)
 * }
 *
 * Response 201:
 * {
 *   success             : true,
 *   stream_id           : int,
 *   agora_channel_name  : string,
 *   agora_token         : string,
 *   agora_token_expires : string  (ISO-8601 UTC),
 *   agora_app_id        : string,
 *   status              : "scheduled"|"live"
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
if (empty($reporterUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in token']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$title        = mb_substr(strip_tags(trim($input['title'] ?? '')), 0, 300);
$description  = mb_substr(strip_tags(trim($input['description'] ?? '')), 0, 2000);
$thumbnailUrl = mb_substr(trim($input['thumbnail_url'] ?? ''), 0, 500);
$categoryId   = isset($input['category_id'])  ? (int)$input['category_id']  : null;
$stateId      = isset($input['state_id'])     ? (int)$input['state_id']     : null;
$districtId   = isset($input['district_id'])  ? (int)$input['district_id']  : null;
$agencyId     = isset($input['agency_id'])    ? (int)$input['agency_id']    : null;
$scheduledAt  = trim($input['scheduled_at'] ?? '');

if ($title === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'title is required']);
    exit;
}

// Validate thumbnail URL if provided
if ($thumbnailUrl !== '' && !filter_var($thumbnailUrl, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid thumbnail_url']);
    exit;
}

// Validate / parse scheduled_at
$scheduledAtSql = null;
if ($scheduledAt !== '') {
    $ts = strtotime($scheduledAt);
    if ($ts === false || $ts < time() - 60) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'scheduled_at must be a future timestamp']);
        exit;
    }
    $scheduledAtSql = date('Y-m-d H:i:s', $ts);
}

// ── Agora config ──────────────────────────────────────────────────────────────
$agoraAppId   = getenv('AGORA_APP_ID')   ?: '';
$agoraAppCert = getenv('AGORA_APP_CERT') ?: '';

if (empty($agoraAppId) || empty($agoraAppCert)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Live streaming not configured']);
    exit;
}

// ── Generate a unique channel name ────────────────────────────────────────────
// Format: "live_{uid_prefix}_{timestamp}_{random4}"
$channelName = 'live_' . substr($reporterUid, 0, 8) . '_' . time() . '_' . bin2hex(random_bytes(2));

// ── Generate initial Agora publisher token ────────────────────────────────────
$tokenTtl    = 3600 * 6; // 6 hours
$agoraToken  = AgoraTokenBuilder::buildTokenWithUid(
    $agoraAppId,
    $agoraAppCert,
    $channelName,
    0, // uid 0 = any user in channel (reporter assigns their own)
    AgoraTokenBuilder::ROLE_PUBLISHER,
    $tokenTtl
);
$tokenExpires = date('Y-m-d H:i:s', time() + $tokenTtl);

// ── Insert stream record ──────────────────────────────────────────────────────
$status = ($scheduledAtSql === null) ? 'scheduled' : 'scheduled';

try {
    $stmt = $pdo->prepare(
        "INSERT INTO `live_streams`
            (reporter_uid, agency_id, title, description, thumbnail_url,
             category_id, state_id, district_id,
             agora_channel_name, agora_token, agora_token_expires,
             status, scheduled_at, created_at)
         VALUES
            (:uid, :agency, :title, :desc, :thumb,
             :cat, :state, :district,
             :channel, :token, :token_exp,
             :status, :sched, NOW())"
    );
    $stmt->execute([
        ':uid'       => $reporterUid,
        ':agency'    => $agencyId,
        ':title'     => $title,
        ':desc'      => $description ?: null,
        ':thumb'     => $thumbnailUrl ?: null,
        ':cat'       => $categoryId,
        ':state'     => $stateId,
        ':district'  => $districtId,
        ':channel'   => $channelName,
        ':token'     => $agoraToken,
        ':token_exp' => $tokenExpires,
        ':status'    => $status,
        ':sched'     => $scheduledAtSql,
    ]);
    $streamId = (int)$pdo->lastInsertId();
} catch (\PDOException $e) {
    error_log('live/create.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create stream']);
    exit;
}

http_response_code(201);
echo json_encode([
    'success'             => true,
    'stream_id'           => $streamId,
    'agora_channel_name'  => $channelName,
    'agora_token'         => $agoraToken,
    'agora_token_expires' => $tokenExpires,
    'agora_app_id'        => $agoraAppId,
    'status'              => $status,
    'scheduled_at'        => $scheduledAtSql,
]);
