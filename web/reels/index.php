<?php
/**
 * web/reels/index.php
 * Full-screen vertical video news reel feed.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Resolve API base (same origin, relative)
$apiBase = SITE_URL . '/api';

$seoMeta = [
    'title'       => 'Reels – ' . SITE_NAME,
    'description' => 'Short video news reels. Swipe through the latest stories.',
    'url'         => SITE_URL . '/reels/',
    'type'        => 'website',
];

// Inline the essential head elements without pulling in the global header/footer layout
// (the global header/footer are injected but hidden via CSS on this page).
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <title><?= htmlspecialchars($seoMeta['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($seoMeta['description'], ENT_QUOTES, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($seoMeta['url'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Open Graph -->
    <meta property="og:title"       content="<?= htmlspecialchars($seoMeta['title'], ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoMeta['description'], ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($seoMeta['url'], ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type"        content="website">

    <!-- Icons / manifest -->
    <link rel="icon"       type="image/x-icon" href="<?= SITE_URL ?>/assets/img/favicon.ico">
    <link rel="manifest"   href="<?= SITE_URL ?>/manifest.json">

    <!-- Styles -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/reels.css">

    <style>
        /* Keep body/html flush for full-screen reel */
        html, body { margin:0; padding:0; background:#000; overflow:hidden; height:100%; }
    </style>
</head>
<body class="reels-page">

<!-- ── Top bar ──────────────────────────────────────────────── -->
<div class="reels-topbar">
    <a href="<?= SITE_URL ?>/" class="reels-topbar__logo">
        📺 <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <button class="reels-topbar__close" onclick="history.back()" aria-label="Close reels">✕</button>
</div>

<!-- ── Global mute button ───────────────────────────────────── -->
<button id="reel-global-mute" class="reel-mute-btn" aria-label="Toggle mute" style="top:3.5rem;">🔇</button>

<!-- ── Reel feed container ──────────────────────────────────── -->
<div id="reels-feed"></div>

<!-- ── Loading / empty states ───────────────────────────────── -->
<div id="reels-loader" class="reel-loader">
    <div class="reel-spinner"></div>
    <span>Loading reels…</span>
</div>
<div id="reels-empty" class="reel-empty" style="display:none;">
    <span style="font-size:2.5rem;">🎬</span>
    <span>No reels available yet.</span>
    <a href="<?= SITE_URL ?>/" style="color:var(--color-primary,#e50914);font-weight:700;text-decoration:none;margin-top:.5rem;">← Back to News</a>
</div>

<!-- ── Comment drawer ───────────────────────────────────────── -->
<div id="reel-comments-drawer" class="reel-comments-drawer" role="dialog" aria-label="Comments">
    <div class="reel-comments-drawer__handle"></div>
    <div class="reel-comments-drawer__title">
        Comments
        <button id="reel-comments-close"
                style="position:absolute;right:1rem;background:none;border:none;color:#888;font-size:1.1rem;cursor:pointer;top:1rem;"
                aria-label="Close comments">✕</button>
    </div>
    <div id="reel-comment-list" class="reel-comments-drawer__list"></div>
    <form id="reel-comment-form" class="reel-comments-drawer__form" autocomplete="off">
        <input id="reel-comment-name"
               class="reel-comments-drawer__input"
               type="text" placeholder="Your name" maxlength="80" required
               style="max-width:90px;flex:0 0 90px;">
        <input id="reel-comment-input"
               class="reel-comments-drawer__input"
               type="text" placeholder="Add a comment…" maxlength="1000" required>
        <button type="submit" class="reel-comments-drawer__submit">Post</button>
    </form>
</div>

<!-- ── Share overlay ────────────────────────────────────────── -->
<div id="reel-share-overlay" class="reel-share-overlay" role="dialog" aria-label="Share">
    <button id="reel-share-close"
            style="position:absolute;right:1rem;top:1rem;background:none;border:none;color:#888;font-size:1.1rem;cursor:pointer;"
            aria-label="Close share">✕</button>
    <h3>Share this reel</h3>
    <div class="reel-share-grid">
        <a id="share-wa" href="#" target="_blank" rel="noopener" class="reel-share-btn">
            <span class="reel-share-icon" style="background:#25D366;">💬</span>
            WhatsApp
        </a>
        <a id="share-tw" href="#" target="_blank" rel="noopener" class="reel-share-btn">
            <span class="reel-share-icon" style="background:#1DA1F2;">🐦</span>
            Twitter
        </a>
        <a id="share-fb" href="#" target="_blank" rel="noopener" class="reel-share-btn">
            <span class="reel-share-icon" style="background:#1877F2;">👍</span>
            Facebook
        </a>
        <button id="share-copy" class="reel-share-btn">
            <span class="reel-share-icon" style="background:#555;">🔗</span>
            Copy Link
        </button>
    </div>
</div>

<!-- ── Backdrop ─────────────────────────────────────────────── -->
<div id="reel-backdrop" class="reel-backdrop"></div>

<!-- ── JS config + script ───────────────────────────────────── -->
<script>
    window.__REELS_API_BASE__ = <?= json_encode($apiBase) ?>;
</script>
<script src="<?= SITE_URL ?>/assets/js/reels.js" defer></script>

</body>
</html>
