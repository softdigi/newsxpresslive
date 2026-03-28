<?php
/**
 * Latest Breaking News API
 * NewsXpressLive
 * 
 * Returns the most recent breaking news item
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->prepare(
        'SELECT id, title, slug, created_at 
         FROM news 
         WHERE status = :status AND is_breaking = 1
         ORDER BY created_at DESC 
         LIMIT 1'
    );
    $stmt->execute([':status' => 'published']);
    $news = $stmt->fetch();
    
    if ($news) {
        echo json_encode([
            'id'         => (int)$news['id'],
            'title'      => htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8'),
            'slug'       => $news['slug'],
            'created_at' => $news['created_at']
        ]);
    } else {
        echo json_encode(null);
    }
    
} catch (PDOException $e) {
    error_log('Latest breaking error: ' . $e->getMessage());
    echo json_encode(null);
}
