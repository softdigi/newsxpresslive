<?php
/**
 * web/news/detail_api.php
 * GET ?slug=article-slug
 * Returns full article details as JSON for the Flutter app.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = mb_substr(strip_tags(trim($_GET['slug'] ?? '')), 0, 500, 'UTF-8');
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['error' => 'slug required']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.slug, n.content, n.featured_image,
                n.created_at, n.is_breaking, n.views,
                c.name  AS category_name, c.slug AS category_slug,
                r.name  AS reporter_name, r.photo AS reporter_photo,
                r.bio   AS reporter_bio,
                a.name  AS agency_name,  a.logo  AS agency_logo
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         LEFT JOIN reporters  r ON r.id = n.reporter_id
         LEFT JOIN agencies   a ON a.id = n.agency_id
         WHERE n.slug = :slug AND n.status = :status
         LIMIT 1'
    );
    $stmt->execute([':slug' => $slug, ':status' => 'published']);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(null);
        exit;
    }

    // Increment view count
    try {
        $pdo->prepare('UPDATE news SET views = COALESCE(views,0)+1 WHERE id=?')
            ->execute([$row['id']]);
    } catch (PDOException $e) { /* ignore */ }

    // Build absolute image URLs
    $row['is_breaking']    = (bool)$row['is_breaking'];
    $row['views']          = (int)$row['views'];
    $row['featured_image'] = !empty($row['featured_image'])
        ? UPLOADS_URL . rawurlencode($row['featured_image'])
        : null;
    $row['reporter_photo'] = !empty($row['reporter_photo'])
        ? SITE_URL . '/uploads/reporters/' . rawurlencode($row['reporter_photo'])
        : null;
    $row['agency_logo']    = !empty($row['agency_logo'])
        ? SITE_URL . '/uploads/agencies/' . rawurlencode($row['agency_logo'])
        : null;

    echo json_encode($row);
} catch (PDOException $e) {
    error_log('detail_api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(null);
}
