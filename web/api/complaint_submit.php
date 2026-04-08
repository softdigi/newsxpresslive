<?php
/**
 * web/api/complaint_submit.php
 *
 * POST multipart/form-data  (or application/json without photo)
 *
 * Fields:
 *   title         string    (required, max 255)
 *   description   string    (required, max 3000)
 *   category_id   int       (required)
 *   district_id   int       (optional)
 *   location_text string    (optional, max 255)
 *   author_name   string    (optional, max 100)
 *   firebase_uid  string    (optional, max 128)
 *   is_anonymous  0|1       (default 0)
 *   photo         file      (optional, jpg/png/webp, max 5 MB)
 *
 * Response: JSON { success: bool, complaint_id?: int, message: string }
 *
 * Rate-limit: max 5 submissions per IP per day (Redis or DB counter).
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

/* ── Determine whether the body is JSON or multipart ──────── */
$isJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

if ($isJson) {
    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw, true) ?? [];
    $fields = $input;
} else {
    $fields = $_POST;
}

/* ── Validate required fields ──────────────────────────────── */
$title       = trim($fields['title']       ?? '');
$description = trim($fields['description'] ?? '');
$categoryId  = isset($fields['category_id']) ? (int)$fields['category_id'] : 0;

if ($title === '' || mb_strlen($title) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'title is required and must be ≤255 chars']);
    exit;
}
if ($description === '' || mb_strlen($description) > 3000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'description is required and must be ≤3000 chars']);
    exit;
}
if ($categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'category_id is required']);
    exit;
}

/* ── Rate-limit: 5 complaints per IP per day ─────────────── */
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM complaints WHERE ip_hash = :ip AND created_at >= CURDATE()'
    );
    $stmt->execute([':ip' => $ipHash]);
    $todayCount = (int)$stmt->fetchColumn();
    if ($todayCount >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Daily submission limit reached (5 per day). Please try again tomorrow.']);
        exit;
    }
} catch (PDOException $e) { /* table may not exist yet — skip check */ }

/* ── Optional fields ──────────────────────────────────────── */
$districtId  = isset($fields['district_id']) && ctype_digit((string)$fields['district_id'])
    ? (int)$fields['district_id'] : null;
$locationTxt = mb_substr(trim($fields['location_text'] ?? ''), 0, 255) ?: null;
$authorName  = mb_substr(trim($fields['author_name']   ?? ''), 0, 100) ?: null;
$firebaseUid = mb_substr(trim($fields['firebase_uid']  ?? ''), 0, 128) ?: null;
$isAnonymous = !empty($fields['is_anonymous']) ? 1 : 0;

/* ── Handle photo upload (optional) ──────────────────────── */
$photoFilename = null;
if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($_FILES['photo']['size'] <= $maxBytes) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($_FILES['photo']['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (in_array($mime, $allowed, true)) {
            $ext  = match ($mime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'jpg',
            };
            $uploadDir = __DIR__ . '/../uploads/complaints/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $photoFilename = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoFilename)) {
                $photoFilename = null;
            }
        }
    }
}

/* ── Insert ───────────────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        'INSERT INTO complaints
         (category_id, title, description, photo,
          district_id, location_text, firebase_uid, author_name, is_anonymous,
          ip_hash, status)
         VALUES
         (:cat, :title, :desc, :photo,
          :did, :loc, :uid, :name, :anon,
          :ip, :status)'
    );
    $stmt->execute([
        ':cat'    => $categoryId,
        ':title'  => $title,
        ':desc'   => $description,
        ':photo'  => $photoFilename,
        ':did'    => $districtId,
        ':loc'    => $locationTxt,
        ':uid'    => $firebaseUid,
        ':name'   => $isAnonymous ? null : $authorName,
        ':anon'   => $isAnonymous,
        ':ip'     => $ipHash,
        ':status' => 'pending',
    ]);
    $complaintId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'      => true,
        'complaint_id' => $complaintId,
        'message'      => 'Your complaint has been submitted and is pending review.',
    ]);
} catch (PDOException $e) {
    if ($photoFilename) {
        @unlink((__DIR__ . '/../uploads/complaints/' . $photoFilename));
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
