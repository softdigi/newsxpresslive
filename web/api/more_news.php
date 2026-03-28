<?php
/**
 * Load More News API
 * NewsXpressLive
 * 
 * Returns paginated news items as JSON
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(20, max(1, (int)($_GET['per'] ?? 9)));
$offset = ($page - 1) * $per;

try {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = :status
         ORDER BY n.created_at DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $per, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $news = $stmt->fetchAll();
    
    // Format dates and sanitize output
    foreach ($news as &$item) {
        $item['created_at'] = formatDate($item['created_at']);
        $item['title'] = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
        $item['category_name'] = htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    echo json_encode($news);
    
} catch (PDOException $e) {
    error_log('More news error: ' . $e->getMessage());
    echo json_encode([]);
}
