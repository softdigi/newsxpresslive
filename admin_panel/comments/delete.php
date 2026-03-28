<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

verify_csrf($_GET['csrf_token'] ?? '');

$id=(int)($_GET['id'] ?? 0);

$stmt=$pdo->prepare("DELETE FROM comments WHERE id=?");
$stmt->execute([$id]);

header("Location: index.php");
exit;
