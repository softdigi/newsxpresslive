<?php
header("Content-Type: application/xml; charset=utf-8");
require_once __DIR__.'/includes/config.php';

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$stmt = $pdo->query("SELECT id,title,updated_at FROM news WHERE status='approved'");
while ($n = $stmt->fetch()) {
    echo '<url>';
    echo '<loc>'.SITE_URL.'/newsxpresslive_api/web/news/'.generateSlug($n['title']).'-'.$n['id'].'</loc>';
    echo '<lastmod>'.date('Y-m-d', strtotime($n['updated_at'])).'</lastmod>';
    echo '<changefreq>daily</changefreq>';
    echo '<priority>0.8</priority>';
    echo '</url>';
}

echo '</urlset>';
