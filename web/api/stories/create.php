<?php
/**
 * web/api/stories/create.php
 * Auth-required API — create a 24-hour story.
 *
 * POST /web/api/stories/create.php
 * Authorization: Bearer <firebase_id_token>
 *
 * multipart/form-data fields:
 *   story_type        — image|video|text
 *   text_content      — (for text stories, max 500 chars)
 *   background_color  — hex colour (default #1a1a2e)
 *   linked_article_id — optional article link
 *   duration_seconds  — 3–15 (default 5)
 *   file              — image/video file (for image/video types)
 *
 * Response: { success, story_id, expires_at }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../helpers/upload.php';

corsHeaders(['POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$user  = requireAppUser($pdo, $token);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

// Only reporters and agency users may create stories
if (!in_array($user['role'] ?? '', ['reporter', 'agency', 'admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Reporter or agency account required']);
    exit;
}

$reporter_uid  = $user['firebase_uid'] ?? '';
$agency_id     = $user['agency_id'] ?? null;
$story_type    = in_array($_POST['story_type'] ?? '', ['image', 'video', 'text'], true)
                 ? $_POST['story_type'] : 'text';
$text_content  = mb_substr(trim($_POST['text_content'] ?? ''), 0, 500) ?: null;
$bg_color      = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['background_color'] ?? '')
                 ? $_POST['background_color'] : '#1a1a2e';
$linked_id     = (int)($_POST['linked_article_id'] ?? 0) ?: null;
$duration      = max(3, min(15, (int)($_POST['duration_seconds'] ?? 5)));

$media_url     = null;
$thumbnail_url = null;

// ── File upload for image/video stories ──────────────────────────────────────
if (in_array($story_type, ['image', 'video'], true)) {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File required for image/video story']);
        exit;
    }

    $file       = $_FILES['file'];
    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed    = $story_type === 'image'
                  ? ['jpg', 'jpeg', 'png', 'webp']
                  : ['mp4', 'mov', 'webm'];

    if (!in_array($ext, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
        exit;
    }

    $max_bytes = $story_type === 'image' ? 5 * 1024 * 1024 : 50 * 1024 * 1024;
    if ($file['size'] > $max_bytes) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File too large']);
        exit;
    }

    $upload_dir = __DIR__ . '/../../../uploads/stories/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filename  = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest_path = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit;
    }

    $media_url = rtrim(SITE_URL, '/') . '/uploads/stories/' . $filename;

    // For video, use a placeholder thumbnail
    if ($story_type === 'video') {
        $thumbnail_url = $media_url; // client should handle thumbnail generation
    }
}

if ($story_type === 'text' && $text_content === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'text_content required for text story']);
    exit;
}

// ── Insert story ──────────────────────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO news_stories
       (reporter_uid, agency_id, story_type, media_url, thumbnail_url, text_content,
        background_color, linked_article_id, duration_seconds, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL 24 HOUR)'
)->execute([
    $reporter_uid, $agency_id, $story_type, $media_url, $thumbnail_url,
    $text_content, $bg_color, $linked_id, $duration,
]);

$story_id  = (int)$pdo->lastInsertId();
$expires   = date('c', strtotime('+24 hours'));

echo json_encode(['success' => true, 'story_id' => $story_id, 'expires_at' => $expires]);
