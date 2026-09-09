<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request');
}

verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    exit('Invalid ID');
}

$stmt = $pdo->prepare("SELECT id, role, status FROM admin_users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

if ($user['role'] === 'super_admin') {
    exit('Cannot modify super admin');
}

$newStatus = ($user['status'] === 'active') ? 'blocked' : 'active';

$update = $pdo->prepare("UPDATE admin_users SET status=? WHERE id=?");
$update->execute([$newStatus, $id]);

header("Location: index.php");
exit;
