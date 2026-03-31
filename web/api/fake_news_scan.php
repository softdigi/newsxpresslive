<?php
/**
 * web/api/fake_news_scan.php
 * NewsXpressLive — Fake News Scan API
 *
 * POST (JSON or form-encoded):
 *   { "news_id": 123 }
 *
 * Runs the NLP detector on the article, persists results to the news row,
 * and (if score > 25) upserts a row in fake_news_queue for admin review.
 *
 * Response:
 *   { "success": true, "score": 37.5, "verdict": "suspicious",
 *     "flags": [...], "sentiment": "negative" }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fake_news_detector.php';

/* ── Parse input ──────────────────────────────────────────────────── */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$newsId = (int)($input['news_id'] ?? 0);
if ($newsId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'news_id required']);
    exit;
}

/* ── Fetch article ────────────────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        'SELECT n.title, n.content, r.name AS reporter_name
         FROM news n
         LEFT JOIN reporters r ON r.id = n.reporter_id
         WHERE n.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $newsId]);
    $article = $stmt->fetch();
} catch (PDOException $e) {
    error_log('fake_news_scan fetch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit;
}

if (!$article) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Article not found']);
    exit;
}

/* ── Run detector ─────────────────────────────────────────────────── */
$result  = detectFakeNews(
    $article['title'],
    $article['content'],
    $article['reporter_name'] ?? ''
);

$score   = $result['score'];
$verdict = $result['verdict'];
$flags   = $result['flags'];
$flagsJson = json_encode($flags);

/* ── Persist results to news row ──────────────────────────────────── */
try {
    $pdo->prepare(
        'UPDATE news
         SET fake_score      = :score,
             fake_flags      = :flags,
             fake_verdict    = :verdict,
             fake_scanned_at = NOW()
         WHERE id = :id'
    )->execute([
        ':score'   => $score,
        ':flags'   => $flagsJson,
        ':verdict' => $verdict,
        ':id'      => $newsId,
    ]);
} catch (PDOException $e) {
    error_log('fake_news_scan update: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error on update']);
    exit;
}

/* ── Add to review queue if flagged ──────────────────────────────── */
if ($verdict !== 'clean') {
    try {
        $pdo->prepare(
            'INSERT INTO fake_news_queue (news_id, fake_score, fake_verdict, fake_flags)
             VALUES (:nid, :sc, :vd, :fl)
             ON DUPLICATE KEY UPDATE
               fake_score   = :sc2,
               fake_verdict = :vd2,
               fake_flags   = :fl2,
               reviewed     = 0,
               reviewed_at  = NULL'
        )->execute([
            ':nid' => $newsId,
            ':sc'  => $score,
            ':vd'  => $verdict,
            ':fl'  => $flagsJson,
            ':sc2' => $score,
            ':vd2' => $verdict,
            ':fl2' => $flagsJson,
        ]);
    } catch (PDOException $e) {
        error_log('fake_news_scan queue: ' . $e->getMessage());
        // Non-fatal — analysis result is already saved
    }
}

echo json_encode([
    'success'   => true,
    'score'     => $score,
    'verdict'   => $verdict,
    'flags'     => $flags,
    'sentiment' => $result['sentiment'],
]);
