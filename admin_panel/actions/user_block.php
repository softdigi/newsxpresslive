<?php
// actions/user_block.php — FIXED
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../../auth/session.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0 || $id === adminId()) {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

// Cannot block super_admin
$check = $pdo->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
$check->execute([$id]);
$target = $check->fetch();
if (!$target || $target['role'] === 'super_admin') {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

$pdo->prepare("UPDATE admin_users SET status = 'blocked' WHERE id = ?")
    ->execute([$id]);

header('Location: ' . ADMIN_URL . '/users/index.php');
exit;
