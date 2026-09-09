<?php
/**
 * web/api/listings/post.php
 *
 * POST — Create a new listing.
 *
 * Accepts multipart/form-data (with images) or application/json (no images).
 *
 * Required fields:
 *   firebase_uid, category_id, title, description
 *
 * Optional fields:
 *   listing_type, price, price_negotiable, price_type,
 *   state_id, district_id, city, pincode, latitude, longitude,
 *   contact_name, contact_phone, show_phone, contact_whatsapp,
 *   expires_days   (default 30)
 *
 * Images (multipart only):
 *   images[]   — up to 10 image files (jpg/png/webp, max 5 MB each)
 *
 * New listings are created with status='pending'.
 * They become active after admin approval (or can be auto-approved by config).
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

define('LISTING_UPLOAD_DIR', __DIR__ . '/../../../uploads/listings/');
define('LISTING_UPLOAD_URL', '/uploads/listings/');
define('LISTING_MAX_IMAGES', 10);
define('LISTING_MAX_IMAGE_BYTES', 5 * 1024 * 1024);
define('LISTING_AUTO_APPROVE', getenv('LISTING_AUTO_APPROVE') === 'true');

/* ── parse body ───────────────────────────────────────────── */

$isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

if ($isMultipart) {
    $body = $_POST;
} else {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
}

/* ── validate required ────────────────────────────────────── */

$uid         = trim($body['firebase_uid'] ?? '');
$categoryId  = isset($body['category_id']) ? (int)$body['category_id'] : 0;
$title       = trim($body['title'] ?? '');
$description = trim($body['description'] ?? '');

if ($uid === '' || $categoryId <= 0 || $title === '' || $description === '') {
    http_response_code(400);
    echo json_encode(['error' => 'firebase_uid, category_id, title, description required']);
    exit;
}
if (mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'title max 200 chars']);
    exit;
}

/* ── optional fields ──────────────────────────────────────── */

$validTypes  = ['sell','service','rent','wanted'];
$listingType = in_array($body['listing_type'] ?? '', $validTypes, true)
    ? $body['listing_type'] : 'sell';
$price           = isset($body['price'])       && $body['price'] !== '' ? (float)$body['price'] : null;
$priceNeg        = !empty($body['price_negotiable']) ? 1 : 0;
$validPriceTypes = ['fixed','per_day','per_month','per_hour','free'];
$priceType       = in_array($body['price_type'] ?? '', $validPriceTypes, true)
    ? $body['price_type'] : 'fixed';
$stateId         = isset($body['state_id'])    && ctype_digit((string)$body['state_id'])
    ? (int)$body['state_id']    : null;
$districtId      = isset($body['district_id']) && ctype_digit((string)$body['district_id'])
    ? (int)$body['district_id'] : null;
$city            = trim($body['city']    ?? '') ?: null;
$pincode         = trim($body['pincode'] ?? '') ?: null;
$lat             = isset($body['latitude'])  && is_numeric($body['latitude'])
    ? (float)$body['latitude']  : null;
$lng             = isset($body['longitude']) && is_numeric($body['longitude'])
    ? (float)$body['longitude'] : null;
$contactName     = trim($body['contact_name']     ?? '') ?: null;
$contactPhone    = trim($body['contact_phone']    ?? '') ?: null;
$showPhone       = isset($body['show_phone']) && $body['show_phone'] == '0' ? 0 : 1;
$contactWA       = trim($body['contact_whatsapp'] ?? '') ?: null;
$expiresDays     = max(1, min(365, (int)($body['expires_days'] ?? 30)));
$expiresAt       = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));

/* ── handle image uploads ─────────────────────────────────── */

$imagePaths = [];
if ($isMultipart && !empty($_FILES['images'])) {
    if (!is_dir(LISTING_UPLOAD_DIR)) {
        mkdir(LISTING_UPLOAD_DIR, 0755, true);
    }

    $files = $_FILES['images'];
    // Normalise single vs multiple files
    if (!is_array($files['name'])) {
        $files = array_map(fn ($v) => [$v], $files);
    }

    $count = min(LISTING_MAX_IMAGES, count($files['name']));
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i]  > LISTING_MAX_IMAGE_BYTES) continue;

        $mime = mime_content_type($files['tmp_name'][$i]);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;

        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = LISTING_UPLOAD_DIR . $name;

        if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
            $imagePaths[] = LISTING_UPLOAD_URL . $name;
        }
    }
}

/* ── insert listing ───────────────────────────────────────── */

$status = LISTING_AUTO_APPROVE ? 'active' : 'pending';

try {
    $stmt = $pdo->prepare(
        'INSERT INTO listings
            (user_id, category_id, title, description, images,
             listing_type, price, price_negotiable, price_type,
             state_id, district_id, city, pincode, latitude, longitude,
             contact_name, contact_phone, show_phone, contact_whatsapp,
             status, expires_at)
         VALUES
            (:uid, :cat, :title, :desc, :imgs,
             :ltype, :price, :pneg, :ptype,
             :sid, :did, :city, :pin, :lat, :lng,
             :cname, :cphone, :sphone, :cwa,
             :status, :exp)'
    );
    $stmt->execute([
        ':uid'    => $uid,
        ':cat'    => $categoryId,
        ':title'  => $title,
        ':desc'   => $description,
        ':imgs'   => !empty($imagePaths) ? json_encode($imagePaths) : null,
        ':ltype'  => $listingType,
        ':price'  => $price,
        ':pneg'   => $priceNeg,
        ':ptype'  => $priceType,
        ':sid'    => $stateId,
        ':did'    => $districtId,
        ':city'   => $city,
        ':pin'    => $pincode,
        ':lat'    => $lat,
        ':lng'    => $lng,
        ':cname'  => $contactName,
        ':cphone' => $contactPhone,
        ':sphone' => $showPhone,
        ':cwa'    => $contactWA,
        ':status' => $status,
        ':exp'    => $expiresAt,
    ]);
    $newId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

echo json_encode([
    'success'    => true,
    'listing_id' => $newId,
    'status'     => $status,
    'message'    => $status === 'pending'
        ? 'Listing submitted for review'
        : 'Listing posted successfully',
]);
