<?php
/**
 * Location News Page (slug-based)
 * NewsXpressLive – List approved news for a location by state_slug or city_slug.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = getParam('slug');
if ($slug === '') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

$locationName = htmlspecialchars(ucwords(str_replace('-', ' ', $slug)), ENT_QUOTES, 'UTF-8');

// Fetch location-based news (gracefully handles missing slug columns)
$newsList = [];
try {
    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                n.views, c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = 'approved'
           AND (n.state_slug = :slug OR n.city_slug = :slug)
         ORDER BY n.created_at DESC
         LIMIT 30"
    );
    $stmt->execute([':slug' => $slug]);
    $newsList = $stmt->fetchAll();
} catch (PDOException $e) {
    // state_slug / city_slug columns may not exist in all deployments
    $newsList = [];
}

$seoMeta = [
    'title'       => $locationName . ' News – Latest & Breaking Updates',
    'description' => 'Latest breaking news, viral updates and top stories from ' . $locationName . '.',
    'url'         => SITE_URL . '/location/?slug=' . urlencode($slug),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="location-heading">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a>
            &rsaquo; <span><?= $locationName ?></span>
        </nav>

        <h1 class="section__title" id="location-heading">
            📍 <span class="section__title-accent"><?= $locationName ?></span> News
        </h1>

        <?php if (empty($newsList)): ?>
        <p class="no-results">No news available for this location.</p>
        <?php else: ?>
        <div class="news-grid">
            <?php foreach ($newsList as $news): ?>
            <article class="news-card">
                <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="news-card__img-link">
                    <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img"
                         loading="lazy">
                </a>
                <div class="news-card__body">
                    <?php if (!empty($news['category_name'])): ?>
                    <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="badge badge--outline">
                        <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php endif; ?>
                    <h2 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h2>
                    <p class="news-card__excerpt">
                        <?= htmlspecialchars(excerpt($news['content']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="news-card__meta">
                        <time datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= timeAgo($news['created_at']) ?>
                        </time>
                        <?php if (!empty($news['views'])): ?>
                        <span><?= formatViews((int)$news['views']) ?> views</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="app-cta-inline">
            <p>📱 Get local alerts instantly on our app</p>
            <a href="<?= htmlspecialchars(PLAY_STORE_URL, ENT_QUOTES, 'UTF-8') ?>" class="btn-download">Download App</a>
        </div>
    </section>

</div><!-- /.layout-main -->

<aside class="layout-sidebar" aria-label="Sidebar">
    <div class="widget">
        <h3 class="widget__title">Categories</h3>
        <ul class="cat-list">
            <?php foreach (getAllCategories($pdo) as $cat): ?>
            <li>
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="cat-list__link">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>

</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
