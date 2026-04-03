<?php
/**
 * Category View Page
 * NewsXpressLive – List all published news for a category (with pagination)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate slug
$slug = getParam('slug');
if ($slug === '') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

// Fetch category
$catStmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE slug = :slug LIMIT 1');
$catStmt->execute([':slug' => $slug]);
$category = $catStmt->fetch();

if (!$category) {
    http_response_code(404);
    $seoMeta = ['title' => 'Category Not Found'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">Category not found.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Track category preference in session for homepage personalisation.
// We cap each category at 99 to prevent integer overflow on long sessions.
if (!isset($_SESSION['pref_cats']) || !is_array($_SESSION['pref_cats'])) {
    $_SESSION['pref_cats'] = [];
}
$cid = (int)$category['id'];
$_SESSION['pref_cats'][$cid] = min(99, ($_SESSION['pref_cats'][$cid] ?? 0) + 1);

$pagination = getPagination(12);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

// Count
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE status = 'approved' AND category_id = :cat_id"
);
$countStmt->execute([':cat_id' => $category['id']]);
$total = (int)$countStmt->fetchColumn();

// Fetch
$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at
     FROM news n
     WHERE n.status = 'approved' AND n.category_id = :cat_id
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':cat_id', $category['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage,        PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,         PDO::PARAM_INT);
$stmt->execute();
$newsList = $stmt->fetchAll();

$seoMeta = [
    'title'       => $category['name'],
    'description' => 'Latest news in the ' . $category['name'] . ' category on ' . SITE_NAME,
    'url'         => categoryUrl($category['slug']),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="cat-page-heading">
        <!-- Breadcrumb -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a>
            &rsaquo; <span><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <h1 class="section__title" id="cat-page-heading">
            <span class="section__title-accent">
                <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h1>

        <p class="section__count"><?= $total ?> article<?= $total !== 1 ? 's' : '' ?></p>

        <?php if (empty($newsList)): ?>
        <p class="no-results">No articles in this category yet.</p>
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

        <?php renderPagination($total, $perPage, $page, categoryUrl($category['slug'])); ?>
        <?php endif; ?>
    </section>

</div><!-- /.layout-main -->

<!-- Sidebar -->
<aside class="layout-sidebar" aria-label="Sidebar">
    <div class="widget">
        <h3 class="widget__title">All Categories</h3>
        <ul class="cat-list">
            <?php foreach (getAllCategories($pdo) as $cat): ?>
            <li>
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="cat-list__link<?= $cat['id'] === $category['id'] ? ' cat-list__link--active' : '' ?>">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>

</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
