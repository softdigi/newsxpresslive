<?php
// actions/reject_news.php — FIXED
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../../auth/session.php';

requireRole(['super_admin', 'admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/news/pending.php');
    exit;
}

verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . ADMIN_URL . '/news/pending.php');
    exit;
}

$pdo->prepare("UPDATE news SET status = 'rejected', updated_at = NOW() WHERE id = ?")
    ->execute([$id]);

header('Location: ' . ADMIN_URL . '/news/pending.php');
exit;
