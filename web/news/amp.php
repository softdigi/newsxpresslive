<?php
/**
 * AMP (Accelerated Mobile Pages) article page
 * NewsXpressLive
 *
 * Canonical counterpart: /news/detail.php?slug=…
 * AMP spec: https://amp.dev/documentation/guides-and-tutorials/start/create/
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo.php';

/* ── Validate slug ─────────────────────────────────────────────────── */
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/i', $slug)) {
    http_response_code(404);
    exit('Article not found.');
}

/* ── Fetch article ─────────────────────────────────────────────────── */
$stmt = $pdo->prepare(
    'SELECT n.id, n.title, n.slug, n.content, n.featured_image,
            n.created_at, n.updated_at,
            c.name AS category_name, c.slug AS category_slug,
            r.name AS reporter_name
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     LEFT JOIN reporters  r ON r.id = n.reporter_id
     WHERE n.slug = :slug AND n.status = :status
     LIMIT 1'
);
$stmt->execute([':slug' => $slug, ':status' => 'published']);
$news = $stmt->fetch();

if (!$news) {
    http_response_code(404);
    exit('Article not found.');
}

/* ── Prepare values ────────────────────────────────────────────────── */
$title       = htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8');
$description = htmlspecialchars(excerpt($news['content'], 160), ENT_QUOTES, 'UTF-8');
$canonical   = newsUrl($news['slug']);
$imageUrl    = newsImage($news['featured_image']);
$published   = date('c', strtotime($news['created_at']));
$modified    = !empty($news['updated_at'])
               ? date('c', strtotime($news['updated_at']))
               : $published;

// Strip disallowed AMP tags from content; keep basic inline formatting only.
// Block elements that AMP forbids are removed; images are handled separately.
$content = strip_tags($news['content'],
    '<p><h2><h3><h4><strong><em><b><i><ul><ol><li><blockquote><a><br>');

/* ── JSON-LD ───────────────────────────────────────────────────────── */
$jsonLd = [
    '@context'      => 'https://schema.org',
    '@type'         => 'NewsArticle',
    'headline'      => $news['title'],
    'description'   => excerpt($news['content'], 160),
    'url'           => $canonical,
    'datePublished' => $published,
    'dateModified'  => $modified,
    'publisher'     => [
        '@type' => 'Organization',
        'name'  => SITE_NAME,
        'logo'  => [
            '@type'  => 'ImageObject',
            'url'    => SITE_URL . '/assets/img/og-default.jpg',
            'width'  => 600,
            'height' => 60,
        ],
    ],
    'author' => !empty($news['reporter_name'])
        ? ['@type' => 'Person', 'name' => $news['reporter_name']]
        : ['@type' => 'Organization', 'name' => SITE_NAME],
];

if (!empty($imageUrl)) {
    $jsonLd['image'] = ['@type' => 'ImageObject', 'url' => $imageUrl, 'width' => 1200, 'height' => 630];
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html ⚡ lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
    <title><?= $title ?></title>
    <meta name="description" content="<?= $description ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">

    <!-- AMP boilerplate -->
    <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style>
    <noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>

    <script async src="https://cdn.ampproject.org/v0.js"></script>

    <!-- AMP custom styles (inline, max 75KB) -->
    <style amp-custom>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:17px;line-height:1.7;color:#222;background:#fff}
        .amp-header{background:#e50914;padding:12px 16px;display:flex;align-items:center;gap:10px}
        .amp-header a{color:#fff;text-decoration:none;font-weight:700;font-size:1.3rem;letter-spacing:.5px}
        .amp-container{max-width:740px;margin:0 auto;padding:16px}
        .amp-breadcrumb{font-size:.8rem;color:#888;margin-bottom:14px}
        .amp-breadcrumb a{color:#e50914;text-decoration:none}
        .amp-category{display:inline-block;background:#e50914;color:#fff;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:3px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;text-decoration:none}
        h1{font-size:1.6rem;font-weight:800;line-height:1.3;margin-bottom:10px;color:#111}
        .amp-meta{font-size:.8rem;color:#888;margin-bottom:16px}
        .amp-meta span{margin-right:12px}
        .amp-hero{margin-bottom:18px}
        .amp-content{font-size:1rem;line-height:1.75;color:#333}
        .amp-content p{margin-bottom:1em}
        .amp-content h2{font-size:1.25rem;font-weight:700;margin:1.4em 0 .5em}
        .amp-content h3{font-size:1.1rem;font-weight:700;margin:1.2em 0 .4em}
        .amp-content blockquote{border-left:4px solid #e50914;padding:8px 12px;margin:1em 0;background:#fafafa;color:#555;font-style:italic}
        .amp-content ul,.amp-content ol{padding-left:1.5em;margin-bottom:1em}
        .amp-content li{margin-bottom:.4em}
        .amp-content a{color:#e50914}
        .amp-footer{background:#111;color:#aaa;text-align:center;padding:18px 16px;font-size:.8rem;margin-top:30px}
        .amp-footer a{color:#e50914;text-decoration:none}
    </style>

    <!-- JSON-LD structured data -->
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

    <!-- Open Graph -->
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $description ?>">
    <?php if (!empty($imageUrl)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>

<header class="amp-header">
    <a href="<?= SITE_URL ?>/"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></a>
</header>

<div class="amp-container">

    <!-- Breadcrumb -->
    <nav class="amp-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= SITE_URL ?>/">Home</a>
        <?php if (!empty($news['category_name'])): ?>
        &rsaquo; <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
        &rsaquo; <?= $title ?>
    </nav>

    <?php if (!empty($news['category_name'])): ?>
    <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>" class="amp-category">
        <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>

    <h1><?= $title ?></h1>

    <div class="amp-meta">
        <span>📅 <?= date('M j, Y', strtotime($news['created_at'])) ?></span>
        <?php if (!empty($news['reporter_name'])): ?>
        <span>✍️ <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($imageUrl)): ?>
    <figure class="amp-hero">
        <amp-img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= $title ?>"
                 width="740"
                 height="416"
                 layout="responsive"></amp-img>
    </figure>
    <?php endif; ?>

    <div class="amp-content">
        <?= $content ?>
    </div>

</div>

<footer class="amp-footer">
    &copy; <?= date('Y') ?> <a href="<?= SITE_URL ?>/"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></a>
    &mdash; <a href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">View full article</a>
</footer>

</body>
</html>
