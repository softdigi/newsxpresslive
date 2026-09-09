<?php
// actions/breaking_news.php — FIXED
// Was GET — anyone could toggle breaking status via URL
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../helpers/firebase_rtdb.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/news/breaking.php');
    exit;
}

verify_csrf();

$id     = (int)($_POST['id']     ?? 0);
$action = trim($_POST['action']  ?? '');

if ($id <= 0 || !in_array($action, ['add', 'remove'], true)) {
    header('Location: ' . ADMIN_URL . '/news/breaking.php');
    exit;
}

$value = ($action === 'add') ? 1 : 0;
$pdo->prepare("UPDATE news SET is_breaking = ? WHERE id = ?")
    ->execute([$value, $id]);

if ($action === 'add') {
    // Push breaking stub to RTDB so Flutter clients update the feed instantly
    $row = $pdo->prepare("SELECT id, title, slug, image, created_at FROM news WHERE id = ? LIMIT 1");
    $row->execute([$id]);
    $article = $row->fetch();
    if ($article) {
        rtdbPut('/live/breaking/latest', [
            'id'         => (int) $article['id'],
            'title'      => $article['title'],
            'slug'       => $article['slug'],
            'image'      => $article['image'],
            'created_at' => $article['created_at'],
            'ts'         => time(),
        ]);
    }
} else {
    // Remove the RTDB node so the ticker disappears on all clients
    rtdbDelete('/live/breaking/latest');
}

header('Location: ' . ADMIN_URL . '/news/breaking.php');
exit;
