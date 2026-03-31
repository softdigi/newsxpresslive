<?php
/**
 * web/api/reel_comment.php
 *
 * GET  ?reel_id=N           – Returns approved comments for a reel.
 * POST { reel_id, author_name, content }
 *      Submits a new comment (queued as 'pending', auto-approved for now).
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';

/* ── GET: list comments ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reelId = isset($_GET['reel_id']) ? (int)$_GET['reel_id'] : 0;
    if ($reelId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'reel_id required']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, author_name, content, created_at
             FROM reel_comments
             WHERE reel_id = :rid AND status = :st
             ORDER BY created_at ASC
             LIMIT 100'
        );
        $stmt->execute([':rid' => $reelId, ':st' => 'approved']);
        echo json_encode($stmt->fetchAll());
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

/* ── POST: submit comment ──────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$reelId = isset($input['reel_id'])     ? (int)$input['reel_id']          : 0;
$name   = isset($input['author_name']) ? trim($input['author_name'])      : '';
$body   = isset($input['content'])     ? trim($input['content'])          : '';

if ($reelId <= 0 || $name === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reel_id, author_name and content are required']);
    exit;
}

if (mb_strlen($name) > 80) {
    echo json_encode(['success' => false, 'message' => 'Name too long (max 80 characters)']);
    exit;
}
if (mb_strlen($body) > 1000) {
    echo json_encode(['success' => false, 'message' => 'Comment too long (max 1000 characters)']);
    exit;
}

$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash = hash('sha256', $ip);

// Rate limit: max 5 comments per IP per 10 minutes
$windowSecs = 600;
$maxPerWin  = 5;

try {
    $pdo->prepare(
        'DELETE FROM reel_comment_rate_limit WHERE created_at < DATE_SUB(NOW(), INTERVAL :s SECOND)'
    )->execute([':s' => $windowSecs]);

    $rlStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reel_comment_rate_limit WHERE ip_hash = :ip'
    );
    $rlStmt->execute([':ip' => $ipHash]);
    if ((int)$rlStmt->fetchColumn() >= $maxPerWin) {
        echo json_encode(['success' => false, 'message' => 'Too many comments. Please wait a few minutes.']);
        exit;
    }

    $pdo->prepare('INSERT INTO reel_comment_rate_limit (ip_hash) VALUES (:ip)')
        ->execute([':ip' => $ipHash]);

    // Insert comment (auto-approved; change to 'pending' + moderation flow as needed)
    $pdo->prepare(
        'INSERT INTO reel_comments (reel_id, author_name, content, status, ip_hash)
         VALUES (:rid, :name, :body, :st, :ip)'
    )->execute([
        ':rid'  => $reelId,
        ':name' => $name,
        ':body' => $body,
        ':st'   => 'approved',
        ':ip'   => $ipHash,
    ]);

    // Increment counter
    $pdo->prepare('UPDATE video_reels SET comments_count = comments_count + 1 WHERE id = :id')
        ->execute([':id' => $reelId]);

    echo json_encode(['success' => true, 'message' => 'Comment added']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
