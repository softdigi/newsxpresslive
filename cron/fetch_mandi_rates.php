<?php
/**
 * cron/fetch_mandi_rates.php
 *
 * Daily cron: Fetch commodity rates from AgMarknet and upsert into mandi_rates.
 * Manual entries (source='manual') are NOT overwritten.
 *
 * Schedule: 0 9 * * *  (9:00 AM daily)
 * CLI usage: php cron/fetch_mandi_rates.php [--state=UP] [--date=YYYY-MM-DD]
 *
 * NOTE: AgMarknet does not provide an official public REST API.
 * This script uses the unofficial CSV download endpoint. Replace the URL
 * and parsing logic with an official integration if one becomes available.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';

/* ── CLI args ─────────────────────────────────────────────── */
$opts  = getopt('', ['state:', 'date:']);
$state = $opts['state'] ?? 'Uttar Pradesh';
$date  = isset($opts['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $opts['date'])
    ? $opts['date'] : date('Y-m-d');

echo "[{$date}] Fetching AgMarknet data for: {$state}\n";

/* ── AgMarknet URL (unofficial CSV endpoint) ──────────────── */
// Replace with official API URL when available.
// Example URL pattern for reference only:
$agmarknetUrl = sprintf(
    'https://agmarknet.gov.in/SearchCommodityWise.aspx?'
    . 'Tx_Commodity=0&Tx_State=0&Tx_District=0&Tx_Market=0'
    . '&DateFrom=%s&DateTo=%s&Fr_Date=%s&To_Date=%s'
    . '&Tx_Trend=2&Tx_CommodityHead=All+Commodities&Tx_StateHead=All+States'
    . '&Tx_DistrictHead=All+Districts&Tx_MarketHead=All+Markets',
    date('d-%b-%Y', strtotime($date)),
    date('d-%b-%Y', strtotime($date)),
    $date, $date
);

/* ── fetch (with timeout) ─────────────────────────────────── */
$ch = curl_init($agmarknetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'NewsXpressLive-DataBot/1.0',
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false || $httpCode !== 200) {
    echo "ERROR: Could not fetch AgMarknet data (HTTP {$httpCode}). Exiting.\n";
    exit(1);
}

/* ── Parse HTML table ─────────────────────────────────────── */
// AgMarknet returns an HTML page with a data table.
// Extract rows matching: State, District, Market, Commodity, Min, Max, Modal
$rows = parseAgmarknetTable($html);

if (empty($rows)) {
    echo "No data parsed from AgMarknet for {$date}.\n";
    exit(0);
}

/* ── commodity & mandi lookup maps ───────────────────────────
   We match AgMarknet commodity names to our commodities table.
   AgMarknet market names are matched to mandis by city name.    */

$commodityMap = buildCommodityMap($pdo);
$mandiMap     = buildMandiMap($pdo);

/* ── upsert ───────────────────────────────────────────────── */
$stmt = $pdo->prepare(
    'INSERT INTO mandi_rates
         (mandi_id, commodity_id, rate_date, min_price, max_price, modal_price, arrivals_tonnes, source)
     VALUES (:mid, :cid, :date, :min, :max, :modal, :arr, \'api\')
     ON DUPLICATE KEY UPDATE
         min_price       = IF(source = \'manual\', min_price, VALUES(min_price)),
         max_price       = IF(source = \'manual\', max_price, VALUES(max_price)),
         modal_price     = IF(source = \'manual\', modal_price, VALUES(modal_price)),
         source          = IF(source = \'manual\', \'manual\', \'api\')'
);

$inserted = 0;
foreach ($rows as $r) {
    $commodityId = matchCommodity($r['commodity'], $commodityMap);
    $mandiId     = matchMandi($r['market'], $r['district'], $mandiMap);
    if ($commodityId === null || $mandiId === null) continue;

    try {
        $stmt->execute([
            ':mid'   => $mandiId,
            ':cid'   => $commodityId,
            ':date'  => $date,
            ':min'   => $r['min_price'],
            ':max'   => $r['max_price'],
            ':modal' => $r['modal_price'],
            ':arr'   => $r['arrivals'] ?? null,
        ]);
        $inserted++;
    } catch (PDOException $e) {
        // Silently skip duplicates
    }
}

echo "Done. {$inserted} rate(s) upserted.\n";

/* ════════════════════════════════════════════════════════════
   Helpers
   ════════════════════════════════════════════════════════════ */

function parseAgmarknetTable(string $html): array {
    $rows = [];
    // Attempt DOMDocument parse
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    // Target the main data table — selector may need updating if site changes
    $trs = $xpath->query('//table[@id="cphBody_gridRecords"]//tr');
    if ($trs === false || $trs->length === 0) return $rows;

    foreach ($trs as $i => $tr) {
        if ($i === 0) continue; // skip header
        $tds = $tr->childNodes;
        $cells = [];
        foreach ($tds as $td) {
            if ($td->nodeName === 'td') {
                $cells[] = trim($td->textContent);
            }
        }
        // Expected columns: State, District, Market, Commodity, Variety, Group, Min, Max, Modal
        if (count($cells) < 9) continue;
        $rows[] = [
            'state'       => $cells[0],
            'district'    => $cells[1],
            'market'      => $cells[2],
            'commodity'   => $cells[3],
            'min_price'   => (float)str_replace(',', '', $cells[6]),
            'max_price'   => (float)str_replace(',', '', $cells[7]),
            'modal_price' => (float)str_replace(',', '', $cells[8]),
            'arrivals'    => null,
        ];
    }
    return $rows;
}

function buildCommodityMap(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query('SELECT id, name, name_hi FROM commodities')->fetchAll() as $c) {
        $map[strtolower($c['name'])]    = (int)$c['id'];
        $map[strtolower($c['name_hi'])] = (int)$c['id'];
    }
    return $map;
}

function buildMandiMap(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query('SELECT id, name, city FROM mandis WHERE is_active=1')->fetchAll() as $m) {
        $key = strtolower(trim($m['city'] ?: $m['name']));
        $map[$key] = (int)$m['id'];
    }
    return $map;
}

function matchCommodity(string $name, array $map): ?int {
    return $map[strtolower(trim($name))] ?? null;
}

function matchMandi(string $market, string $district, array $map): ?int {
    return $map[strtolower(trim($market))]
        ?? $map[strtolower(trim($district))]
        ?? null;
}
