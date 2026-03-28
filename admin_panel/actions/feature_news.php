<?php
// actions/feature_news.php — FIXED
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../../auth/session.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/news/approved.php');
    exit;
}

verify_csrf();

$id     = (int)($_POST['id']    ?? 0);
$action = trim($_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['add', 'remove'], true)) {
    header('Location: ' . ADMIN_URL . '/news/approved.php');
    exit;
}

$value = ($action === 'add') ? 1 : 0;
$pdo->prepare("UPDATE news SET is_featured = ? WHERE id = ?")
    ->execute([$value, $id]);

header('Location: ' . ADMIN_URL . '/news/approved.php');
exit;
