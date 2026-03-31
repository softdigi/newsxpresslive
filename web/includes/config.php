<?php
/**
 * Database Configuration
 * NewsXpressLive - PDO Connection
 */

// Start PHP session (used for category-preference personalization).
// Guard avoids double-start when pages include config via multiple paths.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'newsxpresslive');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// SECURITY: Do NOT derive SITE_URL from HTTP_HOST – that header can be spoofed
// (host-header injection → cache poisoning, password-reset link hijacking, etc.).
// Set this to the exact public URL of the site. Trailing slash omitted intentionally.
define('SITE_URL', 'http://localhost/web');
define('SITE_NAME', 'NewsXpressLive');
define('SITE_TAGLINE', 'Breaking News, Latest Updates');
define('UPLOADS_URL', SITE_URL . '/uploads/news/');

// App store deep-links (update when app is published)
define('PLAY_STORE_URL', 'https://play.google.com/store/apps/details?id=com.newsxpresslive');
define('APP_STORE_URL',  'https://apps.apple.com/app/newsxpresslive/id000000000');

// Article content lock: percentage of article visible before paywall (0–100).
// Set to 0 to disable locking.
define('ARTICLE_LOCK_PERCENT', 60);

// Monetization: Premium subscription
define('PREMIUM_TEASER_PERCENT', 30);   // % of premium article shown before hard paywall
define('PLAN_MONTHLY_PRICE',  '4.99');
define('PLAN_YEARLY_PRICE',  '39.99');
define('PLAN_CURRENCY', 'USD');

// Reporter reel upload key (shared secret — change before deploying to production)
define('REPORTER_UPLOAD_KEY', 'change-me-in-production-use-a-strong-random-secret');

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log the error instead of displaying it
    error_log('Database connection failed: ' . $e->getMessage());
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;">
        <h2>Service Temporarily Unavailable</h2>
        <p>We are experiencing technical difficulties. Please try again later.</p>
    </div>');
}
