<?php
// ============================================================
// api/user_login.php — UPDATED
// Changes:
//   1. Rate limiting added: 10 attempts per 15 min per IP
//   2. firebase_uid format validated
//   3. Blocked user check on login
// ============================================================
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require __DIR__ . "/response.php";
require_once __DIR__ . "/../../auth/rate_limit.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input        = json_decode(file_get_contents("php://input"), true);
$firebase_uid = trim($input['firebase_uid'] ?? '');
$name         = trim(strip_tags($input['name'] ?? ''));

if (!$firebase_uid) {
    jsonResponse(false, [], "firebase_uid required");
}

// Rate limit login attempts per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
rateLimit($pdo, 'app_login', $ip, 10, 900);

// Validate firebase_uid format
if (!preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $firebase_uid)) {
    jsonResponse(false, [], "invalid firebase_uid format");
}

/* Check existing user */
$stmt = $pdo->prepare(
    "SELECT id, status FROM users WHERE firebase_uid = ? LIMIT 1"
);
$stmt->execute([$firebase_uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // New user — insert with defaults
    $stmt = $pdo->prepare(
        "INSERT INTO users (firebase_uid, name, role, status, created_at)
         VALUES (?, ?, 'user', 'active', NOW())"
    );
    $stmt->execute([$firebase_uid, $name ?: null]);
    $userId = (int)$pdo->lastInsertId();

    // Reset rate limit on successful new registration
    resetRateLimit($pdo, 'app_login', $ip);
} else {
    if (in_array($user['status'], ['blocked', 'suspended', 'banned'], true)) {
        jsonResponse(false, [], "account suspended");
    }
    $userId = (int)$user['id'];
    resetRateLimit($pdo, 'app_login', $ip);
}

jsonResponse(true, ["user_id" => $userId], "login success");
