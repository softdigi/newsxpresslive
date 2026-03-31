<?php
/**
 * Header Include
 * NewsXpressLive – dynamic navigation, search, breaking news ticker
 *
 * Expects $pdo (PDO) and optionally $seoMeta (array) to be defined
 * by the including page before this file is included.
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/subscription.php';

// Fetch categories for navigation menu (uses static cache inside getAllCategories)
$navCategories = getAllCategories($pdo);

// Fetch breaking news for ticker
$breakingStmt = $pdo->prepare(
    'SELECT title, slug FROM news WHERE status = :status AND is_breaking = 1
     ORDER BY created_at DESC LIMIT 8'
);
$breakingStmt->execute([':status' => 'published']);
$breakingNews = $breakingStmt->fetchAll();

// Determine current page URL for canonical / nav highlighting
$currentUrl = SITE_URL . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?php renderSeoMeta($seoMeta ?? []); ?>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= SITE_URL ?>/assets/img/favicon.ico">
    <!-- PWA: manifest + theme colour + home-screen icon -->
    <link rel="manifest" href="<?= SITE_URL ?>/manifest.json">
    <meta name="theme-color" content="#e50914">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/img/icon-192.png">
    <!-- Performance: Preload & Preconnect -->
    <link rel="preload" href="<?= SITE_URL ?>/assets/css/style.css" as="style">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="dns-prefetch" href="//www.google-analytics.com">
    <!-- Google News Sitemap -->
    <link rel="sitemap" type="application/xml" href="<?= SITE_URL ?>/news-sitemap.xml.php">
    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- ===== APP DOWNLOAD BANNER (Mobile Only) ===== -->
<div class="app-banner" id="appBanner">
    <span class="app-banner__icon">📰</span>
    <div class="app-banner__text">
        <div class="app-banner__title">Download NewsXpressLive App</div>
        <div class="app-banner__subtitle">Get breaking news alerts instantly!</div>
    </div>
    <a href="#play-store" class="app-banner__btn" id="appBannerLink">Install</a>
    <button class="app-banner__close" id="appBannerClose" aria-label="Close banner">&times;</button>
</div>

<!-- ===== SKIP NAVIGATION (keyboard / screen-reader accessibility) ===== -->
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ===== READING PROGRESS BAR (visible on article pages only, controlled by JS) ===== -->
<div class="reading-progress" id="readingProgress" role="progressbar"
     aria-label="Reading progress" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>

<!-- ===== BREAKING NEWS ALERT POPUP ===== -->
<div class="breaking-alert" id="breakingAlert">
    <div class="breaking-alert__inner">
        <span class="breaking-alert__icon">🚨</span>
        <div class="breaking-alert__content">
            <div class="breaking-alert__label">Breaking News</div>
            <div class="breaking-alert__title" id="breakingAlertTitle"></div>
        </div>
        <a href="#" class="breaking-alert__btn" id="breakingAlertLink">Read Now</a>
        <button class="breaking-alert__close" id="breakingAlertClose" aria-label="Dismiss">&times;</button>
    </div>
</div>

<!-- ===== TOP BAR ===== -->
<div class="topbar">
    <div class="container topbar__inner">
        <span class="topbar__date"><?= date('l, F j, Y') ?></span>
        <nav class="topbar__social" aria-label="Social media links">
            <!-- Language Toggle -->
            <button class="lang-toggle" id="langToggle" aria-label="Toggle language">
                <span class="lang-toggle__en">EN</span>
                <span class="lang-toggle__sep">|</span>
                <span class="lang-toggle__hi">हिं</span>
            </button>
            <!-- Dark Mode Toggle -->
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2"/><line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2"/><line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2"/>
                </svg>
            </button>
            <!-- Social Links with SVG Icons -->
            <a href="#" aria-label="Facebook" class="topbar__social-link">
                <svg class="topbar__social-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                </svg>
            </a>
            <a href="#" aria-label="Twitter" class="topbar__social-link">
                <svg class="topbar__social-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/>
                </svg>
            </a>
            <a href="#" aria-label="YouTube" class="topbar__social-link">
                <svg class="topbar__social-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="#fff"/>
                </svg>
            </a>
            <a href="#" aria-label="Instagram" class="topbar__social-link">
                <svg class="topbar__social-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" fill="none" stroke="#fff" stroke-width="1.5"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="#fff" stroke-width="2"/>
                </svg>
            </a>
            <a href="#" aria-label="Telegram" class="topbar__social-link">
                <svg class="topbar__social-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M21.198 2.433a2.242 2.242 0 0 0-1.022.215l-16.5 6.9a2.23 2.23 0 0 0 .126 4.142l3.89 1.26 1.517 4.856a1.5 1.5 0 0 0 2.5.567l2.2-2.2 4.32 3.2a2.25 2.25 0 0 0 3.5-1.314l3.396-15.5a2.25 2.25 0 0 0-3.927-2.126z"/>
                </svg>
            </a>
        </nav>
    </div>
</div>

<!-- ===== SITE HEADER ===== -->
<header class="site-header">
    <div class="container site-header__inner">
        <!-- Logo / Site Name -->
        <a href="<?= SITE_URL ?>/" class="site-header__logo">
            <span class="site-header__logo-text"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            <small class="site-header__tagline translatable" data-hi="ताज़ा खबर, लाइव अपडेट"><?= htmlspecialchars(SITE_TAGLINE, ENT_QUOTES, 'UTF-8') ?></small>
        </a>

        <!-- Search Form -->
        <form class="search-form" action="<?= SITE_URL ?>/news/search.php" method="get" role="search">
            <label for="site-search" class="sr-only">Search news</label>
            <input
                type="search"
                id="site-search"
                name="q"
                class="search-form__input"
                placeholder="Search news..."
                value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                maxlength="200"
                required
            >
            <button type="submit" class="search-form__btn" aria-label="Search">
                &#128269;
            </button>
        </form>

        <!-- Mobile menu toggle -->
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
        </button>

        <!-- Subscriber account pill -->
        <?php
        $_headerUser = getCurrentUser($pdo);
        if ($_headerUser && isSubscribed($_headerUser)): ?>
        <a href="<?= SITE_URL ?>/subscribe/account.php"
           style="font-size:.78rem;font-weight:700;color:#7c3aed;background:#f5f0ff;padding:4px 10px;border-radius:20px;text-decoration:none;white-space:nowrap;">
            ⭐ <?= htmlspecialchars($_headerUser['name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php elseif (!$_headerUser): ?>
        <a href="<?= SITE_URL ?>/subscribe/"
           style="font-size:.78rem;font-weight:700;color:#7c3aed;background:#f5f0ff;padding:4px 10px;border-radius:20px;text-decoration:none;white-space:nowrap;display:none;" class="premium-cta-pill">
            ⭐ Go Premium
        </a>
        <?php endif; ?>
    </div>
</header>

<!-- ===== MAIN NAVIGATION ===== -->
<nav class="main-nav" id="mainNav" aria-label="Main navigation">
    <div class="container">
        <ul class="main-nav__list">
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/" class="main-nav__link translatable" data-hi="मुख्य">Home</a>
            </li>
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/trending/" class="main-nav__link translatable" data-hi="ट्रेंडिंग">Trending</a>
            </li>
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/bookmarks/" class="main-nav__link">🔖 Bookmarks</a>
            </li>
            <?php foreach (array_slice($navCategories, 0, 6) as $cat): ?>
            <li class="main-nav__item">
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="main-nav__link">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/referral/" class="main-nav__link"
                   style="color:#f59e0b;font-weight:700;">🎁 Refer &amp; Earn</a>
            </li>
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/reels/" class="main-nav__link"
                   style="color:#e50914;font-weight:700;">🎬 Reels</a>
            </li>
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/subscribe/" class="main-nav__link"
                   style="color:#7c3aed;font-weight:700;">⭐ Premium</a>
            </li>
        </ul>
    </div>
</nav>

<!-- ===== BREAKING NEWS TICKER ===== -->
<?php if (!empty($breakingNews)): ?>
<div class="ticker" role="region" aria-label="Breaking news" aria-live="off">
    <div class="container ticker__inner">
        <span class="ticker__label translatable" data-hi="ब्रेकिंग" aria-hidden="true">BREAKING</span>
        <div class="ticker__track-wrapper">
            <ul class="ticker__track" id="breakingTicker">
                <?php foreach ($breakingNews as $item): ?>
                <li class="ticker__item">
                    <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="ticker__link">
                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== PUSH NOTIFICATION BAR ===== -->
<div class="push-notification-bar" id="pushNotificationBar">
    <div class="push-notification-bar__inner">
        <span class="push-notification-bar__icon">🔔</span>
        <span class="push-notification-bar__text">Get breaking news alerts</span>
        <button class="push-notification-bar__btn push-notification-bar__btn--enable" id="pushEnable">Enable</button>
        <button class="push-notification-bar__btn push-notification-bar__btn--later" id="pushLater">Later</button>
    </div>
</div>

<!-- ===== MAIN CONTENT wrapper starts below ===== -->
<main class="main-content" id="main-content">
