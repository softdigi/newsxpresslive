<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if ($_SESSION['admin']['role'] !== 'super_admin' && $_SESSION['admin']['role'] !== 'admin') {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request');
}

verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)$_POST['id'];
$action = $_POST['action'];

$stmt = $pdo->prepare("SELECT * FROM viral_boosts WHERE id=?");
$stmt->execute([$id]);
$boost = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$boost) exit('Not found');

if ($action === 'complete') {
    $stmtU = $pdo->prepare("UPDATE viral_boosts SET status='completed' WHERE id=?");
    $stmtU->execute([$id]);
}

if ($action === 'delete') {
    $stmtD = $pdo->prepare("DELETE FROM viral_boosts WHERE id=?");
    $stmtD->execute([$id]);
}

header("Location: index.php");
exit;
