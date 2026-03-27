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
    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- ===== SKIP NAVIGATION (keyboard / screen-reader accessibility) ===== -->
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ===== TOP BAR ===== -->
<div class="topbar">
    <div class="container topbar__inner">
        <span class="topbar__date"><?= date('l, F j, Y') ?></span>
        <nav class="topbar__social" aria-label="Social media links">
            <a href="#" aria-label="Facebook" class="topbar__social-link">FB</a>
            <a href="#" aria-label="Twitter"  class="topbar__social-link">TW</a>
            <a href="#" aria-label="YouTube"  class="topbar__social-link">YT</a>
        </nav>
    </div>
</div>

<!-- ===== SITE HEADER ===== -->
<header class="site-header">
    <div class="container site-header__inner">
        <!-- Logo / Site Name -->
        <a href="<?= SITE_URL ?>/" class="site-header__logo">
            <span class="site-header__logo-text"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            <small class="site-header__tagline"><?= htmlspecialchars(SITE_TAGLINE, ENT_QUOTES, 'UTF-8') ?></small>
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
    </div>
</header>

<!-- ===== MAIN NAVIGATION ===== -->
<nav class="main-nav" id="mainNav" aria-label="Main navigation">
    <div class="container">
        <ul class="main-nav__list">
            <li class="main-nav__item">
                <a href="<?= SITE_URL ?>/" class="main-nav__link">Home</a>
            </li>
            <?php foreach ($navCategories as $cat): ?>
            <li class="main-nav__item">
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="main-nav__link">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>

<!-- ===== BREAKING NEWS TICKER ===== -->
<?php if (!empty($breakingNews)): ?>
<div class="ticker" role="region" aria-label="Breaking news" aria-live="off">
    <div class="container ticker__inner">
        <span class="ticker__label" aria-hidden="true">BREAKING</span>
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

<!-- ===== MAIN CONTENT wrapper starts below ===== -->
<main class="main-content" id="main-content">
