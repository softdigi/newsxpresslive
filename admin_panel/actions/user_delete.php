<?php
// actions/user_delete.php — FIXED
// Was GET — catastrophic, anyone could delete users via URL
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../../auth/session.php';

requireRole(['super_admin']);

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

// Cannot delete other super_admins
$check = $pdo->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
$check->execute([$id]);
$target = $check->fetch();
if (!$target || $target['role'] === 'super_admin') {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

$pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);

header('Location: ' . ADMIN_URL . '/users/index.php');
exit;
