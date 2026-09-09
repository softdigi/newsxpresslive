<?php
/**
 * admin_panel/actions/fake_news_clear.php
 * Mark a fake-news queue entry as reviewed & legitimate.
 * Article stays published; fake_verdict is updated to 'clean'.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requireRole(['admin', 'super_admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/fake_news/index.php');
    exit;
}

verify_csrf();

$qid    = (int)($_POST['qid']         ?? 0);
$newsId = (int)($_POST['news_id']     ?? 0);
$note   = mb_substr(strip_tags(trim($_POST['review_note'] ?? '')), 0, 1000, 'UTF-8');

if ($qid <= 0 || $newsId <= 0) {
    header('Location: ' . ADMIN_URL . '/fake_news/index.php');
    exit;
}

$adminId = (int)($_SESSION['admin']['id'] ?? 0);

try {
    // Mark queue row as reviewed
    $pdo->prepare(
        'UPDATE fake_news_queue
         SET reviewed = 1, reviewed_by = :admin, review_note = :note, reviewed_at = NOW()
         WHERE id = :qid'
    )->execute([':admin' => $adminId, ':note' => $note, ':qid' => $qid]);

    // Reset verdict on the news row
    $pdo->prepare(
        "UPDATE news
         SET fake_verdict = 'clean', fake_reviewed = 1
         WHERE id = :nid"
    )->execute([':nid' => $newsId]);
} catch (PDOException $e) {
    error_log('fake_news_clear: ' . $e->getMessage());
}

header('Location: ' . ADMIN_URL . '/fake_news/index.php?cleared=1');
exit;
