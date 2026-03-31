<?php
/**
 * admin_panel/actions/fake_news_rescan.php
 * Re-run the NLP detector on an article and update the queue entry.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../web/includes/fake_news_detector.php';

requireRole(['admin', 'super_admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/fake_news/index.php');
    exit;
}

verify_csrf();

$qid    = (int)($_POST['qid']     ?? 0);
$newsId = (int)($_POST['news_id'] ?? 0);

if ($qid <= 0 || $newsId <= 0) {
    header('Location: ' . ADMIN_URL . '/fake_news/index.php');
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT n.title, n.content, r.name AS reporter_name
         FROM news n
         LEFT JOIN reporters r ON r.id = n.reporter_id
         WHERE n.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $newsId]);
    $article = $stmt->fetch();

    if ($article) {
        $result    = detectFakeNews($article['title'], $article['content'], $article['reporter_name'] ?? '');
        $flagsJson = json_encode($result['flags']);

        $pdo->prepare(
            'UPDATE news
             SET fake_score = :sc, fake_flags = :fl, fake_verdict = :vd, fake_scanned_at = NOW()
             WHERE id = :id'
        )->execute([':sc' => $result['score'], ':fl' => $flagsJson, ':vd' => $result['verdict'], ':id' => $newsId]);

        $pdo->prepare(
            'UPDATE fake_news_queue
             SET fake_score = :sc, fake_flags = :fl, fake_verdict = :vd, reviewed = 0, reviewed_at = NULL
             WHERE id = :qid'
        )->execute([':sc' => $result['score'], ':fl' => $flagsJson, ':vd' => $result['verdict'], ':qid' => $qid]);
    }
} catch (PDOException $e) {
    error_log('fake_news_rescan: ' . $e->getMessage());
}

header('Location: ' . ADMIN_URL . '/fake_news/review.php?id=' . $qid);
exit;
