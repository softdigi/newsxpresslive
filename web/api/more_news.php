<?php
/**
 * Load More News API
 * NewsXpressLive
 *
 * Returns paginated news items as JSON.
 * Supports optional ?category=slug filter for category feeds.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = min(20, max(1, (int)($_GET['per'] ?? 9)));
$offset   = ($page - 1) * $per;
$catSlug  = mb_substr(strip_tags(trim($_GET['category'] ?? '')), 0, 200, 'UTF-8');

try {
    $where  = 'n.status = :status';
    $params = [':status' => 'published'];

    if ($catSlug !== '') {
        $where .= ' AND c.slug = :cat';
        $params[':cat'] = $catSlug;
    }

    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                n.created_at, n.is_breaking, n.views,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE $where
         ORDER BY n.created_at DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $per,    PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $news = $stmt->fetchAll();

    foreach ($news as &$item) {
        $item['id']         = (int)$item['id'];
        $item['is_breaking']= (bool)$item['is_breaking'];
        $item['views']      = (int)$item['views'];
        // Return absolute URL for featured image
        $item['featured_image'] = !empty($item['featured_image'])
            ? UPLOADS_URL . rawurlencode($item['featured_image'])
            : null;
    }

    echo json_encode(array_values($news));

} catch (PDOException $e) {
    error_log('More news error: ' . $e->getMessage());
    echo json_encode([]);
}

