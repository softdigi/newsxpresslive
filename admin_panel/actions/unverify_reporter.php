<?php
require "../includes/config.php";
require "../includes/auth.php";
require "../includes/csrf.php";

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

/* Unverify */
$pdo->prepare(
    "UPDATE reporter_profiles SET is_verified=0 WHERE user_id=?"
)->execute([$userId]);

header("Location: ../reporters/index.php");
exit;
