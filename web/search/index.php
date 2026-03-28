<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/seo.php';

$q = trim($_GET['q'] ?? '');
// SECURITY: Limit query length to prevent DoS and sanitize
$q = mb_substr(strip_tags($q), 0, 200);
$results = [];

if ($q !== '') {
    $stmt = $pdo->prepare("
        SELECT * FROM news
        WHERE status='approved'
        AND (title LIKE :q OR description LIKE :q)
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute(['q' => "%$q%"]);
    $results = $stmt->fetchAll();
}

// SECURITY: Escape $q in page_title to prevent XSS
$page_title = $q
    ? "Search results for \"" . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . "\" - News Xpress Live"
    : "Search News - News Xpress Live";

$page_description = "Search latest news articles on News Xpress Live.";
$canonical_url = SITE_URL."/newsxpresslive_api/web/search?q=".urlencode($q);

include __DIR__.'/../includes/header.php';
?>

<div class="container">
    <h1 class="page-title">🔍 Search Results</h1>

    <form class="search-form" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search news..." required>
        <button type="submit">Search</button>
    </form>

    <?php if ($q && !$results): ?>
        <p class="no-results">No news found for <strong><?= htmlspecialchars($q) ?></strong></p>
    <?php endif; ?>

    <div class="news-grid">
        <?php foreach ($results as $news): ?>
            <article class="news-card">
                <?php if ($news['featured_image']): ?>
                    <img src="<?= SITE_URL ?>/uploads/news/images/<?= htmlspecialchars($news['featured_image']) ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                <?php endif; ?>

                <div class="news-content">
                    <h3>
                        <a href="<?= SITE_URL ?>/newsxpresslive_api/web/news/<?= generateSlug($news['title']) ?>-<?= $news['id'] ?>">
                            <?= highlightKeyword($news['title'], $q) ?>
                        </a>
                    </h3>
                    <p><?= highlightKeyword(substr(strip_tags($news['description']),0,140), $q) ?>...</p>

                    <div class="news-meta">
                        <span><?= timeAgo($news['created_at']) ?></span>
                        <span><?= formatViews($news['id']) ?> views</span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- App CTA -->
    <div class="app-cta-inline">
        <p>📱 Read full stories faster on our app</p>
        <a href="<?= defined('APP_DOWNLOAD_LINK') ? APP_DOWNLOAD_LINK : '#' ?>" class="btn-download">Download App</a>
    </div>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
