<?php
/**
 * News Listing / Index
 * NewsXpressLive – paginated list of all published news
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$pagination = getPagination(12);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM news WHERE status = 'approved'");
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

// Fetch page
$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = 'approved'
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$newsList = $stmt->fetchAll();

$seoMeta = [
    'title'       => 'All News',
    'description' => 'Browse all published news articles on ' . SITE_NAME,
    'url'         => SITE_URL . '/news/?page=' . $page,
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="news-index-heading">
        <h1 class="section__title" id="news-index-heading">
            <span class="section__title-accent">All</span> News
        </h1>

        <?php if (empty($newsList)): ?>
        <p class="no-results">No news articles found.</p>
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
                    <time class="news-card__date" datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($news['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php renderPagination($total, $perPage, $page, SITE_URL . '/news/?'); ?>
        <?php endif; ?>
    </section>

</div><!-- /.layout-main -->

<!-- Sidebar -->
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
