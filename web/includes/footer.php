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

<!-- ===== NEWSLETTER SECTION ===== -->
<section class="newsletter-section">
    <div class="newsletter__inner">
        <h2 class="newsletter__title">📬 Stay Updated</h2>
        <p class="newsletter__subtitle">Get the latest news delivered straight to your inbox. No spam, just news that matters.</p>
        <form class="newsletter__form" id="newsletterForm">
            <input type="email" 
                   class="newsletter__input" 
                   id="newsletterEmail" 
                   placeholder="Enter your email address" 
                   required>
            <button type="submit" class="newsletter__btn" id="newsletterBtn">Subscribe</button>
        </form>
        <p class="newsletter__social-proof">Join 50,000+ readers who trust NewsXpressLive</p>
        <div class="newsletter__message" id="newsletterMessage"></div>
    </div>
</section>

<!-- ===== SITE FOOTER ===== -->
<footer class="site-footer">
    <div class="container site-footer__inner">

        <!-- About column -->
        <div class="site-footer__col">
            <h3 class="site-footer__heading"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="site-footer__about">
                Your trusted source for breaking news, in-depth reports, and live updates from around the world.
            </p>
            <!-- Footer Social Links -->
            <div style="display:flex;gap:0.75rem;margin-top:1rem;">
                <a href="#" aria-label="Facebook" style="color:#aaa;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
                <a href="#" aria-label="Twitter" style="color:#aaa;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
                </a>
                <a href="#" aria-label="YouTube" style="color:#aaa;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="#1a1a1a"/></svg>
                </a>
                <a href="#" aria-label="Instagram" style="color:#aaa;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" fill="#1a1a1a" stroke="currentColor" stroke-width="1.5"/></svg>
                </a>
                <a href="#" aria-label="Telegram" style="color:#aaa;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M21.198 2.433a2.242 2.242 0 0 0-1.022.215l-16.5 6.9a2.23 2.23 0 0 0 .126 4.142l3.89 1.26 1.517 4.856a1.5 1.5 0 0 0 2.5.567l2.2-2.2 4.32 3.2a2.25 2.25 0 0 0 3.5-1.314l3.396-15.5a2.25 2.25 0 0 0-3.927-2.126z"/></svg>
                </a>
            </div>
        </div>

        <!-- Categories column -->
        <div class="site-footer__col">
            <h3 class="site-footer__heading translatable" data-hi="श्रेणियाँ">Categories</h3>
            <ul class="site-footer__links">
                <?php foreach (array_slice($footerCategories, 0, 8) as $cat): ?>
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
                <li><a href="<?= SITE_URL ?>/" class="translatable" data-hi="मुख्य">Home</a></li>
                <li><a href="<?= SITE_URL ?>/trending/" class="translatable" data-hi="ट्रेंडिंग">Trending</a></li>
                <li><a href="<?= SITE_URL ?>/bookmarks/">🔖 Bookmarks</a></li>
                <li><a href="<?= SITE_URL ?>/news/search.php">Search</a></li>
                <li><a href="<?= SITE_URL ?>/agency/register.php">📰 Join as Agency</a></li>
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
