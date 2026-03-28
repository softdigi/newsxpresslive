<?php
// ============================================================
// FIXED: geo/config.php
// BUGS:
//   1. CRITICAL: DB error exposed to client:
//      "error" => $e->getMessage() — shows hostname, DB name,
//      credentials hint to anyone who triggers a DB error
//   2. PDO::ATTR_EMULATE_PREPARES => false MISSING
//      (real prepared statements nahi the)
//   3. DB name "new_app_api" — central database.php mein
//      "newsxpre1_news_app_api" hai — inconsistency.
//      This file should use the central config/database.php
//      instead of having its own duplicate connection.
//
// BEST FIX: Use the central config/database.php
// This removes duplication — one place for credentials.
// ============================================================

// Agar central config/database.php exist karta hai to use karo:
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
    return; // $pdo already set
}

// Fallback — load from environment variables for security
$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'newsxpresslive';
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS') ?: '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // FIXED: real prepared statements
        ]
    );
} catch (PDOException $e) {
    error_log('DB Connection Error: ' . $e->getMessage()); // log only
    http_response_code(500);
    // FIXED: never expose $e->getMessage() to client
    echo json_encode([
        "status"  => false,
        "message" => "Service temporarily unavailable"
    ]);
    exit;
}
