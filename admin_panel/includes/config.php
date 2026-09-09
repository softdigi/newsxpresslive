<?php
// ============================================================
// admin_panel/includes/config.php — UPDATED
// Changes:
//   1. session_start() replaced with adminSessionStart()
//      from auth/session.php (secure cookie params)
//   2. PDO hardened: EMULATE_PREPARES=>false added
//   3. display_errors OFF in production
// ============================================================

ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Secure session via auth/session.php
require_once __DIR__ . '/../../auth/session.php';
adminSessionStart();

// SECURITY: Load credentials from environment variables
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'newsxpresslive';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('DB Connection Error: ' . $e->getMessage());
    http_response_code(500);
    die('Service temporarily unavailable.');
}

require_once __DIR__ . '/csrf.php';
define('ADMIN_URL', rtrim(getenv('ADMIN_BASE_PATH') ?: '/admin_panel', '/'));
