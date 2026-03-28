<?php
/**
 * Google News Sitemap
 * NewsXpressLive
 * 
 * Generates XML sitemap for Google News (last 2 days of published news)
 */

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Get news from last 2 days
$stmt = $pdo->prepare(
    'SELECT n.title, n.slug, n.created_at, c.name AS category_name
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = :status
       AND n.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
     ORDER BY n.created_at DESC
     LIMIT 1000'
);
$stmt->execute([':status' => 'published']);
$newsItems = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ($newsItems as $item): 
    $url = newsUrl($item['slug']);
    $pubDate = date('c', strtotime($item['created_at']));
    $title = htmlspecialchars($item['title'], ENT_XML1, 'UTF-8');
    $keywords = htmlspecialchars($item['category_name'] ?? '', ENT_XML1, 'UTF-8');
?>
    <url>
        <loc><?= htmlspecialchars($url, ENT_XML1, 'UTF-8') ?></loc>
        <news:news>
            <news:publication>
                <news:name><?= htmlspecialchars(SITE_NAME, ENT_XML1, 'UTF-8') ?></news:name>
                <news:language>en</news:language>
            </news:publication>
            <news:publication_date><?= $pubDate ?></news:publication_date>
            <news:title><?= $title ?></news:title>
<?php if ($keywords): ?>
            <news:keywords><?= $keywords ?></news:keywords>
<?php endif; ?>
        </news:news>
    </url>
<?php endforeach; ?>
</urlset>
