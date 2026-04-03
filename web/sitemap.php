<?php
header("Content-Type: application/xml; charset=utf-8");
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$stmt = $pdo->query("SELECT slug, updated_at FROM news WHERE status='approved' ORDER BY updated_at DESC");
while ($n = $stmt->fetch()) {
    $loc     = htmlspecialchars(newsUrl($n['slug']), ENT_XML1, 'UTF-8');
    $lastmod = date('Y-m-d', strtotime($n['updated_at']));
    echo '<url>';
    echo '<loc>' . $loc . '</loc>';
    echo '<lastmod>' . $lastmod . '</lastmod>';
    echo '<changefreq>daily</changefreq>';
    echo '<priority>0.8</priority>';
    echo '</url>';
}

echo '</urlset>';
