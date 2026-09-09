<?php
/**
 * api/v1/news/comment_submit.php
 * Mobile (Flutter) API — submit a comment on a news article.
 *
 * Auth:   Authorization: Bearer <firebase_id_token>
 * Method: POST (JSON body)
 * Body:
 *   news_id   int     (required)
 *   content   string  (required, 5–1000 chars)
 *   parent_id int     (optional — reply to existing comment)
 *
 * FIX 2: Rate limiting added using the central auth/rate_limit.php
 * rateLimit() function. Max 10 comment submissions per user per 60 s.
 * Exceeding the limit returns 429 Too Many Requests with Retry-After header.
 * IP-only limiting is insufficient for authenticated mobile clients because
 * a single device behind NAT could be blocked; user_id gives per-account
 * precision and matches the fraud-guard pattern used elsewhere in this API.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../geo/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../auth/rate_limit.php';

// ── Only accept POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── Authenticate caller via Firebase ID token ──────────────────────────
$id_token    = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $id_token = substr($auth_header, 7);
}

$authUser = requireAppUser($pdo, $id_token);
$user_id  = (int) $authUser['id'];

// ── FIX 2: Rate limit — max 10 comment submissions per user per 60 s ──
// Uses the central rateLimit() from auth/rate_limit.php (same mechanism
// as login and withdrawal protection). Exits with 429 + Retry-After if
// the window limit is exceeded.
rateLimit($pdo, 'comment_submit', (string) $user_id, 10, 60);

// ── Parse JSON body ────────────────────────────────────────────────────
$input     = json_decode(file_get_contents('php://input'), true);
$news_id   = isset($input['news_id'])   ? (int) $input['news_id']        : 0;
$parent_id = isset($input['parent_id']) ? (int) $input['parent_id']      : null;
$content   = mb_substr(trim(strip_tags($input['content'] ?? '')), 0, 1000, 'UTF-8');

if ($news_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid news_id']);
    exit;
}
if (mb_strlen($content, 'UTF-8') < 5) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Comment must be at least 5 characters']);
    exit;
}

// ── Verify the article exists and is published ─────────────────────────
try {
    $newsCheck = $pdo->prepare('SELECT id FROM news WHERE id = ? AND status = ? LIMIT 1');
    $newsCheck->execute([$news_id, 'approved']);
    if (!$newsCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Article not found']);
        exit;
    }
} catch (PDOException $e) {
    error_log('comment_submit article check: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}

// ── Validate parent_id if provided ────────────────────────────────────
if ($parent_id !== null) {
    try {
        $parentCheck = $pdo->prepare(
            'SELECT id FROM comments WHERE id = ? AND news_id = ? AND status = ? LIMIT 1'
        );
        $parentCheck->execute([$parent_id, $news_id, 'approved']);
        if (!$parentCheck->fetch()) {
            $parent_id = null; // treat as top-level if parent not found
        }
    } catch (PDOException $e) {
        $parent_id = null;
    }
}

// ── Insert comment (pending moderation) ───────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO comments (news_id, parent_id, user_id, content, status, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$news_id, $parent_id, $user_id, $content, 'pending']);

    echo json_encode([
        'success' => true,
        'message' => 'Comment submitted and awaiting moderation',
    ]);
} catch (PDOException $e) {
    error_log('comment_submit insert: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save comment']);
}
