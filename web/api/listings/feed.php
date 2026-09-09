<?php
/**
 * web/api/listings/feed.php
 *
 * GET — Paginated listing feed with filters.
 *
 * Params:
 *   category_id    int
 *   listing_type   sell|service|rent|wanted
 *   min_price      float
 *   max_price      float
 *   state_id       int
 *   district_id    int
 *   lat            float   } nearest listings
 *   lng            float   }
 *   radius_km      float   default 50
 *   search         string
 *   cursor         int     last seen listing id  (0 = first page)
 *   limit          int     1–50, default 20
 *   featured_first 1|0    default 1
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

/* ── params ───────────────────────────────────────────────── */

$categoryId   = isset($_GET['category_id'])  && ctype_digit($_GET['category_id'])
    ? (int)$_GET['category_id'] : null;
$listingType  = in_array($_GET['listing_type'] ?? '', ['sell','service','rent','wanted'], true)
    ? $_GET['listing_type'] : null;
$minPrice     = isset($_GET['min_price'])  ? (float)$_GET['min_price']  : null;
$maxPrice     = isset($_GET['max_price'])  ? (float)$_GET['max_price']  : null;
$stateId      = isset($_GET['state_id'])    && ctype_digit($_GET['state_id'])
    ? (int)$_GET['state_id']    : null;
$districtId   = isset($_GET['district_id']) && ctype_digit($_GET['district_id'])
    ? (int)$_GET['district_id'] : null;
$lat          = isset($_GET['lat'])  ? (float)$_GET['lat']  : null;
$lng          = isset($_GET['lng'])  ? (float)$_GET['lng']  : null;
$radiusKm     = isset($_GET['radius_km']) ? min(500, max(1, (float)$_GET['radius_km'])) : 50.0;
$search       = trim($_GET['search'] ?? '');
$cursor       = isset($_GET['cursor']) && ctype_digit($_GET['cursor']) ? (int)$_GET['cursor'] : 0;
$limit        = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$featuredFirst = ($_GET['featured_first'] ?? '1') !== '0';

/* ── build query ──────────────────────────────────────────── */

$sql = '
SELECT
    l.id,
    l.title,
    l.images,
    l.price,
    l.price_negotiable,
    l.price_type,
    l.listing_type,
    l.city,
    l.state_id,
    l.district_id,
    l.latitude,
    l.longitude,
    l.views_count,
    l.saves_count,
    l.is_featured,
    l.created_at,
    l.expires_at,
    lc.name         AS category,
    lc.name_hi      AS category_hi,
    lc.icon         AS category_icon
FROM listings l
JOIN listing_categories lc ON lc.id = l.category_id
WHERE l.status = \'active\'
  AND (l.expires_at IS NULL OR l.expires_at > NOW())
';

$params = [];

if ($categoryId !== null) {
    $sql .= ' AND l.category_id = :cat';
    $params[':cat'] = $categoryId;
}
if ($listingType !== null) {
    $sql .= ' AND l.listing_type = :ltype';
    $params[':ltype'] = $listingType;
}
if ($minPrice !== null) {
    $sql .= ' AND l.price >= :minp';
    $params[':minp'] = $minPrice;
}
if ($maxPrice !== null) {
    $sql .= ' AND l.price <= :maxp';
    $params[':maxp'] = $maxPrice;
}
if ($stateId !== null) {
    $sql .= ' AND l.state_id = :sid';
    $params[':sid'] = $stateId;
}
if ($districtId !== null) {
    $sql .= ' AND l.district_id = :did';
    $params[':did'] = $districtId;
}
if ($search !== '') {
    $sql .= ' AND (l.title LIKE :q OR l.description LIKE :q2)';
    $likeQ = '%' . $search . '%';
    $params[':q']  = $likeQ;
    $params[':q2'] = $likeQ;
}
if ($cursor > 0) {
    $sql .= ' AND l.id < :cursor';
    $params[':cursor'] = $cursor;
}

/* ── geo filter ───────────────────────────────────────────── */

if ($lat !== null && $lng !== null) {
    $sql .= '
  AND l.latitude IS NOT NULL
  AND (6371 * ACOS(
        COS(RADIANS(:glat)) * COS(RADIANS(l.latitude)) *
        COS(RADIANS(l.longitude) - RADIANS(:glng)) +
        SIN(RADIANS(:glat2)) * SIN(RADIANS(l.latitude))
      )) <= :grad';
    $params[':glat']  = $lat;
    $params[':glng']  = $lng;
    $params[':glat2'] = $lat;
    $params[':grad']  = $radiusKm;
}

/* ── order ────────────────────────────────────────────────── */

$orderParts = [];
if ($featuredFirst) {
    $orderParts[] = 'l.is_featured DESC';
}
if ($lat !== null && $lng !== null) {
    // closest first among geo-filtered results
    $orderParts[] = '(6371 * ACOS(
        COS(RADIANS(:olat)) * COS(RADIANS(l.latitude)) *
        COS(RADIANS(l.longitude) - RADIANS(:olng)) +
        SIN(RADIANS(:olat2)) * SIN(RADIANS(l.latitude))
      )) ASC';
    $params[':olat']  = $lat;
    $params[':olng']  = $lng;
    $params[':olat2'] = $lat;
}
$orderParts[] = 'l.created_at DESC';
$sql .= ' ORDER BY ' . implode(', ', $orderParts);
$sql .= ' LIMIT :lim';
$params[':lim'] = $limit + 1; // fetch one extra to detect next page

/* ── execute ──────────────────────────────────────────────── */

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($k, $v, $type);
}
$stmt->execute();
$rows = $stmt->fetchAll();

$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);

$nextCursor = $hasMore && !empty($rows) ? (int)end($rows)['id'] : null;

/* ── format ───────────────────────────────────────────────── */

$listings = [];
foreach ($rows as $r) {
    $images = [];
    if (!empty($r['images'])) {
        $decoded = json_decode($r['images'], true);
        if (is_array($decoded)) $images = $decoded;
    }
    $listings[] = [
        'id'              => (int)$r['id'],
        'title'           => $r['title'],
        'thumb'           => $images[0] ?? null,
        'images'          => $images,
        'price'           => $r['price'] !== null ? (float)$r['price'] : null,
        'price_negotiable'=> (bool)$r['price_negotiable'],
        'price_type'      => $r['price_type'],
        'listing_type'    => $r['listing_type'],
        'city'            => $r['city'],
        'state_id'        => $r['state_id'] ? (int)$r['state_id'] : null,
        'district_id'     => $r['district_id'] ? (int)$r['district_id'] : null,
        'category'        => $r['category'],
        'category_hi'     => $r['category_hi'],
        'category_icon'   => $r['category_icon'],
        'is_featured'     => (bool)$r['is_featured'],
        'views_count'     => (int)$r['views_count'],
        'saves_count'     => (int)$r['saves_count'],
        'created_at'      => $r['created_at'],
    ];
}

echo json_encode([
    'listings'    => $listings,
    'has_more'    => $hasMore,
    'next_cursor' => $nextCursor,
    'count'       => count($listings),
]);
