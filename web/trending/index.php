<?php
/**
 * Trending News Page
 * NewsXpressLive – Most-viewed approved articles
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$stmt = $pdo->query(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.created_at,
            COALESCE(n.views, 0) AS views,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = 'approved'
     ORDER BY n.views DESC, n.created_at DESC
     LIMIT 20"
);
$trending = $stmt->fetchAll();

$seoMeta = [
    'title'       => 'Trending News',
    'description' => 'Most read and trending news articles on ' . SITE_NAME . '.',
    'url'         => SITE_URL . '/trending/',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="trending-heading">
        <h1 class="section__title" id="trending-heading">
            🔥 <span class="section__title-accent">Trending</span> News
        </h1>

        <?php if (empty($trending)): ?>
        <p class="no-results">No trending news found.</p>
        <?php else: ?>
        <div class="news-grid">
            <?php foreach ($trending as $news): ?>
            <article class="news-card trending-card">
                <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="news-card__img-link">
                    <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img"
                         loading="lazy">
                </a>
                <div class="news-card__body">
                    <span class="trending-badge">🔥 TRENDING</span>
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
                    <div class="news-card__meta">
                        <span><?= formatViews((int)$news['views']) ?> views</span>
                        <time datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= timeAgo($news['created_at']) ?>
                        </time>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="app-cta-inline">
            <p>📱 Trending news updates faster on app</p>
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
