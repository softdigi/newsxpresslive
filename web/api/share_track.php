<?php
/**
 * web/api/share_track.php
 * NewsXpressLive — Share Event Tracker
 *
 * POST JSON  { "news_id": 123 }
 *
 * Increments shares_count on the news row, records a 'share' event in
 * user_behavior (for personalisation), and triggers a viral score update.
 *
 * Returns: { "success": true, "viral_score": 42.5 }
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/viral_score.php';

/* ── Parse input ────────────────────────────────────────────────────── */
$input  = json_decode(file_get_contents('php://input'), true);
$newsId = (int)($input['news_id'] ?? 0);

if ($newsId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid news_id']);
    exit;
}

/* ── Rate-limit: 1 share per session per article ────────────────────── */
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip        = $_SERVER['REMOTE_ADDR']    ?? '';
$sessionId = hash('sha256', $ip . $ua . date('Ymd'));

$rlKey = 'share_' . $sessionId . '_' . $newsId;
if (!empty($_SESSION[$rlKey])) {
    // Already counted for this session — return current score silently
    try {
        $row   = $pdo->prepare('SELECT viral_score FROM news WHERE id = :id LIMIT 1');
        $row->execute([':id' => $newsId]);
        $score = (float)($row->fetchColumn() ?: 0.0);
    } catch (PDOException $e) {
        $score = 0.0;
    }
    echo json_encode(['success' => true, 'viral_score' => $score, 'duplicate' => true]);
    exit;
}
$_SESSION[$rlKey] = true;

/* ── Increment shares_count ─────────────────────────────────────────── */
try {
    $pdo->prepare(
        "UPDATE news
         SET shares_count = COALESCE(shares_count, 0) + 1
         WHERE id = :id AND status = 'approved'"
    )->execute([':id' => $newsId]);
} catch (PDOException $e) {
    // Column may not exist on legacy DB — try to add it
    if (stripos($e->getMessage(), 'shares_count') !== false ||
        stripos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $pdo->exec('ALTER TABLE news ADD COLUMN shares_count INT UNSIGNED NOT NULL DEFAULT 0');
            $pdo->prepare(
                "UPDATE news SET shares_count = 1 WHERE id = :id AND status = 'approved'"
            )->execute([':id' => $newsId]);
        } catch (PDOException $e2) {
            error_log('share_track shares_count: ' . $e2->getMessage());
        }
    } else {
        error_log('share_track increment error: ' . $e->getMessage());
    }
}

/* ── Record share event in user_behavior (for personalisation) ─────── */
try {
    $pdo->prepare(
        'INSERT INTO user_behavior (session_id, news_id, event_type, time_spent, scroll_depth)
         VALUES (:sid, :nid, :evt, 0, 0)'
    )->execute([
        ':sid' => $sessionId,
        ':nid' => $newsId,
        ':evt' => 'share',
    ]);
} catch (PDOException $e) {
    error_log('share_track user_behavior: ' . $e->getMessage());
}

/* ── Update viral score ─────────────────────────────────────────────── */
$score = updateViralScore($pdo, $newsId);

echo json_encode(['success' => true, 'viral_score' => $score]);
