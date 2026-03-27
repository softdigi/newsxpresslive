<?php
/**
 * City / Location News Page
 * NewsXpressLive – List published news for a city
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate city id
$cityId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$cityId || $cityId < 1) {
    header('Location: ' . SITE_URL . '/');
    exit;
}

// Fetch city name (graceful fallback if cities table doesn't exist)
$cityName = 'City #' . $cityId;
try {
    $cityStmt = $pdo->prepare('SELECT name FROM cities WHERE id = :id LIMIT 1');
    $cityStmt->execute([':id' => $cityId]);
    $cityRow  = $cityStmt->fetch();
    if ($cityRow) {
        $cityName = $cityRow['name'];
    }
} catch (PDOException $e) {
    // cities table may not exist – silently use the fallback name
}

$pagination = getPagination(12);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE status = 'published' AND city_id = :city_id"
);
$countStmt->execute([':city_id' => $cityId]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = 'published' AND n.city_id = :city_id
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':city_id', $cityId, PDO::PARAM_INT);
$stmt->bindValue(':limit',   $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset',  $offset,  PDO::PARAM_INT);
$stmt->execute();
$newsList = $stmt->fetchAll();

$seoMeta = [
    'title'       => 'News from ' . $cityName,
    'description' => 'Latest news from ' . $cityName . ' on ' . SITE_NAME,
    'url'         => cityUrl($cityId),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">
    <section class="section" aria-labelledby="city-heading">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a>
            &rsaquo; <span><?= htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <h1 class="section__title" id="city-heading">
            News from <span class="section__title-accent">
                <?= htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h1>

        <?php if (empty($newsList)): ?>
        <p class="no-results">No news found for this location yet.</p>
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
        <?php renderPagination($total, $perPage, $page, cityUrl($cityId)); ?>
        <?php endif; ?>
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
