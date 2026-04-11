<?php
/**
 * web/api/complaints/submit.php
 * Enhanced Public Voice — Submit a new complaint
 *
 * POST multipart/form-data
 * Headers: Authorization: Bearer <firebase_id_token>
 *
 * Fields:
 *   category_id   int      (required)
 *   title         string   (required, max 200)
 *   description   string   (required, max 5000)
 *   images[]      file[]   (optional, max 5, jpg/png/webp, 5 MB each)
 *   video_url     string   (optional)
 *   latitude      float    (optional)
 *   longitude     float    (optional)
 *   address       string   (optional)
 *   state_id      int      (optional)
 *   district_id   int      (optional)
 *   city          string   (optional)
 *   pincode       string   (optional)
 *   is_anonymous  0|1      (default 0)
 *
 * Response: { success, complaint_id, message }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../../helpers/cors.php';
corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Auth ───────────────────────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}
$payload = verifyFirebaseToken($idToken);
if (!$payload || empty($payload['sub'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$userId = $payload['sub'];

// ── Required fields ────────────────────────────────────────────────────────
$categoryId  = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$title       = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
$description = mb_substr(trim($_POST['description'] ?? ''), 0, 5000);

if ($categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'category_id required']);
    exit;
}
if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'title required (max 200 chars)']);
    exit;
}
if ($description === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'description required']);
    exit;
}

// ── Optional fields ────────────────────────────────────────────────────────
$videoUrl   = mb_substr(trim($_POST['video_url']  ?? ''), 0, 500) ?: null;
$latitude   = isset($_POST['latitude'])  && is_numeric($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
$longitude  = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
$address    = mb_substr(trim($_POST['address']  ?? ''), 0, 1000) ?: null;
$stateId    = isset($_POST['state_id'])    && ctype_digit((string)$_POST['state_id'])    ? (int)$_POST['state_id']    : null;
$districtId = isset($_POST['district_id']) && ctype_digit((string)$_POST['district_id']) ? (int)$_POST['district_id'] : null;
$city       = mb_substr(trim($_POST['city']    ?? ''), 0, 100) ?: null;
$pincode    = mb_substr(trim($_POST['pincode'] ?? ''), 0, 10)  ?: null;
$isAnonymous = !empty($_POST['is_anonymous']) ? 1 : 0;

// ── Rate-limit: 5 complaints per user per day ──────────────────────────────
try {
    $rlStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM complaints
          WHERE user_id = :uid AND created_at >= CURDATE()'
    );
    $rlStmt->execute([':uid' => $userId]);
    if ((int)$rlStmt->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false,
            'message' => 'Daily limit reached (5 per day). Try again tomorrow.']);
        exit;
    }
} catch (PDOException $e) { /* table may not exist yet — skip */ }

// ── Handle image uploads (max 5) ───────────────────────────────────────────
$uploadedUrls = [];
$uploadDir    = __DIR__ . '/../../uploads/complaints/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$files = $_FILES['images'] ?? null;
if (!empty($files) && is_array($files['name'])) {
    $maxFiles  = 5;
    $maxBytes  = 5 * 1024 * 1024;
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $fi = new finfo(FILEINFO_MIME_TYPE);

    $count = min($maxFiles, count($files['name']));
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        if ($files['size'][$i] > $maxBytes) {
            continue;
        }
        $mime = $fi->file($files['tmp_name'][$i]);
        if (!in_array($mime, $allowedMime, true)) {
            continue;
        }
        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $filename)) {
            $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            $uploadedUrls[] = $baseUrl . '/web/uploads/complaints/' . $filename;
        }
    }
}
$imagesJson = !empty($uploadedUrls) ? json_encode($uploadedUrls) : null;

// ── Insert complaint ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO complaints
         (user_id, category_id, title, description,
          images, video_url,
          latitude, longitude, address, state_id, district_id, city, pincode,
          is_anonymous, status)
         VALUES
         (:uid, :cat, :title, :desc,
          :images, :video,
          :lat, :lng, :addr, :sid, :did, :city, :pin,
          :anon, "pending")'
    );
    $stmt->execute([
        ':uid'    => $userId,
        ':cat'    => $categoryId,
        ':title'  => $title,
        ':desc'   => $description,
        ':images' => $imagesJson,
        ':video'  => $videoUrl,
        ':lat'    => $latitude,
        ':lng'    => $longitude,
        ':addr'   => $address,
        ':sid'    => $stateId,
        ':did'    => $districtId,
        ':city'   => $city,
        ':pin'    => $pincode,
        ':anon'   => $isAnonymous,
    ]);
    $complaintId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'      => true,
        'complaint_id' => $complaintId,
        'message'      => 'Complaint submitted successfully.',
    ]);
} catch (PDOException $e) {
    // Clean up uploaded files on DB error
    foreach ($uploadedUrls as $url) {
        $filename = basename($url);
        @unlink($uploadDir . $filename);
    }
    error_log('complaints/submit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
