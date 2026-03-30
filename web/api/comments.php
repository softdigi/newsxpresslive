<?php
/**
 * web/api/comments.php
 * GET ?news_id=123
 * Returns all approved comments (flat, threaded by parent_id) for the Flutter app.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';

$newsId = isset($_GET['news_id']) ? (int)$_GET['news_id'] : 0;

if ($newsId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'news_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, news_id, parent_id, author_name, content, created_at
         FROM comments
         WHERE news_id = ? AND status = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$newsId, 'approved']);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id']        = (int)$row['id'];
        $row['news_id']   = (int)$row['news_id'];
        $row['parent_id'] = $row['parent_id'] !== null
            ? (int)$row['parent_id']
            : null;
    }

    echo json_encode(array_values($rows));
} catch (PDOException $e) {
    // comments table may not exist yet
    echo json_encode([]);
}
