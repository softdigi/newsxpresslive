<?php
/**
 * Reporter Profile Page
 * NewsXpressLive – Show reporter details and their published articles
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate id
$reporterId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$reporterId || $reporterId < 1) {
    header('Location: ' . SITE_URL . '/');
    exit;
}

// Fetch reporter
$rStmt = $pdo->prepare('SELECT id, name, photo, bio FROM reporters WHERE id = :id LIMIT 1');
$rStmt->execute([':id' => $reporterId]);
$reporter = $rStmt->fetch();

if (!$reporter) {
    http_response_code(404);
    $seoMeta = ['title' => 'Reporter Not Found'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">Reporter not found.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pagination = getPagination(12);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

// Total articles by reporter
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE status = 'published' AND reporter_id = :rid"
);
$countStmt->execute([':rid' => $reporterId]);
$total = (int)$countStmt->fetchColumn();

// Articles
$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = 'published' AND n.reporter_id = :rid
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':rid',    $reporterId, PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage,    PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll();

$seoMeta = [
    'title'       => $reporter['name'],
    'description' => !empty($reporter['bio'])
        ? excerpt($reporter['bio'], 160)
        : 'Articles by ' . $reporter['name'] . ' on ' . SITE_NAME,
    'url'         => reporterUrl($reporterId),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <!-- ===== REPORTER PROFILE ===== -->
    <section class="section profile-section" aria-labelledby="reporter-heading">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a> &rsaquo;
            <span>Reporter: <?= htmlspecialchars($reporter['name'], ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <div class="profile-card">
            <?php if (!empty($reporter['photo'])): ?>
            <img src="<?= htmlspecialchars(SITE_URL . '/uploads/reporters/' . $reporter['photo'], ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($reporter['name'], ENT_QUOTES, 'UTF-8') ?>"
                 class="profile-card__photo"
                 loading="lazy">
            <?php endif; ?>
            <div class="profile-card__info">
                <h1 class="profile-card__name" id="reporter-heading">
                    <?= htmlspecialchars($reporter['name'], ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <?php if (!empty($reporter['bio'])): ?>
                <p class="profile-card__bio">
                    <?= htmlspecialchars($reporter['bio'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php endif; ?>
                <p class="profile-card__count">
                    <strong><?= $total ?></strong> published article<?= $total !== 1 ? 's' : '' ?>
                </p>
            </div>
        </div>

        <h2 class="section__title section__title--sub">
            Articles by <span class="section__title-accent">
                <?= htmlspecialchars($reporter['name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>

        <?php if (empty($articles)): ?>
        <p class="no-results">No published articles yet.</p>
        <?php else: ?>
        <div class="news-grid">
            <?php foreach ($articles as $news): ?>
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
                    <h3 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h3>
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

        <?php renderPagination($total, $perPage, $page, reporterUrl($reporterId)); ?>
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
