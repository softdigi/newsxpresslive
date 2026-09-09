<?php
/**
 * Search Page (legacy /search/ endpoint)
 * NewsXpressLive – keyword search with result highlighting
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Sanitise query
$rawQuery  = mb_substr(strip_tags(trim($_GET['q'] ?? '')), 0, 200);
$safeQuery = htmlspecialchars($rawQuery, ENT_QUOTES, 'UTF-8');

$results    = [];
$total      = 0;
$pagination = getPagination(10);
$page       = $pagination['page'];
$offset     = $pagination['offset'];
$perPage    = $pagination['perPage'];

if ($rawQuery !== '') {
    $like = '%' . $rawQuery . '%';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM news
         WHERE status = 'approved'
           AND (title LIKE :like1 OR content LIKE :like2)"
    );
    $countStmt->execute([':like1' => $like, ':like2' => $like]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = 'approved'
           AND (n.title LIKE :like1 OR n.content LIKE :like2)
         ORDER BY n.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':like1',  $like,    PDO::PARAM_STR);
    $stmt->bindValue(':like2',  $like,    PDO::PARAM_STR);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
}

$seoMeta = [
    'title'       => $rawQuery !== '' ? 'Search: ' . $rawQuery : 'Search News',
    'description' => 'Search results for "' . $rawQuery . '" on ' . SITE_NAME,
    'url'         => SITE_URL . '/search/?q=' . urlencode($rawQuery),
    'robots'      => 'noindex,follow',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="search-heading">
        <h1 class="section__title" id="search-heading">
            <?php if ($rawQuery !== ''): ?>
            Search results for <em>&ldquo;<?= $safeQuery ?>&rdquo;</em>
            <?php else: ?>
            <span class="section__title-accent">Search</span> News
            <?php endif; ?>
        </h1>

        <form class="search-form search-form--inline" action="<?= SITE_URL ?>/search/" method="get" role="search">
            <label for="search-q" class="sr-only">Search</label>
            <input type="search" id="search-q" name="q"
                   class="search-form__input"
                   placeholder="Search news…"
                   value="<?= $safeQuery ?>"
                   maxlength="200">
            <button type="submit" class="btn btn--red">Search</button>
        </form>

        <?php if ($rawQuery !== ''): ?>
        <p class="search-results__count">
            Found <strong><?= $total ?></strong> result<?= $total !== 1 ? 's' : '' ?>
        </p>

        <?php if (empty($results)): ?>
        <p class="no-results">No articles matched your search. Try different keywords.</p>
        <?php else: ?>
        <div class="search-results">
            <?php foreach ($results as $item): ?>
            <article class="search-result">
                <?php if (!empty($item['featured_image'])): ?>
                <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="search-result__img-link">
                    <img src="<?= htmlspecialchars(newsImage($item['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="search-result__img"
                         loading="lazy">
                </a>
                <?php endif; ?>
                <div class="search-result__body">
                    <?php if (!empty($item['category_name'])): ?>
                    <a href="<?= htmlspecialchars(categoryUrl($item['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="badge badge--outline">
                        <?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php endif; ?>
                    <h2 class="search-result__title">
                        <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= highlightKeywords($item['title'], $rawQuery) ?>
                        </a>
                    </h2>
                    <p class="search-result__excerpt">
                        <?= highlightKeywords(excerpt($item['content'], 200), $rawQuery) ?>
                    </p>
                    <time class="search-result__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($item['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php renderPagination($total, $perPage, $page, SITE_URL . '/search/?q=' . urlencode($rawQuery)); ?>
        <?php endif; ?>
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
