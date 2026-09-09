<?php
/**
 * web/api/mandi/trend.php
 *
 * GET ?commodity_id=1&mandi_id=1&days=30
 *
 * Returns last N days price trend in chart-friendly format,
 * plus MSP reference line.
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

$commodityId = isset($_GET['commodity_id']) && ctype_digit($_GET['commodity_id'])
    ? (int)$_GET['commodity_id'] : null;
$mandiId     = isset($_GET['mandi_id']) && ctype_digit($_GET['mandi_id'])
    ? (int)$_GET['mandi_id'] : null;
$days        = max(7, min(365, (int)($_GET['days'] ?? 30)));

if ($commodityId === null || $mandiId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'commodity_id and mandi_id required']);
    exit;
}

/* ── commodity info (with MSP) ────────────────────────────── */

$commStmt = $pdo->prepare('SELECT id, name, name_hi, unit, msp FROM commodities WHERE id = ?');
$commStmt->execute([$commodityId]);
$commodity = $commStmt->fetch();
if (!$commodity) {
    http_response_code(404);
    echo json_encode(['error' => 'Commodity not found']);
    exit;
}

/* ── trend data ───────────────────────────────────────────── */

$stmt = $pdo->prepare(
    'SELECT rate_date, modal_price, min_price, max_price, arrivals_tonnes
     FROM mandi_rates
     WHERE mandi_id = ? AND commodity_id = ?
     ORDER BY rate_date DESC
     LIMIT ?'
);
$stmt->execute([$mandiId, $commodityId, $days]);
$rows = array_reverse($stmt->fetchAll());

if (empty($rows)) {
    echo json_encode([
        'commodity'  => $commodity,
        'dates'      => [],
        'prices'     => [],
        'min'        => [],
        'max'        => [],
        'arrivals'   => [],
        'msp'        => $commodity['msp'] !== null ? (float)$commodity['msp'] : null,
        'best_month_hint' => null,
    ]);
    exit;
}

$dates    = [];
$prices   = [];
$minArr   = [];
$maxArr   = [];
$arrivals = [];

foreach ($rows as $r) {
    $dates[]    = $r['rate_date'];
    $prices[]   = (float)$r['modal_price'];
    $minArr[]   = (float)$r['min_price'];
    $maxArr[]   = (float)$r['max_price'];
    $arrivals[] = $r['arrivals_tonnes'] !== null ? (float)$r['arrivals_tonnes'] : null;
}

/* ── best month hint (last 12 months, group by month) ─────── */

$bestStmt = $pdo->prepare(
    'SELECT DATE_FORMAT(rate_date, \'%Y-%m\') AS ym,
            AVG(modal_price) AS avg_price
     FROM mandi_rates
     WHERE mandi_id = ? AND commodity_id = ?
       AND rate_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY ym
     ORDER BY avg_price DESC
     LIMIT 1'
);
$bestStmt->execute([$mandiId, $commodityId]);
$bestMonth = $bestStmt->fetch();
$bestMonthHint = null;
if ($bestMonth) {
    $dt = \DateTime::createFromFormat('Y-m', $bestMonth['ym']);
    $bestMonthHint = $dt ? $dt->format('F') . ' mein bhav sabse zyada tha' : null;
}

echo json_encode([
    'commodity'       => [
        'id'      => (int)$commodity['id'],
        'name'    => $commodity['name'],
        'name_hi' => $commodity['name_hi'],
        'unit'    => $commodity['unit'],
    ],
    'dates'           => $dates,
    'prices'          => $prices,
    'min'             => $minArr,
    'max'             => $maxArr,
    'arrivals'        => $arrivals,
    'msp'             => $commodity['msp'] !== null ? (float)$commodity['msp'] : null,
    'best_month_hint' => $bestMonthHint,
]);
