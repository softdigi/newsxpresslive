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

$action = $_POST['action'] ?? '';
$ids = $_POST['ids'] ?? [];

if (!is_array($ids) || empty($ids)) {
    exit('No users selected');
}

$cleanIds = array_filter(array_map('intval', $ids));

if (empty($cleanIds)) {
    exit('Invalid selection');
}

$placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

/* Prevent modifying super_admin accounts */
$stmtCheck = $pdo->prepare("SELECT id FROM admin_users WHERE id IN ($placeholders) AND role='super_admin'");
$stmtCheck->execute($cleanIds);
if ($stmtCheck->rowCount() > 0) {
    exit('Cannot modify super admin accounts');
}

switch ($action) {

    case 'activate':
        $stmt = $pdo->prepare("UPDATE admin_users SET status='active' WHERE id IN ($placeholders)");
        $stmt->execute($cleanIds);
        break;

    case 'block':
        $stmt = $pdo->prepare("UPDATE admin_users SET status='blocked' WHERE id IN ($placeholders)");
        $stmt->execute($cleanIds);
        break;

    case 'delete':
        $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id IN ($placeholders)");
        $stmt->execute($cleanIds);
        break;

    default:
        exit('Invalid action');
}

header("Location: index.php");
exit;
