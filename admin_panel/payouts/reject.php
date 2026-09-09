<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)$_POST['id'];

$stmt = $pdo->prepare("UPDATE viral_boosts SET status='rejected' WHERE id=?");
$stmt->execute([$id]);

header("Location: index.php");
exit;
