<?php
/**
 * web/api/mandi/nearby.php
 *
 * GET ?lat=26.8&lng=80.9&radius_km=100
 *
 * Returns nearby mandis with distance and today's top commodity previews.
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

$lat      = isset($_GET['lat'])       ? (float)$_GET['lat']       : null;
$lng      = isset($_GET['lng'])       ? (float)$_GET['lng']       : null;
$radius   = isset($_GET['radius_km']) ? min(500, max(1, (float)$_GET['radius_km'])) : 100.0;
$limit    = max(1, min(20, (int)($_GET['limit'] ?? 10)));

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['error' => 'lat and lng required']);
    exit;
}

/* ── Haversine via MySQL ──────────────────────────────────── */

$stmt = $pdo->prepare(
    'SELECT id, name, name_hi, city, latitude, longitude,
            (6371 * ACOS(
                COS(RADIANS(:lat)) * COS(RADIANS(latitude)) *
                COS(RADIANS(longitude) - RADIANS(:lng)) +
                SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
            )) AS distance_km
     FROM mandis
     WHERE is_active = 1
       AND latitude IS NOT NULL
     HAVING distance_km <= :radius
     ORDER BY distance_km ASC
     LIMIT :lim'
);
$stmt->bindValue(':lat',    $lat);
$stmt->bindValue(':lng',    $lng);
$stmt->bindValue(':lat2',   $lat);
$stmt->bindValue(':radius', $radius);
$stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);
$stmt->execute();
$mandis = $stmt->fetchAll();

/* ── today's top 3 commodity rates per mandi ─────────────── */

$today = date('Y-m-d');
$result = [];
foreach ($mandis as $m) {
    $rStmt = $pdo->prepare(
        'SELECT c.name_hi AS commodity, r.modal_price, c.unit
         FROM mandi_rates r
         JOIN commodities c ON c.id = r.commodity_id
         WHERE r.mandi_id = ? AND r.rate_date = ?
         ORDER BY r.modal_price DESC
         LIMIT 3'
    );
    $rStmt->execute([(int)$m['id'], $today]);
    $preview = $rStmt->fetchAll();

    $result[] = [
        'id'          => (int)$m['id'],
        'name'        => $m['name'],
        'name_hi'     => $m['name_hi'],
        'city'        => $m['city'],
        'distance_km' => round((float)$m['distance_km'], 1),
        'preview'     => $preview,
    ];
}

echo json_encode(['mandis' => $result, 'total' => count($result)]);
