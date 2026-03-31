<?php
/**
 * web/api/trending.php
 * GET ?limit=6
 * Returns trending/top-viral published articles from the last 7 days,
 * ordered by viral_score (views + shares + comments + watch time) DESC.
 * Used by the Flutter app's Trending section on the Home screen.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';

$limit = max(1, min(20, (int)($_GET['limit'] ?? 6)));

try {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.slug, n.featured_image,
                n.content, n.created_at, n.is_breaking,
                COALESCE(n.views, 0)        AS views,
                COALESCE(n.viral_score, 0)  AS viral_score,
                COALESCE(n.is_trending, 0)  AS is_trending,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = :status
           AND n.created_at >= NOW() - INTERVAL 7 DAY
         ORDER BY n.viral_score DESC, n.views DESC, n.created_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
    $stmt->bindValue(':lim',    $limit,      PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    // Fallback: if no articles in the last 7 days, return overall top-viral
    if (empty($rows)) {
        $stmt2 = $pdo->prepare(
            'SELECT n.id, n.title, n.slug, n.featured_image,
                    n.content, n.created_at, n.is_breaking,
                    COALESCE(n.views, 0)        AS views,
                    COALESCE(n.viral_score, 0)  AS viral_score,
                    COALESCE(n.is_trending, 0)  AS is_trending,
                    c.name AS category_name, c.slug AS category_slug
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE n.status = :status
             ORDER BY n.viral_score DESC, n.views DESC, n.created_at DESC
             LIMIT :lim'
        );
        $stmt2->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt2->bindValue(':lim',    $limit,      PDO::PARAM_INT);
        $stmt2->execute();
        $rows = $stmt2->fetchAll();
    }

    foreach ($rows as &$row) {
        $row['id']          = (int)$row['id'];
        $row['views']       = (int)$row['views'];
        $row['viral_score'] = (float)$row['viral_score'];
        $row['is_trending'] = (bool)$row['is_trending'];
        $row['is_breaking'] = (bool)$row['is_breaking'];
        $row['featured_image'] = !empty($row['featured_image'])
            ? UPLOADS_URL . rawurlencode($row['featured_image'])
            : null;
    }

    echo json_encode(array_values($rows));
} catch (PDOException $e) {
    error_log('trending API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
