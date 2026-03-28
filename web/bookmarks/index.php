<?php
/**
 * Bookmarks Page
 * NewsXpressLive
 * 
 * Displays user's bookmarked articles (localStorage-based)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$seoMeta = [
    'title'       => 'My Bookmarks',
    'description' => 'Your saved articles on ' . SITE_NAME,
    'url'         => SITE_URL . '/bookmarks/',
    'robots'      => 'noindex,nofollow',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="bookmarks-heading">
        <h1 class="section__title" id="bookmarks-heading">
            <span class="section__title-accent">🔖 My</span> Bookmarks
        </h1>
        
        <p style="color: var(--color-gray); margin-bottom: 1.5rem;">
            Your bookmarked articles are saved locally on this device.
        </p>
        
        <!-- JS will populate this container -->
        <div id="bookmarksContainer">
            <div class="bookmarks-empty">
                <div class="bookmarks-empty__icon">🔖</div>
                <h2 class="bookmarks-empty__title">Loading...</h2>
                <p class="bookmarks-empty__text">Please wait while we load your bookmarks.</p>
            </div>
        </div>
        
    </section>

</div><!-- /.layout-main -->

<!-- Sidebar -->
<aside class="layout-sidebar" aria-label="Sidebar">
    <div class="widget">
        <h3 class="widget__title">About Bookmarks</h3>
        <div style="padding: 1rem; font-size: 0.85rem; color: var(--color-gray);">
            <p>📌 Bookmarks are saved in your browser's local storage.</p>
            <p style="margin-top: 0.5rem;">💡 They will persist until you clear your browser data.</p>
            <p style="margin-top: 0.5rem;">📱 Download our app for cloud-synced bookmarks across all devices!</p>
        </div>
    </div>
    
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
