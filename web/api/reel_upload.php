<?php
/**
 * web/api/reel_upload.php
 *
 * POST multipart/form-data
 *   Fields:
 *     reporter_id   int       (required)
 *     reporter_key  string    (shared secret – replace with proper auth in production)
 *     title         string    (required, max 255)
 *     description   string    (optional, max 2000)
 *     category_id   int       (optional)
 *     video         file      (required, mp4/webm/mov, max 200 MB)
 *     thumbnail     file      (optional, jpg/png/webp, max 5 MB)
 *
 * Response: JSON { success: bool, reel_id?: int, message: string }
 *
 * SECURITY NOTES:
 *  - Replace `REPORTER_UPLOAD_KEY` with proper JWT / reporter session auth.
 *  - Uploaded files get a random name; original name is discarded.
 *  - File content is validated by MIME type sniffing (finfo), not just extension.
 *  - Set `upload_max_filesize` and `post_max_size` in php.ini to at least 200M.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

/* ── Only accept POST ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

/* ── Simple reporter key auth (replace with JWT in production) ── */
$validKey = defined('REPORTER_UPLOAD_KEY') ? REPORTER_UPLOAD_KEY : 'change-me-in-config';
$suppliedKey = trim($_POST['reporter_key'] ?? '');
if (!hash_equals($validKey, $suppliedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* ── Validate fields ──────────────────────────────────────── */
$reporterId  = isset($_POST['reporter_id'])  ? (int)$_POST['reporter_id']         : 0;
$title       = trim($_POST['title']          ?? '');
$description = trim($_POST['description']    ?? '');
$categoryId  = isset($_POST['category_id'])  ? (int)$_POST['category_id']         : null;

if ($reporterId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reporter_id is required']);
    exit;
}
if ($title === '' || mb_strlen($title) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'title is required and must be ≤255 chars']);
    exit;
}
if (mb_strlen($description) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'description must be ≤2000 chars']);
    exit;
}

/* ── Validate uploaded video ──────────────────────────────── */
if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['video']['error'] ?? -1;
    $errMsg  = match ($errCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
        UPLOAD_ERR_NO_FILE => 'No video file uploaded',
        default            => 'Upload error (code ' . $errCode . ')',
    };
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit;
}

$maxVideoBytes = 200 * 1024 * 1024; // 200 MB
if ($_FILES['video']['size'] > $maxVideoBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Video must be ≤200 MB']);
    exit;
}

// Validate MIME type via finfo
$allowedVideoMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'];
$fi = new finfo(FILEINFO_MIME_TYPE);
$videoMime = $fi->file($_FILES['video']['tmp_name']);
if (!in_array($videoMime, $allowedVideoMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid video type. Allowed: mp4, webm, mov']);
    exit;
}
$videoExt = match ($videoMime) {
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
    default           => 'mp4',
};

/* ── Determine upload directory ───────────────────────────── */
$uploadDir = __DIR__ . '/../uploads/reels/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* ── Save video file ──────────────────────────────────────── */
$videoFilename = bin2hex(random_bytes(16)) . '.' . $videoExt;
$videoPath     = $uploadDir . $videoFilename;
if (!move_uploaded_file($_FILES['video']['tmp_name'], $videoPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save video']);
    exit;
}

/* ── Optional thumbnail ───────────────────────────────────── */
$thumbFilename = null;
if (!empty($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
    $maxThumbBytes = 5 * 1024 * 1024; // 5 MB
    if ($_FILES['thumbnail']['size'] <= $maxThumbBytes) {
        $allowedThumbMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $thumbMime = $fi->file($_FILES['thumbnail']['tmp_name']);
        if (in_array($thumbMime, $allowedThumbMimes, true)) {
            $thumbExt      = match ($thumbMime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'jpg',
            };
            $thumbFilename = bin2hex(random_bytes(16)) . '.' . $thumbExt;
            $thumbPath     = $uploadDir . $thumbFilename;
            if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbPath)) {
                $thumbFilename = null; // non-fatal
            }
        }
    }
}

/* ── Insert DB record ─────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        'INSERT INTO video_reels
         (reporter_id, title, description, video_file, thumbnail, category_id, status)
         VALUES (:rid, :title, :desc, :vf, :thumb, :cat, :st)'
    );
    $stmt->execute([
        ':rid'   => $reporterId,
        ':title' => $title,
        ':desc'  => $description !== '' ? $description : null,
        ':vf'    => $videoFilename,
        ':thumb' => $thumbFilename,
        ':cat'   => $categoryId,
        ':st'    => 'pending',   // Admin must approve before it appears in feed
    ]);
    $reelId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'  => true,
        'reel_id'  => $reelId,
        'message'  => 'Reel uploaded successfully and is pending review.',
        'video_url' => reelVideoUrl($videoFilename),
        'thumbnail' => $thumbFilename ? reelThumbUrl($thumbFilename) : null,
    ]);

} catch (PDOException $e) {
    // Clean up uploaded files on DB failure
    @unlink($videoPath);
    if ($thumbFilename) @unlink($uploadDir . $thumbFilename);

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
