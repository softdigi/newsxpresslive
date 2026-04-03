<?php
/**
 * web/api/search.php
 * GET ?q=keyword&page=1
 * Returns matching published news articles as JSON for the Flutter app.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$query  = mb_substr(strip_tags(trim($_GET['q'] ?? '')), 0, 200, 'UTF-8');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

if ($query === '') {
    echo json_encode([]);
    exit;
}

$like = '%' . $query . '%';

try {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                n.created_at, n.is_breaking, n.views,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = :status
           AND (n.title LIKE :like1 OR n.content LIKE :like2)
         ORDER BY n.created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':status', 'approved', PDO::PARAM_STR);
    $stmt->bindValue(':like1',  $like,       PDO::PARAM_STR);
    $stmt->bindValue(':like2',  $like,       PDO::PARAM_STR);
    $stmt->bindValue(':lim',    $perPage,    PDO::PARAM_INT);
    $stmt->bindValue(':off',    $offset,     PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['is_breaking'] = (bool)$row['is_breaking'];
        $row['views']       = (int)$row['views'];
        $row['featured_image'] = $row['featured_image']
            ? UPLOADS_URL . rawurlencode($row['featured_image'])
            : null;
    }

    echo json_encode(array_values($rows));
} catch (PDOException $e) {
    error_log('search API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
