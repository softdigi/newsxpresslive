<?php
/**
 * web/api/live/end.php
 *
 * POST /api/live/end.php
 *      Reporter ends the live stream.
 *      Calculates duration, stores viewer/earnings totals,
 *      and marks the stream as "ended".
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type:  application/json
 *
 * Body (JSON):
 * {
 *   stream_id    : int     (required)
 *   recording_url: string  (optional — HLS/MP4 URL if recording enabled)
 * }
 *
 * Response 200:
 * {
 *   success           : true,
 *   stream_id         : int,
 *   duration_seconds  : int,
 *   peak_viewers      : int,
 *   total_viewers     : int,
 *   total_super_chats : int,
 *   reporter_earnings : string  (decimal)
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../auth/firebase.php';

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
$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$streamId     = isset($input['stream_id'])     ? (int)$input['stream_id']            : 0;
$recordingUrl = mb_substr(trim($input['recording_url'] ?? ''), 0, 500);

if ($streamId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'stream_id is required']);
    exit;
}

if ($recordingUrl !== '' && !filter_var($recordingUrl, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid recording_url']);
    exit;
}

try {
    // ── Fetch stream ──────────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, reporter_uid, status, started_at,
                peak_viewers, total_viewers,
                total_super_chats, total_super_chat_amount,
                platform_earnings, reporter_earnings
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

    if ($stream['status'] === 'ended') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Stream already ended']);
        exit;
    }

    // ── Calculate duration ────────────────────────────────────────────────────
    $endedAt         = date('Y-m-d H:i:s');
    $durationSeconds = 0;
    if (!empty($stream['started_at'])) {
        $durationSeconds = max(0, (int)(strtotime($endedAt) - strtotime($stream['started_at'])));
    }

    // ── Aggregate super-chat totals from live_super_chats ─────────────────────
    $scStmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount), 0)        AS total_amount,
                COALESCE(SUM(platform_cut), 0)  AS plat_total,
                COALESCE(SUM(reporter_cut), 0)  AS rep_total
         FROM live_super_chats
         WHERE stream_id = :sid AND status = 'completed'"
    );
    $scStmt->execute([':sid' => $streamId]);
    $scRow = $scStmt->fetch(PDO::FETCH_ASSOC);

    $totalSuperChats        = (int)$scRow['cnt'];
    $totalSuperChatAmount   = (float)$scRow['total_amount'];
    $platformEarnings       = (float)$scRow['plat_total'];
    $reporterEarnings       = (float)$scRow['rep_total'];

    // Count unique authenticated viewers (from live_stream_viewers)
    $viewerStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT user_uid) FROM live_stream_viewers WHERE stream_id = :sid'
    );
    $viewerStmt->execute([':sid' => $streamId]);
    $totalViewers = (int)$viewerStmt->fetchColumn();
    // Fall back to v12-style viewer_count if no authenticated viewers recorded
    if ($totalViewers === 0) {
        $totalViewers = (int)$stream['total_viewers'];
    }

    // ── Update stream record ──────────────────────────────────────────────────
    $pdo->prepare(
        "UPDATE live_streams
         SET status                    = 'ended',
             ended_at                  = :ended,
             duration_seconds          = :dur,
             total_viewers             = :viewers,
             total_super_chats         = :sc_cnt,
             total_super_chat_amount   = :sc_amt,
             platform_earnings         = :plat,
             reporter_earnings         = :rep,
             recording_url             = :rec,
             is_recorded               = :is_rec,
             agora_token               = NULL
         WHERE id = :id"
    )->execute([
        ':ended'   => $endedAt,
        ':dur'     => $durationSeconds,
        ':viewers' => $totalViewers,
        ':sc_cnt'  => $totalSuperChats,
        ':sc_amt'  => number_format($totalSuperChatAmount, 2, '.', ''),
        ':plat'    => number_format($platformEarnings, 2, '.', ''),
        ':rep'     => number_format($reporterEarnings, 2, '.', ''),
        ':rec'     => $recordingUrl ?: null,
        ':is_rec'  => ($recordingUrl !== '') ? 1 : 0,
        ':id'      => $streamId,
    ]);

    // ── Stamp left_at for authenticated viewers still "active" ────────────────
    $pdo->prepare(
        "UPDATE live_stream_viewers
         SET left_at = NOW()
         WHERE stream_id = :sid AND left_at IS NULL"
    )->execute([':sid' => $streamId]);

} catch (\PDOException $e) {
    error_log('live/end.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

echo json_encode([
    'success'           => true,
    'stream_id'         => $streamId,
    'duration_seconds'  => $durationSeconds,
    'peak_viewers'      => (int)$stream['peak_viewers'],
    'total_viewers'     => $totalViewers,
    'total_super_chats' => $totalSuperChats,
    'reporter_earnings' => number_format($reporterEarnings, 2, '.', ''),
    'ended_at'          => $endedAt,
]);
