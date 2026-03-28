<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

// SECURITY FIX: Require POST method for destructive actions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed - use POST');
}

verify_csrf($_POST['csrf_token'] ?? '');

$id=(int)($_POST['id'] ?? 0);

$stmt=$pdo->prepare("DELETE FROM comments WHERE id=?");
$stmt->execute([$id]);

header("Location: index.php");
exit;
