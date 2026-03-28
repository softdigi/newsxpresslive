<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/seo.php';

$stmt = $pdo->query("
    SELECT * FROM news
    WHERE status='approved'
    ORDER BY views DESC
    LIMIT 20
");

$trending = $stmt->fetchAll();

$page_title = "Trending News - News Xpress Live";
$page_description = "Most read and trending news articles on News Xpress Live.";
$canonical_url = SITE_URL."/newsxpresslive_api/web/trending";

include __DIR__.'/../includes/header.php';
?>

<div class="container">
    <h1 class="page-title">🔥 Trending News</h1>

    <div class="news-grid">
        <?php foreach ($trending as $news): ?>
            <article class="news-card trending-card">
                <?php if ($news['featured_image']): ?>
                    <img src="<?= SITE_URL ?>/uploads/news/images/<?= htmlspecialchars($news['featured_image']) ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                <?php endif; ?>

                <div class="news-content">
                    <span class="trending-badge">🔥 TRENDING</span>

                    <h3>
                        <a href="<?= SITE_URL ?>/newsxpresslive_api/web/news/<?= generateSlug($news['title']) ?>-<?= $news['id'] ?>">
                            <?= htmlspecialchars($news['title']) ?>
                        </a>
                    </h3>

                    <div class="news-meta">
                        <span><?= formatViews($news['views']) ?> views</span>
                        <span><?= timeAgo($news['created_at']) ?></span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="app-cta-inline">
        <p>📱 Trending news updates faster on app</p>
        <a href="<?= defined('APP_DOWNLOAD_LINK') ? APP_DOWNLOAD_LINK : '#' ?>" class="btn-download">Download App</a>
    </div>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
