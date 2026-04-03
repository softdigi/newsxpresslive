<?php
/**
 * Agency Profile Page
 * NewsXpressLive – Show agency details and their published articles
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate id
$agencyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$agencyId || $agencyId < 1) {
    header('Location: ' . SITE_URL . '/');
    exit;
}

// Fetch agency
$aStmt = $pdo->prepare('SELECT id, name, logo FROM agencies WHERE id = :id LIMIT 1');
$aStmt->execute([':id' => $agencyId]);
$agency = $aStmt->fetch();

if (!$agency) {
    http_response_code(404);
    $seoMeta = ['title' => 'Agency Not Found'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">Agency not found.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pagination = getPagination(12);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

// Total articles by agency
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE status = 'approved' AND agency_id = :aid"
);
$countStmt->execute([':aid' => $agencyId]);
$total = (int)$countStmt->fetchColumn();

// Articles
$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
            c.name AS category_name, c.slug AS category_slug,
            r.name AS reporter_name
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     LEFT JOIN reporters  r ON r.id = n.reporter_id
     WHERE n.status = 'approved' AND n.agency_id = :aid
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':aid',    $agencyId, PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll();

$seoMeta = [
    'title'       => $agency['name'] . ' – News Agency',
    'description' => 'Latest news published by ' . $agency['name'] . ' on ' . SITE_NAME,
    'url'         => agencyUrl($agencyId),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section profile-section" aria-labelledby="agency-heading">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a> &rsaquo;
            <span>Agency: <?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <div class="profile-card">
            <?php
            $agencyLogoUrl = !empty($agency['logo'])
                ? mediaUrl($agency['logo'], 'agencies')
                : '';
            ?>
            <?php if ($agencyLogoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($agencyLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?>"
                 class="profile-card__photo profile-card__photo--logo"
                 loading="lazy">
            <?php endif; ?>
            <div class="profile-card__info">
                <h1 class="profile-card__name" id="agency-heading">
                    <?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <p class="profile-card__count">
                    <strong><?= $total ?></strong> published article<?= $total !== 1 ? 's' : '' ?>
                </p>
            </div>
        </div>

        <h2 class="section__title section__title--sub">
            Articles from <span class="section__title-accent">
                <?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>

        <?php if (empty($articles)): ?>
        <p class="no-results">No published articles from this agency yet.</p>
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
                    <?php if (!empty($news['reporter_name'])): ?>
                    <span class="news-card__reporter">
                        By <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
                    <time class="news-card__date" datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($news['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php renderPagination($total, $perPage, $page, agencyUrl($agencyId)); ?>
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
