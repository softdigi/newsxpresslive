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
            n.views,
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

/* ── Increment view count ───────────────────────────────────────────── */
try {
    $pdo->prepare('UPDATE news SET views = COALESCE(views, 0) + 1 WHERE id = ?')->execute([$news['id']]);
} catch (PDOException $e) {
    // views column might not exist - try to add it
    if (strpos($e->getMessage(), 'views') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $pdo->exec('ALTER TABLE news ADD COLUMN views INT DEFAULT 0');
            $pdo->prepare('UPDATE news SET views = 1 WHERE id = ?')->execute([$news['id']]);
        } catch (PDOException $e2) {
            // Silently ignore
        }
    }
}

/* ── Get article tags ───────────────────────────────────────────────── */
$articleTags = [];
try {
    $tagsStmt = $pdo->prepare(
        'SELECT t.name, t.slug FROM tags t 
         INNER JOIN news_tags nt ON nt.tag_id = t.id 
         WHERE nt.news_id = :news_id'
    );
    $tagsStmt->execute([':news_id' => $news['id']]);
    $articleTags = $tagsStmt->fetchAll();
} catch (PDOException $e) {
    // Tables might not exist
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

/* ── Extract Key Points ─────────────────────────────────────────────── */
$keyPoints = extractKeyPoints($news['content'], 3);

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
    <article class="article" itemscope itemtype="https://schema.org/NewsArticle" data-article-id="<?= (int)$news['id'] ?>">

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
            <span class="badge badge--breaking translatable" data-hi="ब्रेकिंग">Breaking</span>
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
                <?php if (!empty($news['views']) && $news['views'] > 0): ?>
                <span class="article__views">
                    👁 <?= formatViews((int)$news['views']) ?> views
                </span>
                <?php endif; ?>
            </div>
        </header>

        <!-- Social Share Bar + Bookmark -->
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
            <a href="https://t.me/share/url?url=<?= rawurlencode(newsUrl($news['slug'])) ?>&text=<?= rawurlencode($news['title']) ?>"
               class="share-btn share-btn--tg" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Telegram">Telegram</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--ln" target="_blank" rel="noopener noreferrer"
               aria-label="Share on LinkedIn">LinkedIn</a>
            <button class="share-btn share-btn--copy" data-url="<?= $shareUrl ?>"
                    aria-label="Copy link to clipboard">Copy Link</button>
            <button class="bookmark-btn" id="bookmarkBtn" 
                    data-id="<?= (int)$news['id'] ?>"
                    data-title="<?= $shareTitle ?>"
                    data-slug="<?= htmlspecialchars($news['slug'], ENT_QUOTES, 'UTF-8') ?>"
                    data-image="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                    data-date="<?= formatDate($news['created_at']) ?>">
                <span class="bookmark-btn__icon">🔖</span> Bookmark
            </button>
        </div>

        <!-- Key Points Summary -->
        <?php if (!empty($keyPoints)): ?>
        <div class="key-points">
            <h3 class="key-points__title">📌 Key Points</h3>
            <ul class="key-points__list">
                <?php foreach ($keyPoints as $point): ?>
                <li class="key-points__item"><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

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

        <!-- Tags Section -->
        <?php if (!empty($articleTags)): ?>
        <div class="article-tags">
            <h4 class="article-tags__title">🏷️ Tags</h4>
            <div class="article-tags__list">
                <?php foreach ($articleTags as $tag): ?>
                <a href="<?= htmlspecialchars(tagUrl($tag['slug']), ENT_QUOTES, 'UTF-8') ?>" 
                   class="tag-badge">
                    <?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

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
        <h3 class="widget__title translatable" data-hi="ताज़ा खबर">Latest News</h3>
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
        <h3 class="widget__title translatable" data-hi="श्रेणियाँ">Categories</h3>
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
