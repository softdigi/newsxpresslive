<?php
/**
 * web/api/live/heartbeat.php
 *
 * POST /api/live/heartbeat.php
 *      Called every ~30 s by a watching client to signal presence.
 *      Also returns the current viewer count and last 5 chat messages.
 *
 * Body (JSON):
 *   stream_id   int
 *   session_id  string  (client-generated UUID)
 *   user_id     int|null
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$body = (array)(json_decode(file_get_contents('php://input'), true) ?? []);

$streamId  = isset($body['stream_id'])  ? (int)$body['stream_id']  : 0;
$sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : '';
$userId    = isset($body['user_id'])    ? (int)$body['user_id']    : null;

if ($streamId <= 0 || strlen($sessionId) < 4 || strlen($sessionId) > 64) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_params']);
    exit;
}

// Sanitise session_id to alphanumeric + hyphens
if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_session_id']);
    exit;
}

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''));

try {
    /* ── Upsert viewer heartbeat ──────────────────────────── */
    $pdo->prepare(
        'INSERT INTO live_viewers (stream_id, session_id, user_id, ip_hash, last_ping)
         VALUES (:sid, :sess, :uid, :ip, NOW())
         ON DUPLICATE KEY UPDATE
           last_ping = NOW(),
           user_id   = COALESCE(:uid2, user_id)'
    )->execute([
        ':sid'  => $streamId,
        ':sess' => $sessionId,
        ':uid'  => $userId,
        ':ip'   => $ipHash,
        ':uid2' => $userId,
    ]);

    /* ── Prune stale viewers (last ping > 90 s ago) ─────────── */
    $pdo->prepare(
        'DELETE FROM live_viewers WHERE last_ping < DATE_SUB(NOW(), INTERVAL 90 SECOND)'
    )->execute();

    /* ── Count active viewers for this stream ──────────────── */
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM live_viewers WHERE stream_id = :sid'
    );
    $stmt->execute([':sid' => $streamId]);
    $viewerCount = (int)$stmt->fetchColumn();

    /* ── Update viewer_count + peak_viewers ────────────────── */
    $pdo->prepare(
        'UPDATE live_streams
         SET viewer_count = :vc,
             peak_viewers = GREATEST(peak_viewers, :vc)
         WHERE id = :sid'
    )->execute([':vc' => $viewerCount, ':sid' => $streamId]);

    /* ── Fetch stream status ────────────────────────────────── */
    $stmtStatus = $pdo->prepare(
        'SELECT status, reaction_count FROM live_streams WHERE id = :sid'
    );
    $stmtStatus->execute([':sid' => $streamId]);
    $streamRow = $stmtStatus->fetch(PDO::FETCH_ASSOC);

    /* ── Fetch last 20 chat messages ────────────────────────── */
    $stmtChat = $pdo->prepare(
        'SELECT id, author, message, is_pinned, created_at
         FROM live_chat
         WHERE stream_id = :sid
         ORDER BY id DESC
         LIMIT 20'
    );
    $stmtChat->execute([':sid' => $streamId]);
    $chatRows = array_reverse($stmtChat->fetchAll(PDO::FETCH_ASSOC));

    /* ── Fetch recent reactions (last 5 s) ──────────────────── */
    $stmtReact = $pdo->prepare(
        'SELECT emoji, COUNT(*) AS cnt
         FROM live_reactions
         WHERE stream_id = :sid AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
         GROUP BY emoji
         ORDER BY cnt DESC
         LIMIT 10'
    );
    $stmtReact->execute([':sid' => $streamId]);
    $reactions = $stmtReact->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

$chat = array_map(static function (array $m): array {
    return [
        'id'        => (int)$m['id'],
        'author'    => $m['author'],
        'message'   => $m['message'],
        'is_pinned' => (bool)$m['is_pinned'],
        'ts'        => $m['created_at'],
    ];
}, $chatRows);

echo json_encode([
    'ok'             => true,
    'viewer_count'   => $viewerCount,
    'stream_status'  => $streamRow['status']         ?? 'ended',
    'reaction_count' => (int)($streamRow['reaction_count'] ?? 0),
    'recent_reactions'=> $reactions,
    'chat'           => $chat,
]);
