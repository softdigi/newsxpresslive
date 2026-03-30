<?php
require "../includes/config.php";
require "../includes/auth.php";
require "../includes/csrf.php";
require_once __DIR__ . "/../../helpers/firebase_rtdb.php";

// SECURITY FIX: Require POST method with CSRF protection
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed - use POST');
}

verify_csrf();

$newsId = (int)($_POST['id'] ?? 0);
if (!$newsId) {
    header("Location: ../news/breaking.php");
    exit;
}

$pdo->prepare(
    "UPDATE news 
     SET is_breaking=0 
     WHERE id=?"
)->execute([$newsId]);

// Remove the RTDB node so the breaking ticker disappears on all clients
rtdbDelete('/live/breaking/latest');

header("Location: ../news/breaking.php");
exit;
