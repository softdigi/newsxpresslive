<?php
/**
 * admin_panel/actions/fake_news_reject.php
 * Mark queue entry as reviewed and reject the article (status → rejected).
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

    // Reject the article
    $pdo->prepare(
        "UPDATE news
         SET status = 'rejected', fake_reviewed = 1, updated_at = NOW()
         WHERE id = :nid"
    )->execute([':nid' => $newsId]);
} catch (PDOException $e) {
    error_log('fake_news_reject: ' . $e->getMessage());
}

header('Location: ' . ADMIN_URL . '/fake_news/index.php?rejected=1');
exit;
