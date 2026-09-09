<?php
/**
 * web/api/reel_like.php
 *
 * POST { reel_id: int }
 * Toggles a like for the current visitor (IP-hash based).
 * Returns { liked: bool, likes_count: int }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$input  = json_decode(file_get_contents('php://input'), true);
$reelId = isset($input['reel_id']) ? (int)$input['reel_id'] : 0;

if ($reelId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'reel_id required']);
    exit;
}

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));

try {
    // Check if like already exists
    $checkStmt = $pdo->prepare(
        'SELECT id FROM reel_likes WHERE reel_id = :rid AND ip_hash = :ip LIMIT 1'
    );
    $checkStmt->execute([':rid' => $reelId, ':ip' => $ipHash]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        // Un-like
        $pdo->prepare('DELETE FROM reel_likes WHERE reel_id = :rid AND ip_hash = :ip')
            ->execute([':rid' => $reelId, ':ip' => $ipHash]);
        $pdo->prepare('UPDATE video_reels SET likes_count = GREATEST(0, likes_count - 1) WHERE id = :id')
            ->execute([':id' => $reelId]);
        $liked = false;
    } else {
        // Like
        $pdo->prepare('INSERT IGNORE INTO reel_likes (reel_id, ip_hash) VALUES (:rid, :ip)')
            ->execute([':rid' => $reelId, ':ip' => $ipHash]);
        $pdo->prepare('UPDATE video_reels SET likes_count = likes_count + 1 WHERE id = :id')
            ->execute([':id' => $reelId]);
        $liked = true;
    }

    // Fetch fresh count
    $countStmt = $pdo->prepare('SELECT likes_count FROM video_reels WHERE id = :id LIMIT 1');
    $countStmt->execute([':id' => $reelId]);
    $count = (int)($countStmt->fetchColumn() ?: 0);

    echo json_encode(['liked' => $liked, 'likes_count' => $count]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
