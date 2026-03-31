<?php
/**
 * Personalized News Feed
 * NewsXpressLive
 *
 * Shows articles ranked by the current visitor's interest profile
 * (weighted category/tag scoring) combined with a collaborative
 * filtering "users like you also read" section.
 *
 * Falls back to recency when no behavior data exists yet.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/personalization.php';

$sessionId          = getSessionId();
$personalizedArticles = getPersonalizedFeed($pdo, $sessionId, 12);
$collaborativeArticles = getCollaborativeArticles($pdo, $sessionId, 6);

$seoMeta = [
    'title'       => 'Your Personalized News Feed',
    'description' => 'News articles ranked by your interests on ' . SITE_NAME,
    'url'         => SITE_URL . '/news/feed.php',
    'type'        => 'website',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <!-- ===== PERSONALIZED FEED ===== -->
    <section class="section" aria-label="Your personalized news feed">
        <div class="section-header">
            <h1 class="section-title">🎯 For You</h1>
            <p class="section-subtitle" style="font-size:.85rem;color:#888;margin-top:4px;">
                Ranked by your interests &amp; reading habits
            </p>
        </div>

        <?php if (empty($personalizedArticles)): ?>
        <p style="text-align:center;padding:2rem;color:#888;">
            Start reading a few articles and your personalised feed will appear here.
        </p>
        <?php else: ?>
        <div class="news-grid">
            <?php foreach ($personalizedArticles as $article): ?>
            <article class="news-card">
                <?php if (!empty($article['featured_image'])): ?>
                <a href="<?= htmlspecialchars(newsUrl($article['slug']), ENT_QUOTES, 'UTF-8') ?>" class="news-card__img-wrap" tabindex="-1" aria-hidden="true">
                    <img src="<?= htmlspecialchars(newsImage($article['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img" loading="lazy">
                </a>
                <?php endif; ?>
                <div class="news-card__body">
                    <?php if (!empty($article['category_name'])): ?>
                    <a href="<?= htmlspecialchars(categoryUrl($article['category_slug']), ENT_QUOTES, 'UTF-8') ?>" class="news-card__category">
                        <?= htmlspecialchars($article['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php endif; ?>
                    <h2 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($article['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h2>
                    <p class="news-card__excerpt"><?= htmlspecialchars(excerpt($article['content'], 100), ENT_QUOTES, 'UTF-8') ?></p>
                    <time class="news-card__time" datetime="<?= htmlspecialchars($article['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= timeAgo($article['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($collaborativeArticles)): ?>
    <!-- ===== COLLABORATIVE "OTHERS ALSO READ" ===== -->
    <section class="section" aria-label="Readers like you also read">
        <div class="section-header">
            <h2 class="section-title">👥 Readers Like You Also Read</h2>
        </div>
        <div class="news-grid news-grid--small">
            <?php foreach ($collaborativeArticles as $article): ?>
            <article class="news-card news-card--compact">
                <?php if (!empty($article['featured_image'])): ?>
                <a href="<?= htmlspecialchars(newsUrl($article['slug']), ENT_QUOTES, 'UTF-8') ?>" class="news-card__img-wrap" tabindex="-1" aria-hidden="true">
                    <img src="<?= htmlspecialchars(newsImage($article['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img" loading="lazy">
                </a>
                <?php endif; ?>
                <div class="news-card__body">
                    <?php if (!empty($article['category_name'])): ?>
                    <a href="<?= htmlspecialchars(categoryUrl($article['category_slug']), ENT_QUOTES, 'UTF-8') ?>" class="news-card__category">
                        <?= htmlspecialchars($article['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php endif; ?>
                    <h3 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($article['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h3>
                    <time class="news-card__time" datetime="<?= htmlspecialchars($article['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= timeAgo($article['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /.layout-main -->

<?php
/* ── Sidebar ─────────────────────────────────────────────────────── */
$sideStmt = $pdo->prepare(
    'SELECT title, slug, created_at FROM news
     WHERE status = :status ORDER BY created_at DESC LIMIT 6'
);
$sideStmt->execute([':status' => 'published']);
$sideItems = $sideStmt->fetchAll();
?>

<aside class="layout-sidebar">
    <?php if (!empty($sideItems)): ?>
    <div class="sidebar-widget">
        <h3 class="sidebar-widget__title">🔥 Trending</h3>
        <ul class="sidebar-list">
            <?php foreach ($sideItems as $item): ?>
            <li class="sidebar-list__item">
                <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <time class="sidebar-list__time"><?= timeAgo($item['created_at']) ?></time>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</aside>

</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
