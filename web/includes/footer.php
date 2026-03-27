<?php
/**
 * Footer Include
 * NewsXpressLive
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/functions.php';

$footerCategories = getAllCategories($pdo);
?>
</main><!-- /.main-content -->

<!-- ===== SITE FOOTER ===== -->
<footer class="site-footer">
    <div class="container site-footer__inner">

        <!-- About column -->
        <div class="site-footer__col">
            <h3 class="site-footer__heading"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="site-footer__about">
                Your trusted source for breaking news, in-depth reports, and live updates from around the world.
            </p>
        </div>

        <!-- Categories column -->
        <div class="site-footer__col">
            <h3 class="site-footer__heading">Categories</h3>
            <ul class="site-footer__links">
                <?php foreach ($footerCategories as $cat): ?>
                <li>
                    <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Quick Links column -->
        <div class="site-footer__col">
            <h3 class="site-footer__heading">Quick Links</h3>
            <ul class="site-footer__links">
                <li><a href="<?= SITE_URL ?>/">Home</a></li>
                <li><a href="<?= SITE_URL ?>/news/search.php">Search</a></li>
                <li><a href="#">About Us</a></li>
                <li><a href="#">Contact</a></li>
                <li><a href="#">Privacy Policy</a></li>
            </ul>
        </div>

    </div>
    <div class="site-footer__bottom">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- ===== BACK TO TOP ===== -->
<button class="back-to-top" id="backToTop" aria-label="Back to top" title="Back to top">&#8679;</button>

<!-- ===== SCRIPTS ===== -->
<script src="<?= SITE_URL ?>/assets/js/app.js" defer></script>
</body>
</html>
