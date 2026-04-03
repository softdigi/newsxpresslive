<?php
/**
 * Behavior Tracking API
 * NewsXpressLive
 *
 * Accepts click / read / scroll / share events from the front-end tracker.
 * POST JSON body:
 *   {
 *     "news_id":      123,
 *     "event_type":   "read",        // click|read|scroll|share
 *     "time_spent":   45,            // seconds (0 for click events)
 *     "scroll_depth": 80             // 0-100 %
 *   }
 *
 * After recording the raw event the endpoint updates the user_profiles
 * aggregation table using a weighted scoring formula:
 *   score += weight_base * time_factor * scroll_factor
 *
 * Where:
 *   weight_base   = 1 (click) | 3 (scroll>50%) | 5 (read/complete)
 *   time_factor   = min(time_spent / 60, 3)  (cap at 3 minutes)
 *   scroll_factor = scroll_depth / 100
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/viral_score.php';

/* ── Read + validate input ─────────────────────────────────────────── */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$newsId      = (int)($input['news_id']      ?? 0);
$eventType   = $input['event_type']  ?? 'click';
$timeSpent   = min((int)($input['time_spent']   ?? 0), 3600); // max 1 h
$scrollDepth = min(max((int)($input['scroll_depth'] ?? 0), 0), 100);

$allowedTypes = ['click', 'read', 'scroll', 'share'];
if ($newsId <= 0 || !in_array($eventType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

/* ── Anonymous session fingerprint (no PII stored) ─────────────────── */
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip        = $_SERVER['REMOTE_ADDR']    ?? '';
$sessionId = hash('sha256', $ip . $ua . date('Ymd')); // rotates daily

/* ── Insert raw event ──────────────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        'INSERT INTO user_behavior (session_id, news_id, event_type, time_spent, scroll_depth)
         VALUES (:sid, :nid, :evt, :ts, :sd)'
    );
    $stmt->execute([
        ':sid' => $sessionId,
        ':nid' => $newsId,
        ':evt' => $eventType,
        ':ts'  => $timeSpent,
        ':sd'  => $scrollDepth,
    ]);
} catch (PDOException $e) {
    // Table may not exist yet on first deploy
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit;
}

/* ── Compute interest score increment ──────────────────────────────── */
$weightBase   = match($eventType) {
    'read'   => 5,
    'scroll' => ($scrollDepth >= 50 ? 3 : 1),
    'share'  => 4,
    default  => 1,
};
$timeFactor   = min($timeSpent / 60.0, 3.0);       // 0 – 3
$scrollFactor = $scrollDepth / 100.0;              // 0 – 1
// For click events, use a flat score; for read/scroll scale by engagement.
$scoreIncrement = ($eventType === 'click')
    ? 1.0
    : round($weightBase * max($timeFactor, 0.2) * max($scrollFactor, 0.2), 4);

/* ── Update user_profiles for category ─────────────────────────────── */
try {
    // Get category_id for this article
    $catRow = $pdo->prepare('SELECT category_id FROM news WHERE id = :id LIMIT 1');
    $catRow->execute([':id' => $newsId]);
    $categoryId = (int)($catRow->fetchColumn() ?: 0);

    if ($categoryId > 0) {
        $pdo->prepare(
            'INSERT INTO user_profiles (session_id, category_id, tag_id, interest_score)
             VALUES (:sid, :cid, NULL, :sc)
             ON DUPLICATE KEY UPDATE interest_score = LEAST(interest_score + :sc2, 9999)'
        )->execute([
            ':sid' => $sessionId,
            ':cid' => $categoryId,
            ':sc'  => $scoreIncrement,
            ':sc2' => $scoreIncrement,
        ]);
    }

    // Get tags for this article and update per-tag profile rows
    $tagRows = $pdo->prepare(
        'SELECT tag_id FROM news_tags WHERE news_id = :nid'
    );
    $tagRows->execute([':nid' => $newsId]);
    foreach ($tagRows->fetchAll() as $tagRow) {
        $pdo->prepare(
            'INSERT INTO user_profiles (session_id, category_id, tag_id, interest_score)
             VALUES (:sid, NULL, :tid, :sc)
             ON DUPLICATE KEY UPDATE interest_score = LEAST(interest_score + :sc2, 9999)'
        )->execute([
            ':sid' => $sessionId,
            ':tid' => (int)$tagRow['tag_id'],
            ':sc'  => $scoreIncrement,
            ':sc2' => $scoreIncrement,
        ]);
    }
} catch (PDOException $e) {
    // Profile update failure is non-fatal; raw event already saved
    error_log('user_profiles update failed: ' . $e->getMessage());
}

// ── Update viral score when a share event is tracked ─────────────────
// (share_track.php also does this for web shares; this covers app events)
if ($eventType === 'share') {
    // Increment shares_count if not already done by share_track.php
    // (app may call track.php directly without calling share_track.php)
    try {
        $pdo->prepare(
            "UPDATE news SET shares_count = COALESCE(shares_count, 0) + 1
             WHERE id = :id AND status = 'approved'"
        )->execute([':id' => $newsId]);
    } catch (PDOException $e) {
        error_log('track share increment: ' . $e->getMessage());
    }
}

// Recalculate viral score for every tracked event (non-blocking)
updateViralScore($pdo, $newsId);

echo json_encode(['success' => true]);
