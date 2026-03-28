<?php
/**
 * Central Database Connection
 * Used by: API, Admin Panel, Geo, Helpers
 * 
 * SECURITY: Credentials loaded from environment variables.
 * Set these in your server config or .env file (never commit .env).
 */

declare(strict_types=1);

// ---- ENV / CONFIG ----
// Load from environment variables with fallback for local dev
$host    = getenv('DB_HOST')    ?: 'localhost';
$db      = getenv('DB_NAME')    ?: 'newsxpresslive';
$user    = getenv('DB_USER')    ?: 'root';
$pass    = getenv('DB_PASS')    ?: '';
$charset = 'utf8mb4';

// ---- DSN ----
$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

// ---- PDO OPTIONS ----
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // ⚠️ Do NOT expose DB details in production
    error_log('DB Connection Error: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error');
}
