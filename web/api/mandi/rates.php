<?php
/**
 * web/api/mandi/rates.php
 *
 * GET — Fetch mandi commodity rates.
 *
 * Params:
 *   mandi_id     int       Required (unless lat+lng provided)
 *   lat          float     Latitude  (find nearest mandi)
 *   lng          float     Longitude (find nearest mandi)
 *   commodity_id int       Optional — filter to single commodity
 *   date         string    YYYY-MM-DD (default: today)
 *   days         int       1–90 — also return last N days trend (default: 1)
 *   category     string    grain|vegetable|fruit|spice|oilseed|other
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

/* ── helpers ──────────────────────────────────────────────── */

/**
 * Haversine distance (km) between two lat/lng points.
 */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return $R * 2 * asin(sqrt($a));
}

/* ── resolve mandi ────────────────────────────────────────── */

$mandiId     = isset($_GET['mandi_id']) && ctype_digit($_GET['mandi_id']) ? (int)$_GET['mandi_id'] : null;
$lat         = isset($_GET['lat'])  ? (float)$_GET['lat']  : null;
$lng         = isset($_GET['lng'])  ? (float)$_GET['lng']  : null;
$distanceKm  = null;

if ($mandiId === null && $lat !== null && $lng !== null) {
    // Find nearest active mandi
    $mandis = $pdo->query(
        'SELECT id, latitude, longitude FROM mandis WHERE is_active = 1 AND latitude IS NOT NULL'
    )->fetchAll();
    $best = null;
    $bestDist = PHP_FLOAT_MAX;
    foreach ($mandis as $m) {
        $d = haversine($lat, $lng, (float)$m['latitude'], (float)$m['longitude']);
        if ($d < $bestDist) {
            $bestDist = $d;
            $best     = $m['id'];
        }
    }
    if ($best === null) {
        echo json_encode(['error' => 'No mandi found nearby']);
        exit;
    }
    $mandiId    = $best;
    $distanceKm = round($bestDist, 1);
}

if ($mandiId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'mandi_id or lat+lng required']);
    exit;
}

/* ── mandi info ───────────────────────────────────────────── */

$mandi = $pdo->prepare('SELECT id, name, name_hi, city FROM mandis WHERE id = ? AND is_active = 1');
$mandi->execute([$mandiId]);
$mandiRow = $mandi->fetch();
if (!$mandiRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Mandi not found']);
    exit;
}

/* ── params ───────────────────────────────────────────────── */

$date        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$days        = max(1, min(90, (int)($_GET['days'] ?? 1)));
$commodityId = isset($_GET['commodity_id']) && ctype_digit($_GET['commodity_id']) ? (int)$_GET['commodity_id'] : null;
$category    = in_array($_GET['category'] ?? '', ['grain','vegetable','fruit','spice','oilseed','other'], true)
    ? $_GET['category'] : null;

/* ── today's rates ────────────────────────────────────────── */

$sql = '
SELECT
    c.id        AS commodity_id,
    c.name      AS commodity_en,
    c.name_hi   AS commodity,
    c.category,
    c.unit,
    c.msp,
    r.min_price,
    r.max_price,
    r.modal_price,
    r.arrivals_tonnes,
    r.rate_date
FROM mandi_rates r
JOIN commodities c ON c.id = r.commodity_id
WHERE r.mandi_id = :mandi AND r.rate_date = :date
';
$params = [':mandi' => $mandiId, ':date' => $date];

if ($commodityId !== null) {
    $sql .= ' AND r.commodity_id = :cid';
    $params[':cid'] = $commodityId;
}
if ($category !== null) {
    $sql .= ' AND c.category = :cat';
    $params[':cat'] = $category;
}
$sql .= ' ORDER BY c.category, c.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todayRates = $stmt->fetchAll();

/* ── previous day rates for change calculation ────────────── */

$prevDate  = date('Y-m-d', strtotime($date . ' -1 day'));
$prevStmt  = $pdo->prepare(
    'SELECT commodity_id, modal_price FROM mandi_rates
     WHERE mandi_id = ? AND rate_date = ?'
);
$prevStmt->execute([$mandiId, $prevDate]);
$prevMap = [];
foreach ($prevStmt->fetchAll() as $p) {
    $prevMap[$p['commodity_id']] = (float)$p['modal_price'];
}

/* ── build rate cards ─────────────────────────────────────── */

$rateCards = [];
foreach ($todayRates as $r) {
    $modal  = (float)$r['modal_price'];
    $prev   = $prevMap[$r['commodity_id']] ?? null;
    $change = $prev !== null ? round($modal - $prev, 2) : null;
    $pct    = ($prev && $prev > 0) ? round(($change / $prev) * 100, 1) : null;

    $trend = 'stable';
    if ($change !== null) {
        if ($change > 0)  $trend = 'up';
        elseif ($change < 0) $trend = 'down';
    }

    $arrivals = $r['arrivals_tonnes'] !== null
        ? number_format((float)$r['arrivals_tonnes'], 1) . ' tonnes'
        : null;

    $rateCards[] = [
        'commodity_id'     => (int)$r['commodity_id'],
        'commodity'        => $r['commodity'],
        'commodity_en'     => $r['commodity_en'],
        'category'         => $r['category'],
        'unit'             => $r['unit'],
        'min_price'        => (float)$r['min_price'],
        'max_price'        => (float)$r['max_price'],
        'modal_price'      => $modal,
        'msp'              => $r['msp'] !== null ? (float)$r['msp'] : null,
        'change'           => $change,
        'change_percent'   => $pct,
        'trend'            => $trend,
        'arrivals'         => $arrivals,
    ];
}

/* ── optional N-day trend ─────────────────────────────────── */

$trendData = null;
if ($days > 1 && $commodityId !== null) {
    $trendStmt = $pdo->prepare(
        'SELECT rate_date, modal_price, min_price, max_price
         FROM mandi_rates
         WHERE mandi_id = ? AND commodity_id = ? AND rate_date <= ?
         ORDER BY rate_date DESC
         LIMIT ?'
    );
    $trendStmt->execute([$mandiId, $commodityId, $date, $days]);
    $rows = array_reverse($trendStmt->fetchAll());
    $trendData = [
        'dates'  => array_column($rows, 'rate_date'),
        'prices' => array_map(fn($r) => (float)$r['modal_price'], $rows),
        'min'    => array_map(fn($r) => (float)$r['min_price'],   $rows),
        'max'    => array_map(fn($r) => (float)$r['max_price'],   $rows),
    ];
}

/* ── response ─────────────────────────────────────────────── */

echo json_encode([
    'mandi' => [
        'id'          => (int)$mandiRow['id'],
        'name'        => $mandiRow['name'],
        'name_hi'     => $mandiRow['name_hi'],
        'city'        => $mandiRow['city'],
        'distance_km' => $distanceKm,
    ],
    'date'  => $date,
    'rates' => $rateCards,
    'trend' => $trendData,
]);
