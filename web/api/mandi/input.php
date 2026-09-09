<?php
/**
 * web/api/mandi/input.php
 *
 * Admin / trusted endpoint to enter mandi rates manually.
 *
 * POST — Single or bulk rate entry.
 *   Content-Type: application/json
 *   Body: {
 *     "api_key": "...",
 *     "rates": [
 *       {
 *         "mandi_id": 1,
 *         "commodity_id": 1,
 *         "rate_date": "2025-01-15",
 *         "min_price": 2100,
 *         "max_price": 2350,
 *         "modal_price": 2200,
 *         "arrivals_tonnes": 450
 *       },
 *       ...
 *     ]
 *   }
 *
 *   OR CSV upload:
 *   Content-Type: multipart/form-data
 *   Field: api_key, csv_file
 *   CSV columns: mandi_id,commodity_id,rate_date,min_price,max_price,modal_price,arrivals_tonnes
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

/* ── simple API key auth ──────────────────────────────────── */

define('MANDI_ADMIN_KEY', getenv('MANDI_ADMIN_KEY') ?: 'change-mandi-admin-key');

/* ── CSV upload path ──────────────────────────────────────── */

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isCsv       = str_contains($contentType, 'multipart/form-data');

if ($isCsv) {
    $apiKey = trim($_POST['api_key'] ?? '');
    if ($apiKey !== MANDI_ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (empty($_FILES['csv_file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'csv_file required']);
        exit;
    }

    $rows = [];
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $header  = fgetcsv($handle);
    while (($line = fgetcsv($handle)) !== false) {
        if (count($line) < 6) continue;
        $rows[] = array_combine(['mandi_id','commodity_id','rate_date','min_price','max_price','modal_price','arrivals_tonnes'], array_pad($line, 7, null));
    }
    fclose($handle);
} else {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $apiKey = trim($body['api_key'] ?? '');
    if ($apiKey !== MANDI_ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $rows = $body['rates'] ?? [];
}

if (empty($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'No rates provided']);
    exit;
}

/* ── validate & insert ────────────────────────────────────── */

$stmt = $pdo->prepare(
    'INSERT INTO mandi_rates
         (mandi_id, commodity_id, rate_date, min_price, max_price, modal_price, arrivals_tonnes, source)
     VALUES (:mid, :cid, :date, :min, :max, :modal, :arr, :src)
     ON DUPLICATE KEY UPDATE
         min_price       = IF(source = \'api\', min_price, VALUES(min_price)),
         max_price       = IF(source = \'api\', max_price, VALUES(max_price)),
         modal_price     = IF(source = \'api\', modal_price, VALUES(modal_price)),
         arrivals_tonnes = VALUES(arrivals_tonnes),
         source          = VALUES(source)'
);

$inserted = 0;
$errors   = [];

foreach ($rows as $i => $r) {
    $mandiId     = isset($r['mandi_id'])     ? (int)$r['mandi_id']     : 0;
    $commodityId = isset($r['commodity_id']) ? (int)$r['commodity_id'] : 0;
    $rateDate    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['rate_date'] ?? '') ? $r['rate_date'] : null;
    $minPrice    = isset($r['min_price'])    ? (float)$r['min_price']   : null;
    $maxPrice    = isset($r['max_price'])    ? (float)$r['max_price']   : null;
    $modalPrice  = isset($r['modal_price'])  ? (float)$r['modal_price'] : null;
    $arrivals    = isset($r['arrivals_tonnes']) ? (float)$r['arrivals_tonnes'] : null;

    if ($mandiId <= 0 || $commodityId <= 0 || !$rateDate || $minPrice === null || $maxPrice === null || $modalPrice === null) {
        $errors[] = "Row $i: invalid data";
        continue;
    }

    try {
        $stmt->execute([
            ':mid'   => $mandiId,
            ':cid'   => $commodityId,
            ':date'  => $rateDate,
            ':min'   => $minPrice,
            ':max'   => $maxPrice,
            ':modal' => $modalPrice,
            ':arr'   => $arrivals,
            ':src'   => 'manual',
        ]);
        $inserted++;
    } catch (PDOException $e) {
        $errors[] = "Row $i: DB error";
    }
}

echo json_encode([
    'success'  => $inserted > 0,
    'inserted' => $inserted,
    'errors'   => $errors,
]);
