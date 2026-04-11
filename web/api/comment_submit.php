<?php
/**
 * web/api/comment_submit.php
 * POST endpoint — submit a comment on a news article.
 *
 * Required POST fields:
 *   news_id      int
 *   author_name  string (2–80 chars)
 *   content      string (5–1000 chars)
 *
 * Optional:
 *   author_email string (valid email)
 *   parent_id    int (for replies)
 *
 * Response: JSON  { success: bool, message: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/redis.php';
require_once __DIR__ . '/../../helpers/csrf.php';

// ── Only accept POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── FIX 7: Verify CSRF token for web form submissions ────────────────
// Skip CSRF check for requests from the mobile app (Authorization: Bearer)
$is_app_request = !empty($_SERVER['HTTP_AUTHORIZATION'])
    && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ');
if (!$is_app_request) {
    verifyCsrf();
}

// ── FIX 4: Rate limit via Redis — max 5 comments per IP per 10 minutes ──────
// Using Redis INCR + EXPIRE provides O(1) rate checking with no DB writes,
// no table bloat, no cleanup jobs, and atomic counter increments.
$ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash     = hash('sha256', $ip);
$window_secs = 600; // 10 minutes
$max_per_win = 5;
$rate_key    = 'comment_rl:' . $ip_hash;

$redis_available = false;
try {
    $redis = getRedis();
    $redis_available = true;

    $count = (int)$redis->get($rate_key);
    if ($count >= $max_per_win) {
        $ttl = $redis->ttl($rate_key);
        $wait = $ttl > 0 ? ceil($ttl / 60) : 10;
        http_response_code(429);
        header('Retry-After: ' . ($ttl > 0 ? $ttl : $window_secs));
        echo json_encode(['success' => false, 'message' => "Too many comments. Please wait {$wait} minute(s)."]);
        exit;
    }
} catch (Exception $e) {
    // Redis unavailable — fall back to MySQL rate limiting below
    error_log('Redis unavailable for comment rate limit: ' . $e->getMessage());
}

if (!$redis_available) {
    // MySQL fallback rate limit
    try {
        $pdo->prepare(
            'DELETE FROM comment_rate_limit WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        )->execute([$window_secs]);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM comment_rate_limit WHERE ip_hash = ?');
        $countStmt->execute([$ip_hash]);
        if ((int)$countStmt->fetchColumn() >= $max_per_win) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many comments. Please wait a few minutes.']);
            exit;
        }
    } catch (PDOException $e) {
        // Table may not exist — create it silently
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS comment_rate_limit (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    ip_hash    VARCHAR(64) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ip_time (ip_hash, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (PDOException $e2) { /* ignore */ }
    }
}

// ── Input validation ──────────────────────────────────────────────────
$news_id      = isset($_POST['news_id'])     ? (int)$_POST['news_id']       : 0;
$parent_id    = isset($_POST['parent_id'])   ? (int)$_POST['parent_id']     : null;
$author_name  = mb_substr(trim(strip_tags($_POST['author_name']  ?? '')), 0, 80,   'UTF-8');
$author_email = mb_substr(trim($_POST['author_email'] ?? ''),              0, 255, 'UTF-8');
$content      = mb_substr(trim(strip_tags($_POST['content']      ?? '')), 0, 1000, 'UTF-8');

if ($news_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid article.']);
    exit;
}
if (mb_strlen($author_name, 'UTF-8') < 2) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Name must be at least 2 characters.']);
    exit;
}
if (mb_strlen($content, 'UTF-8') < 5) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Comment must be at least 5 characters.']);
    exit;
}
if ($author_email !== '' && !filter_var($author_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// ── Verify the article exists and is published ────────────────────────
try {
    $newsCheck = $pdo->prepare('SELECT id FROM news WHERE id = ? AND status = ? LIMIT 1');
    $newsCheck->execute([$news_id, 'published']);
    if (!$newsCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Article not found.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
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
            $parent_id = null; // Invalid parent — treat as top-level comment
        }
    } catch (PDOException $e) {
        $parent_id = null;
    }
}

// ── Insert comment (status = pending for moderation) ─────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO comments (news_id, parent_id, author_name, author_email, content, status, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $news_id,
        $parent_id,
        $author_name,
        $author_email !== '' ? $author_email : null,
        $content,
        'pending',
        $ip_hash,   // store hash, not raw IP
    ]);

    // Increment rate limit counter (Redis or MySQL fallback)
    if ($redis_available) {
        try {
            $newCount = $redis->incr($rate_key);
            if ($newCount === 1) {
                $redis->expire($rate_key, $window_secs);
            }
        } catch (Exception $e) { /* non-fatal */ }
    } else {
        try {
            $pdo->prepare('INSERT INTO comment_rate_limit (ip_hash) VALUES (?)')->execute([$ip_hash]);
        } catch (PDOException $e) { /* non-fatal */ }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Your comment has been submitted and is awaiting moderation. Thank you!',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save comment. Please try again.']);
}
