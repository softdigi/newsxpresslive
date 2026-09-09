<?php
header("Content-Type: application/xml; charset=utf-8");
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// ── Static pages ─────────────────────────────────────────────
$staticPages = [
    ['loc' => SITE_URL . '/',                         'changefreq' => 'hourly',  'priority' => '1.0'],
    ['loc' => SITE_URL . '/legal/about.php',          'changefreq' => 'monthly', 'priority' => '0.6'],
    ['loc' => SITE_URL . '/legal/contact.php',        'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/legal/grievance.php',      'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/legal/privacy.php',        'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/legal/terms.php',          'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/legal/refund.php',         'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/legal/pricing.php',        'changefreq' => 'weekly',  'priority' => '0.7'],
];
$today = date('Y-m-d');
foreach ($staticPages as $page) {
    echo '<url>';
    echo '<loc>' . htmlspecialchars($page['loc'], ENT_XML1, 'UTF-8') . '</loc>';
    echo '<lastmod>' . $today . '</lastmod>';
    echo '<changefreq>' . $page['changefreq'] . '</changefreq>';
    echo '<priority>' . $page['priority'] . '</priority>';
    echo '</url>';
}

// ── News articles ─────────────────────────────────────────────
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
