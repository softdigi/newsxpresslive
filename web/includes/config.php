<?php
/**
 * Database Configuration
 * NewsXpressLive - PDO Connection
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'newsxpresslive');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/web');
define('SITE_NAME', 'NewsXpressLive');
define('SITE_TAGLINE', 'Breaking News, Latest Updates');
define('UPLOADS_URL', SITE_URL . '/uploads/news/');

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
