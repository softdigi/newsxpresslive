<?php
/**
 * web/api/live/react.php
 *
 * POST /api/live/react.php
 *      Record an emoji reaction on a live stream.
 *
 * Body (JSON):
 *   stream_id   int
 *   session_id  string
 *   emoji       string  (single emoji, default ❤️)
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
$emoji     = isset($body['emoji'])      ? mb_substr(trim((string)$body['emoji']), 0, 10) : '❤️';

if ($streamId <= 0 || strlen($sessionId) < 4) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_params']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_session_id']);
    exit;
}

// Rate-limit: max 10 reactions per session per second (simple in-DB check)
try {
    $stmtCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM live_reactions
         WHERE stream_id = :sid AND session_id = :sess
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 SECOND)'
    );
    $stmtCheck->execute([':sid' => $streamId, ':sess' => $sessionId]);
    if ((int)$stmtCheck->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }

    $pdo->prepare(
        'INSERT INTO live_reactions (stream_id, session_id, emoji)
         VALUES (:sid, :sess, :emoji)'
    )->execute([':sid' => $streamId, ':sess' => $sessionId, ':emoji' => $emoji]);

    $pdo->prepare(
        'UPDATE live_streams SET reaction_count = reaction_count + 1 WHERE id = :sid'
    )->execute([':sid' => $streamId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

echo json_encode(['ok' => true]);
