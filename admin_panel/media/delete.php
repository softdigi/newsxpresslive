<?php
// media/delete.php — FIXED
// CSRF was in GET param — URL logged, bypassable
// file_name from DB used directly in unlink — path traversal possible
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';

requireRole(['super_admin', 'admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/media/index.php');
    exit;
}

verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . ADMIN_URL . '/media/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT file_name FROM media WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$file = $stmt->fetch();

if ($file) {
    // FIXED: basename() prevents path traversal — strips any directory component
    $safe_name  = basename($file['file_name']);
    $upload_dir = realpath(__DIR__ . '/../../uploads/');

    if ($upload_dir && $safe_name && $safe_name !== '.' && $safe_name !== '..') {
        $full_path = $upload_dir . DIRECTORY_SEPARATOR . $safe_name;
        // Verify path is within uploads dir
        if (strpos($full_path, $upload_dir) === 0 && file_exists($full_path)) {
            unlink($full_path);
        }
    }

    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);
}

header('Location: ' . ADMIN_URL . '/media/index.php');
exit;
