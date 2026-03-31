<?php
/**
 * Homepage
 * NewsXpressLive
 *
 * Sections:
 * 1. Breaking / featured news slider (top 5)
 * 2. Latest news grid (9 items)
 * 3. Category-wise sections (loop all categories, show 4 news each)
 * 4. Sidebar (trending – most recent 6 items)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

/* ── 1. Breaking / featured news (slider) ──────────────────────────── */
$featuredStmt = $pdo->prepare(
    'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = :status AND n.is_breaking = 1
     ORDER BY n.created_at DESC
     LIMIT 5'
);
$featuredStmt->execute([':status' => 'published']);
$featuredNews = $featuredStmt->fetchAll();

/* ── 2. Latest news grid (9 items) ─────────────────────────────────── */
$latestStmt = $pdo->prepare(
    'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at, n.views,
            c.name AS category_name, c.slug AS category_slug
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = :status
     ORDER BY n.created_at DESC
     LIMIT 9'
);
$latestStmt->execute([':status' => 'published']);
$latestNews = $latestStmt->fetchAll();

/* ── 3. Categories with their latest 4 news each ───────────────────── */
$allCategories = getAllCategories($pdo);
$categoryNews  = [];
if (!empty($allCategories)) {
    // Build a single query for all categories at once to avoid N+1 queries.
    // We cap at 4 results per category at the PHP level after the fetch, but
    // we also add a LIMIT at SQL level so the DB never returns more than
    // 4 × number-of-categories rows (significantly faster on large tables).
    $catIds       = array_column($allCategories, 'id');
    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
    $sqlLimit     = count($catIds) * 4; // max rows we could ever use

    $catNewsStmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                n.created_at, n.category_id,
                c.name AS category_name, c.slug AS category_slug
         FROM news n
         INNER JOIN categories c ON c.id = n.category_id
         WHERE n.status = 'published'
           AND n.category_id IN ($placeholders)
         ORDER BY n.category_id, n.created_at DESC
         LIMIT $sqlLimit"
    );
    $catNewsStmt->execute($catIds);
    $allCatNews = $catNewsStmt->fetchAll();

    // Group and limit to 4 per category
    foreach ($allCatNews as $row) {
        $cid = $row['category_id'];
        if (!isset($categoryNews[$cid])) {
            $categoryNews[$cid] = [];
        }
        if (count($categoryNews[$cid]) < 4) {
            $categoryNews[$cid][] = $row;
        }
    }
}

/* ── 4. Sidebar – SMART TRENDING (time-decay score) ────────────────── */
// Score = 1000 / (hours_since_published + 2), so newer articles rank higher.
// This mimics the Hacker News time-decay algorithm without needing a views counter.
$trendingStmt = $pdo->prepare(
    'SELECT n.title, n.slug, n.featured_image, n.created_at,
            c.name AS category_name,
            ROUND(1000 / (TIMESTAMPDIFF(HOUR, n.created_at, NOW()) + 2), 4) AS trend_score
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status = :status
     ORDER BY trend_score DESC
     LIMIT 6'
);
$trendingStmt->execute([':status' => 'published']);
$trendingNews = $trendingStmt->fetchAll();

/* ── 5. "For You" – session-based personalisation ───────────────────── */
// Populated when the user has visited at least one category page.
// Tracks $_SESSION['pref_cats'][ category_id ] = visit_count.
$forYouNews     = [];
$forYouCatName  = '';
if (!empty($_SESSION['pref_cats']) && is_array($_SESSION['pref_cats'])) {
    arsort($_SESSION['pref_cats']);                  // highest visit count first
    $topCatId = (int)array_key_first($_SESSION['pref_cats']);
    if ($topCatId > 0) {
        $fyStmt = $pdo->prepare(
            'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                    c.name AS category_name, c.slug AS category_slug
             FROM news n
             INNER JOIN categories c ON c.id = n.category_id
             WHERE n.status = :status AND n.category_id = :cat_id
             ORDER BY n.created_at DESC
             LIMIT 4'
        );
        $fyStmt->execute([':status' => 'published', ':cat_id' => $topCatId]);
        $forYouNews    = $fyStmt->fetchAll();
        $forYouCatName = $forYouNews[0]['category_name'] ?? '';
    }
}

/* ── SEO meta ───────────────────────────────────────────────────────── */
// Use titleFull so renderSeoMeta() does NOT append "| SITE_NAME" again,
// which would produce "NewsXpressLive – Tagline | NewsXpressLive".
$seoMeta = [
    'titleFull'   => SITE_NAME . ' | ' . SITE_TAGLINE,
    'description' => 'Latest breaking news, top stories and live updates from ' . SITE_NAME,
    'url'         => SITE_URL . '/',
    'type'        => 'website',
];

require_once __DIR__ . '/includes/header.php';

// ── WebSite + SiteLinksSearchBox JSON-LD ────────────────────────────
renderJsonLd(buildWebSiteJsonLd());
?>

<div class="container page-body">
<div class="layout-main">

    <!-- ===== BREAKING NEWS HERO SLIDER ===== -->
    <?php if (!empty($featuredNews)): ?>
    <section class="hero-slider section" aria-label="Featured breaking news">
        <div class="hero-slider__wrapper" id="heroSlider">
            <?php foreach ($featuredNews as $i => $news): ?>
            <article class="hero-slide<?= $i === 0 ? ' hero-slide--active' : '' ?>"
                     aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
                <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="hero-slide__img-wrap">
                        <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                             class="hero-slide__img"
                             <?php if ($i === 0): ?>
                             loading="eager"
                             fetchpriority="high"
                             <?php else: ?>
                             loading="lazy"
                             <?php endif; ?>>
                    </div>
                    <div class="hero-slide__caption">
                        <?php if (!empty($news['category_name'])): ?>
                        <span class="badge badge--red">
                            <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                        <h2 class="hero-slide__title">
                            <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                        <p class="hero-slide__excerpt">
                            <?= htmlspecialchars(excerpt($news['content'], 120), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <time class="hero-slide__date" datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= formatDate($news['created_at']) ?>
                        </time>
                    </div>
                </a>
            </article>
            <?php endforeach; ?>
        </div>
        <!-- Slider controls -->
        <button class="hero-slider__btn hero-slider__btn--prev" id="heroPrev" aria-label="Previous slide">&#10094;</button>
        <button class="hero-slider__btn hero-slider__btn--next" id="heroNext" aria-label="Next slide">&#10095;</button>
        <div class="hero-slider__dots" id="heroDots" aria-label="Slide indicators">
            <?php foreach ($featuredNews as $i => $dummy): ?>
            <button class="hero-slider__dot<?= $i === 0 ? ' hero-slider__dot--active' : '' ?>"
                    data-slide="<?= $i ?>" aria-label="Go to slide <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== LATEST NEWS GRID ===== -->
    <?php if (!empty($latestNews)): ?>
    <section class="section" aria-labelledby="latest-heading">
        <h2 class="section__title" id="latest-heading">
            <span class="section__title-accent translatable" data-hi="ताज़ा">Latest</span> <span class="translatable" data-hi="खबर">News</span>
        </h2>
        <div class="news-grid">
            <?php foreach ($latestNews as $news): ?>
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
                    <div class="news-card__footer">
                        <time class="news-card__date" datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= formatDate($news['created_at']) ?>
                        </time>
                        <span class="news-card__read-time"><?= readingTime($news['content']) ?> min read</span>
                        <?php if (!empty($news['views']) && $news['views'] > 0): ?>
                        <span class="news-card__views">👁 <?= formatViews((int)$news['views']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        
        <!-- Load More Button -->
        <div class="load-more-wrap">
            <button class="load-more-btn" id="loadMoreBtn">
                <span class="load-more-btn__text">Load More News</span>
                <span class="load-more-btn__spinner"></span>
            </button>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== "FOR YOU" PERSONALISED SECTION ===== -->
    <?php if (!empty($forYouNews)): ?>
    <section class="section for-you-section" aria-labelledby="for-you-heading">
        <h2 class="section__title" id="for-you-heading">
            <span class="section__title-accent translatable" data-hi="आपके लिए">For You</span>
            <span class="for-you-badge">✦ Personalised</span>
            <?php if (!empty($forYouCatName)): ?>
            <a href="<?= htmlspecialchars(categoryUrl($forYouNews[0]['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
               class="section__view-all">More <?= htmlspecialchars($forYouCatName, ENT_QUOTES, 'UTF-8') ?> &rarr;</a>
            <?php endif; ?>
        </h2>
        <div class="news-grid news-grid--4col">
            <?php foreach ($forYouNews as $item): ?>
            <article class="news-card news-card--fy">
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
                    <p class="news-card__excerpt">
                        <?= htmlspecialchars(excerpt($item['content'], 80), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="news-card__footer">
                        <time class="news-card__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= formatDate($item['created_at']) ?>
                        </time>
                        <span class="news-card__read-time"><?= readingTime($item['content']) ?> min read</span>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== TRENDING IN YOUR AREA (Local News) ===== -->
    <section class="local-news-section" id="localNewsSection" aria-labelledby="local-heading">
        <div class="local-news__header">
            <span class="local-news__icon">📍</span>
            <h2 class="local-news__title" id="local-heading">Local News</h2>
        </div>
        <p class="local-news__message">Enable location to see news from your area.</p>
        <button class="local-news__enable" id="localNewsEnable">
            📍 Enable Location
        </button>
        <div class="news-grid news-grid--4col" id="localNewsGrid"></div>
    </section>

    <!-- ===== CITIZEN REPORTER CTA ===== -->
    <div class="citizen-reporter-cta">
        <h2 class="citizen-reporter-cta__title">📰 Become a Citizen Reporter</h2>
        <p class="citizen-reporter-cta__subtitle">
            Share stories that matter. Join 10,000+ reporters already on NewsXpressLive.
        </p>
        <a href="<?= SITE_URL ?>/agency/register.php" class="citizen-reporter-cta__btn">
            Start Reporting Now →
        </a>
        <div class="citizen-reporter-cta__stats">
            <span>📰 50,000+ Stories Published</span>
            <span>🌍 150+ Countries</span>
            <span>⚡ Real-time Updates</span>
        </div>
    </div>

    <!-- ===== CATEGORY-WISE SECTIONS ===== -->
    <?php foreach ($allCategories as $cat): ?>
        <?php
        $cid  = $cat['id'];
        $news = $categoryNews[$cid] ?? [];
        if (empty($news)) continue;
        ?>
        <section class="section" aria-labelledby="cat-heading-<?= (int)$cid ?>">
            <h2 class="section__title" id="cat-heading-<?= (int)$cid ?>">
                <span class="section__title-accent">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="section__view-all">View All &rarr;</a>
            </h2>
            <div class="news-grid news-grid--4col">
                <?php foreach ($news as $item): ?>
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
                        <p class="news-card__excerpt">
                            <?= htmlspecialchars(excerpt($item['content'], 80), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <time class="news-card__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= formatDate($item['created_at']) ?>
                        </time>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

</div><!-- /.layout-main -->

<!-- ===== SIDEBAR ===== -->
<aside class="layout-sidebar" aria-label="Sidebar">

    <!-- Trending News widget -->
    <?php if (!empty($trendingNews)): ?>
    <div class="widget">
        <h3 class="widget__title">Trending Now</h3>
        <ul class="trending-list">
            <?php foreach ($trendingNews as $i => $item): ?>
            <li class="trending-list__item">
                <span class="trending-list__num"><?= $i + 1 ?></span>
                <div class="trending-list__body">
                    <?php if (!empty($item['category_name'])): ?>
                    <span class="badge badge--small">
                        <?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
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
    <?php if (!empty($allCategories)): ?>
    <div class="widget">
        <h3 class="widget__title">Categories</h3>
        <ul class="cat-list">
            <?php foreach ($allCategories as $cat): ?>
            <li>
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="cat-list__link">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

</aside><!-- /.layout-sidebar -->
</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
