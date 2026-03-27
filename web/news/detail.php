<?php
/**
 * News Detail Page
 * NewsXpressLive – Fetch article by slug, show full content,
 * reporter info (via JOIN), and related news (same category).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate slug param
$slug = getParam('slug');
if ($slug === '') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

/* ── Fetch the article ──────────────────────────────────────────────── */
$stmt = $pdo->prepare(
    'SELECT n.id, n.title, n.slug, n.content, n.featured_image,
            n.created_at, n.is_breaking,
            n.reporter_id, n.agency_id, n.category_id,
            c.name  AS category_name, c.slug AS category_slug,
            r.name  AS reporter_name, r.photo AS reporter_photo,
            r.bio   AS reporter_bio,
            a.name  AS agency_name,  a.logo  AS agency_logo
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     LEFT JOIN reporters  r ON r.id = n.reporter_id
     LEFT JOIN agencies   a ON a.id = n.agency_id
     WHERE n.slug = :slug AND n.status = :status
     LIMIT 1'
);
$stmt->execute([':slug' => $slug, ':status' => 'published']);
$news = $stmt->fetch();

if (!$news) {
    // True 404 — tell search engines not to index this page
    http_response_code(404);
    $seoMeta = ['title' => 'Article Not Found', 'robots' => 'noindex,nofollow'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">The article you are looking for does not exist or has been removed.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ── Related news (same category, exclude current) ─────────────────── */
$relatedNews = [];
if (!empty($news['category_id'])) {
    $relStmt = $pdo->prepare(
        'SELECT n.title, n.slug, n.featured_image, n.created_at
         FROM news n
         WHERE n.status = :status
           AND n.category_id = :cat_id
           AND n.id != :id
         ORDER BY n.created_at DESC
         LIMIT 4'
    );
    $relStmt->execute([
        ':status' => 'published',
        ':cat_id' => $news['category_id'],
        ':id'     => $news['id'],
    ]);
    $relatedNews = $relStmt->fetchAll();
}

/* ── Next article (same category, older or any order) ───────────────── */
$nextArticle = null;
if (!empty($news['category_id'])) {
    $nextStmt = $pdo->prepare(
        'SELECT title, slug, featured_image FROM news
         WHERE status = :status AND category_id = :cat_id AND id != :id
         ORDER BY created_at DESC LIMIT 1'
    );
    $nextStmt->execute([
        ':status' => 'published',
        ':cat_id' => $news['category_id'],
        ':id'     => $news['id'],
    ]);
    $nextArticle = $nextStmt->fetch() ?: null;
}

/* ── SEO meta + structured data ─────────────────────────────────────── */
$seoMeta = [
    'title'        => $news['title'],
    'description'  => excerpt($news['content'], 160),
    'image'        => newsImage($news['featured_image']),
    'url'          => newsUrl($news['slug']),
    'type'         => 'article',
    'keywords'     => !empty($news['category_name']) ? $news['category_name'] : '',
    'author'       => !empty($news['reporter_name']) ? $news['reporter_name'] : '',
    'published_at' => date('c', strtotime($news['created_at'])),
    // Prefetch the next article so it loads instantly when the user clicks
    'prefetch_url' => $nextArticle ? newsUrl($nextArticle['slug']) : '',
];

// Sidebar – reuse the latest-news query, capped at 6 for efficiency
$sideStmt = $pdo->prepare(
    'SELECT title, slug, created_at FROM news
     WHERE status = :status ORDER BY created_at DESC LIMIT 6'
);
$sideStmt->execute([':status' => 'published']);
$sideItems = $sideStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';

// ── JSON-LD Structured Data ──────────────────────────────────────────────
renderJsonLd(buildNewsArticleJsonLd($news));

// BreadcrumbList
$breadcrumbItems = [['name' => 'Home', 'url' => SITE_URL . '/']];
if (!empty($news['category_name'])) {
    $breadcrumbItems[] = [
        'name' => $news['category_name'],
        'url'  => categoryUrl($news['category_slug']),
    ];
}
$breadcrumbItems[] = ['name' => $news['title'], 'url' => newsUrl($news['slug'])];
renderJsonLd(buildBreadcrumbJsonLd($breadcrumbItems));
?>

<div class="container page-body">
<div class="layout-main">

    <!-- ===== ARTICLE ===== -->
    <article class="article" itemscope itemtype="https://schema.org/NewsArticle">

        <!-- Breadcrumb -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a>
            <?php if (!empty($news['category_name'])): ?>
            &rsaquo;
            <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endif; ?>
            &rsaquo; <span><?= htmlspecialchars(
                mb_strlen($news['title']) > 60
                    ? mb_substr($news['title'], 0, 60) . '...'
                    : $news['title'],
                ENT_QUOTES, 'UTF-8'
            ) ?></span>
        </nav>

        <!-- Header -->
        <header class="article__header">
            <?php if (!empty($news['category_name'])): ?>
            <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
               class="badge badge--red">
                <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endif; ?>

            <?php if ($news['is_breaking']): ?>
            <span class="badge badge--breaking">Breaking</span>
            <?php endif; ?>

            <h1 class="article__title" itemprop="headline">
                <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <div class="article__meta">
                <?php if (!empty($news['reporter_name'])): ?>
                <span class="article__meta-by">
                    By <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>"
                          itemprop="author">
                        <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </span>
                <?php endif; ?>
                <?php if (!empty($news['agency_name'])): ?>
                <span class="article__meta-agency">
                    via <a href="<?= htmlspecialchars(agencyUrl((int)$news['agency_id']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($news['agency_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </span>
                <?php endif; ?>
                <time class="article__date" itemprop="datePublished"
                      datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= formatDate($news['created_at'], 'F j, Y \a\t g:i A') ?>
                </time>
                <span class="article__read-time">
                    &#9201; <?= readingTime($news['content']) ?> min read
                </span>
            </div>
        </header>

        <!-- Social Share Bar -->
        <?php
        $shareUrl   = htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8');
        $shareTitle = htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="share-bar" aria-label="Share this article">
            <span class="share-bar__label">Share:</span>
            <a href="https://api.whatsapp.com/send?text=<?= rawurlencode($news['title'] . ' ' . newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--wa" target="_blank" rel="noopener noreferrer"
               aria-label="Share on WhatsApp">WhatsApp</a>
            <a href="https://twitter.com/intent/tweet?url=<?= rawurlencode(newsUrl($news['slug'])) ?>&text=<?= rawurlencode($news['title']) ?>"
               class="share-btn share-btn--tw" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Twitter">Twitter</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode(newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--fb" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Facebook">Facebook</a>
            <button class="share-btn share-btn--copy" data-url="<?= $shareUrl ?>"
                    aria-label="Copy link to clipboard">Copy Link</button>
        </div>
        <?php if (!empty($news['featured_image'])): ?>
        <figure class="article__hero">
            <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                 class="article__hero-img"
                 itemprop="image"
                 loading="lazy">
        </figure>
        <?php endif; ?>

        <!-- Full content (HTML stored in DB).
             SECURITY NOTE: article body is stored as HTML (rich text editor output).
             In production, sanitize HTML at write-time with a library such as HTML Purifier
             before storing it in the database to prevent stored XSS. -->
        <div class="article__body" itemprop="articleBody">
            <?= $news['content'] ?>
        </div>

        <!-- Reporter info card -->
        <?php if (!empty($news['reporter_name'])): ?>
        <div class="reporter-card">
            <?php
            // Use mediaUrl() to validate the filename (prevents path traversal)
            $reporterPhotoUrl = !empty($news['reporter_photo'])
                ? mediaUrl($news['reporter_photo'], 'reporters')
                : '';
            ?>
            <?php if ($reporterPhotoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($reporterPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>"
                 class="reporter-card__photo"
                 loading="lazy">
            <?php endif; ?>
            <div class="reporter-card__info">
                <h3 class="reporter-card__name">
                    <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </h3>
                <?php if (!empty($news['reporter_bio'])): ?>
                <p class="reporter-card__bio">
                    <?= htmlspecialchars($news['reporter_bio'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>"
                   class="reporter-card__more">More by this reporter &rarr;</a>
            </div>
        </div>
        <?php endif; ?>

    </article><!-- /.article -->

    <!-- ===== RELATED NEWS ===== -->
    <?php if (!empty($relatedNews)): ?>
    <section class="section" aria-labelledby="related-heading">
        <h2 class="section__title" id="related-heading">
            <span class="section__title-accent">Related</span> News
        </h2>
        <div class="news-grid news-grid--4col">
            <?php foreach ($relatedNews as $item): ?>
            <article class="news-card">
                <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="news-card__img-link">
                    <img src="<?= htmlspecialchars(newsImage($item['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img"
                         loading="lazy">
                </a>
                <div class="news-card__body">
                    <h3 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h3>
                    <time class="news-card__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($item['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <!-- ===== NEXT ARTICLE ===== -->
    <?php if ($nextArticle): ?>
    <div class="next-article-box">
        <span class="next-article-box__label">Next Article</span>
        <a href="<?= htmlspecialchars(newsUrl($nextArticle['slug']), ENT_QUOTES, 'UTF-8') ?>"
           class="next-article-box__link">
            <?php if (!empty($nextArticle['featured_image'])): ?>
            <img src="<?= htmlspecialchars(newsImage($nextArticle['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($nextArticle['title'], ENT_QUOTES, 'UTF-8') ?>"
                 class="next-article-box__img"
                 loading="lazy">
            <?php endif; ?>
            <div class="next-article-box__body">
                <p class="next-article-box__title">
                    <?= htmlspecialchars($nextArticle['title'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <span class="next-article-box__cta">Read now &rarr;</span>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.layout-main -->

<!-- ===== SIDEBAR ===== -->
<aside class="layout-sidebar" aria-label="Sidebar">
    <?php if (!empty($sideItems)): ?>
    <div class="widget">
        <h3 class="widget__title">Latest News</h3>
        <ul class="trending-list">
            <?php foreach ($sideItems as $i => $item): ?>
            <li class="trending-list__item">
                <span class="trending-list__num"><?= $i + 1 ?></span>
                <div class="trending-list__body">
                    <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="trending-list__link">
                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <time class="trending-list__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($item['created_at']) ?>
                    </time>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Categories widget -->
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
