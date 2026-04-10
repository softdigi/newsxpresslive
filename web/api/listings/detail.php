<?php
/**
 * web/api/listings/detail.php
 *
 * GET ?id=<listing_id>[&firebase_uid=<uid>]
 *
 * Returns full listing details.
 * Increments views_count on every request.
 * If firebase_uid is supplied, also returns whether the user has saved it.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$uid = trim($_GET['firebase_uid'] ?? '');

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id required']);
    exit;
}

/* ── fetch listing ────────────────────────────────────────── */

$stmt = $pdo->prepare(
    'SELECT l.*,
            lc.name      AS category,
            lc.name_hi   AS category_hi,
            lc.icon      AS category_icon
     FROM listings l
     JOIN listing_categories lc ON lc.id = l.category_id
     WHERE l.id = ? AND l.status = \'active\''
);
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Listing not found']);
    exit;
}

/* ── increment view count ─────────────────────────────────── */

$pdo->prepare('UPDATE listings SET views_count = views_count + 1 WHERE id = ?')
    ->execute([$id]);

/* ── is saved by this user? ───────────────────────────────── */

$isSaved = false;
if ($uid !== '') {
    $sStmt = $pdo->prepare(
        'SELECT 1 FROM listing_saves WHERE user_id = ? AND listing_id = ?'
    );
    $sStmt->execute([$uid, $id]);
    $isSaved = (bool)$sStmt->fetchColumn();
}

/* ── similar listings ─────────────────────────────────────── */

$simStmt = $pdo->prepare(
    'SELECT id, title, images, price, city, created_at
     FROM listings
     WHERE category_id = ? AND status = \'active\' AND id != ?
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY is_featured DESC, created_at DESC
     LIMIT 6'
);
$simStmt->execute([(int)$row['category_id'], $id]);
$similar = array_map(function ($r) {
    $imgs = [];
    if (!empty($r['images'])) {
        $d = json_decode($r['images'], true);
        if (is_array($d)) $imgs = $d;
    }
    return [
        'id'         => (int)$r['id'],
        'title'      => $r['title'],
        'thumb'      => $imgs[0] ?? null,
        'price'      => $r['price'] !== null ? (float)$r['price'] : null,
        'city'       => $r['city'],
        'created_at' => $r['created_at'],
    ];
}, $simStmt->fetchAll());

/* ── format response ──────────────────────────────────────── */

$images = [];
if (!empty($row['images'])) {
    $d = json_decode($row['images'], true);
    if (is_array($d)) $images = $d;
}

echo json_encode([
    'listing' => [
        'id'               => (int)$row['id'],
        'user_id'          => $row['user_id'],
        'category_id'      => (int)$row['category_id'],
        'category'         => $row['category'],
        'category_hi'      => $row['category_hi'],
        'category_icon'    => $row['category_icon'],
        'title'            => $row['title'],
        'description'      => $row['description'],
        'images'           => $images,
        'listing_type'     => $row['listing_type'],
        'price'            => $row['price'] !== null ? (float)$row['price'] : null,
        'price_negotiable' => (bool)$row['price_negotiable'],
        'price_type'       => $row['price_type'],
        'city'             => $row['city'],
        'pincode'          => $row['pincode'],
        'state_id'         => $row['state_id'] ? (int)$row['state_id'] : null,
        'district_id'      => $row['district_id'] ? (int)$row['district_id'] : null,
        'latitude'         => $row['latitude'] ? (float)$row['latitude'] : null,
        'longitude'        => $row['longitude'] ? (float)$row['longitude'] : null,
        'contact_name'     => $row['contact_name'],
        'contact_phone'    => $row['show_phone'] ? $row['contact_phone'] : null,
        'show_phone'       => (bool)$row['show_phone'],
        'contact_whatsapp' => $row['contact_whatsapp'],
        'is_featured'      => (bool)$row['is_featured'],
        'views_count'      => (int)$row['views_count'] + 1, // already incremented
        'saves_count'      => (int)$row['saves_count'],
        'status'           => $row['status'],
        'created_at'       => $row['created_at'],
        'expires_at'       => $row['expires_at'],
        'is_saved'         => $isSaved,
    ],
    'similar' => $similar,
]);
