<?php
/**
 * web/api/live/chat.php
 *
 * POST /api/live/chat.php
 *      Submit a chat message during a live stream.
 *
 * Body (JSON):
 *   stream_id   int
 *   session_id  string
 *   author      string  (display name, max 100 chars)
 *   message     string  (max 300 chars)
 *   user_id     int|null
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$body = (array)(json_decode(file_get_contents('php://input'), true) ?? []);

$streamId  = isset($body['stream_id'])  ? (int)$body['stream_id']  : 0;
$sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : '';
$author    = mb_substr(strip_tags(trim($body['author']  ?? 'Viewer')), 0, 100);
$message   = mb_substr(strip_tags(trim($body['message'] ?? '')), 0, 300);
$userId    = isset($body['user_id']) ? (int)$body['user_id'] : null;

if ($streamId <= 0 || strlen($sessionId) < 4 || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_params']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_session_id']);
    exit;
}

// Rate-limit: max 2 messages per session per 5 seconds
try {
    $stmtCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM live_chat
         WHERE stream_id = :sid AND session_id = :sess
           AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)'
    );
    $stmtCheck->execute([':sid' => $streamId, ':sess' => $sessionId]);
    if ((int)$stmtCheck->fetchColumn() >= 2) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited', 'message' => 'Too many messages. Please wait.']);
        exit;
    }

    $stmtStatus = $pdo->prepare('SELECT status FROM live_streams WHERE id = :sid');
    $stmtStatus->execute([':sid' => $streamId]);
    $status = $stmtStatus->fetchColumn();

    if ($status !== 'live') {
        http_response_code(409);
        echo json_encode(['error' => 'stream_not_live']);
        exit;
    }

    $pdo->prepare(
        'INSERT INTO live_chat (stream_id, session_id, user_id, author, message)
         VALUES (:sid, :sess, :uid, :author, :msg)'
    )->execute([
        ':sid'    => $streamId,
        ':sess'   => $sessionId,
        ':uid'    => $userId,
        ':author' => $author ?: 'Viewer',
        ':msg'    => $message,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

echo json_encode(['ok' => true]);
