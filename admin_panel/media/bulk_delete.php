<?php
// media/bulk_delete.php — FIXED
// Path traversal via file_name from DB
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';

requireRole(['super_admin', 'admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/media/index.php');
    exit;
}

verify_csrf();

$ids   = $_POST['ids'] ?? [];
$clean = array_values(array_filter(array_map('intval', $ids)));

if (empty($clean)) {
    header('Location: ' . ADMIN_URL . '/media/index.php');
    exit;
}

// Cap bulk operations at 100
$clean = array_slice($clean, 0, 100);
$placeholders = implode(',', array_fill(0, count($clean), '?'));

$stmt = $pdo->prepare("SELECT file_name FROM media WHERE id IN ($placeholders)");
$stmt->execute($clean);
$files = $stmt->fetchAll();

$upload_dir = realpath(__DIR__ . '/../../uploads/');

foreach ($files as $file) {
    // FIXED: basename() + realpath check to prevent path traversal
    $safe_name = basename($file['file_name']);
    if (!$safe_name || $safe_name === '.' || $safe_name === '..') continue;
    $full_path = $upload_dir . DIRECTORY_SEPARATOR . $safe_name;
    if ($upload_dir && strpos($full_path, $upload_dir) === 0 && file_exists($full_path)) {
        unlink($full_path);
    }
}

$pdo->prepare("DELETE FROM media WHERE id IN ($placeholders)")->execute($clean);

header('Location: ' . ADMIN_URL . '/media/index.php');
exit;
