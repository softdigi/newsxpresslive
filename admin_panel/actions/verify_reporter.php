<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/csrf.php';

/* Only admin / super_admin */
if (!in_array($_SESSION['admin']['role'], ['admin','super_admin'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// SECURITY FIX: Require POST method with CSRF protection
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed - use POST');
}

verify_csrf($_POST['csrf_token'] ?? '');

$userId = (int)($_POST['id'] ?? 0);
if (!$userId) {
    header("Location: ../reporters/index.php");
    exit;
}

/* Ensure reporter profile exists */
$pdo->prepare("
    INSERT IGNORE INTO reporter_profiles (user_id, is_verified)
    VALUES (?, 1)
")->execute([$userId]);

/* Mark reporter as verified */
$pdo->prepare("
    UPDATE reporter_profiles
    SET is_verified = 1
    WHERE user_id = ?
")->execute([$userId]);

header("Location: ../reporters/index.php");
exit;
