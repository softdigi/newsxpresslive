<?php
// ============================================================
// cron/track_agency_revenue.php
//
// Daily cron — updates impressions/clicks for agency articles
// and calls the calculate_agency_revenue() stored procedure.
//
// Schedule (crontab):
//   0 2 * * * php /path/to/cron/track_agency_revenue.php >> /var/log/agency_revenue.log 2>&1
//
// Revenue calculation strategy (in priority order):
//   1. AdMob/Firebase Reporting API  — if credentials configured
//   2. Manual CPM/CPC from admob_rates table — fallback
//
// Environment variables used:
//   ADMOB_CLIENT_ID, ADMOB_CLIENT_SECRET, ADMOB_REFRESH_TOKEN
//   ADMOB_PUBLISHER_ID   (e.g. pub-XXXXXXXXXXXXXXXX)
//   ADMIN_EMAIL          (receives daily summary)
//   APP_BASE_URL         (used in email links)
//
// Requires:
//   - admob_rates table (migration_v4_admob_payout.sql)
//   - agency_revenue table (migration_v3_agency_partner.sql)
//   - news.total_impressions, news.total_clicks columns
//   - calculate_agency_revenue(p_date DATE) stored procedure
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

define('REVENUE_CRON_DATE', date('Y-m-d'));   // today — override via argv[1]
$targetDate = $argv[1] ?? REVENUE_CRON_DATE;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    echo '[' . ts() . '] ERROR: Invalid date argument. Use YYYY-MM-DD.' . PHP_EOL;
    exit(1);
}

echo '[' . ts() . "] Starting revenue tracking for date: {$targetDate}" . PHP_EOL;

// ── Step 1: Resolve daily CPM / CPC rates ────────────────────────────────────
$rates = resolveDailyRates($pdo, $targetDate);
echo '[' . ts() . "] Using rates — CPM: ₹{$rates['cpm_rate']} | CPC: ₹{$rates['cpc_rate']} | source: {$rates['platform']}" . PHP_EOL;

// ── Step 2: Fetch impressions/clicks from AdMob API (if available) ────────────
$apiData = fetchAdmobApiData($targetDate);

// ── Step 3: Update news.total_impressions and news.total_clicks ───────────────
$updateCount = 0;
if (!empty($apiData)) {
    $updateCount = updateFromApiData($pdo, $apiData);
    echo '[' . ts() . "] Updated {$updateCount} article(s) from AdMob API." . PHP_EOL;
} else {
    echo '[' . ts() . '] No AdMob API data available — skipping per-article impression update.' . PHP_EOL;
}

// ── Step 4: Call calculate_agency_revenue stored procedure ───────────────────
try {
    $stmt = $pdo->prepare('CALL calculate_agency_revenue(:p_date)');
    $stmt->execute([':p_date' => $targetDate]);
    echo '[' . ts() . "] calculate_agency_revenue('{$targetDate}') executed successfully." . PHP_EOL;
} catch (PDOException $e) {
    echo '[' . ts() . '] ERROR calling calculate_agency_revenue: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ── Step 5: Build summary report ─────────────────────────────────────────────
$summary = buildSummaryReport($pdo, $targetDate);
echo '[' . ts() . "] Summary: {$summary['agencies']} agencies | impressions: {$summary['impressions']} | "
    . "clicks: {$summary['clicks']} | gross_revenue: ₹{$summary['gross_revenue']} | "
    . "agency_share: ₹{$summary['agency_share']}" . PHP_EOL;

// ── Step 6: Email admin ───────────────────────────────────────────────────────
$adminEmail = getenv('ADMIN_EMAIL') ?: null;
if ($adminEmail) {
    sendAdminReport($adminEmail, $targetDate, $summary, $rates);
    echo '[' . ts() . "] Daily revenue report emailed to {$adminEmail}." . PHP_EOL;
} else {
    echo '[' . ts() . '] ADMIN_EMAIL not set — skipping email.' . PHP_EOL;
}

echo '[' . ts() . '] Revenue tracking complete.' . PHP_EOL;
exit(0);

// ── Helper functions ──────────────────────────────────────────────────────────

function ts(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Resolve CPM/CPC rates for $date.
 * Checks admob_rates table; falls back to safe defaults if none found.
 */
function resolveDailyRates(PDO $pdo, string $date): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT cpm_rate, cpc_rate, platform
             FROM   admob_rates
             WHERE  rate_date <= ?
             ORDER BY rate_date DESC
             LIMIT 1'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    } catch (PDOException $e) {
        echo '[' . ts() . '] WARNING: admob_rates query failed — ' . $e->getMessage() . PHP_EOL;
    }

    // Default safe fallback
    return ['cpm_rate' => '1.50', 'cpc_rate' => '0.50', 'platform' => 'default'];
}

/**
 * Attempt to fetch impression/click data from the AdMob Reporting API.
 * Returns associative array keyed by custom_event_label (article external_id).
 * Returns empty array if credentials are not configured or API call fails.
 *
 * AdMob API v1: https://developers.google.com/admob/api/v1
 */
function fetchAdmobApiData(string $date): array
{
    $clientId     = getenv('ADMOB_CLIENT_ID');
    $clientSecret = getenv('ADMOB_CLIENT_SECRET');
    $refreshToken = getenv('ADMOB_REFRESH_TOKEN');
    $publisherId  = getenv('ADMOB_PUBLISHER_ID');

    if (!$clientId || !$clientSecret || !$refreshToken || !$publisherId) {
        return [];
    }

    // Refresh OAuth2 access token
    $tokenResp = _httpPost('https://oauth2.googleapis.com/token', [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
    ]);
    if (empty($tokenResp['access_token'])) {
        echo '[' . ts() . '] WARNING: Failed to obtain AdMob access token.' . PHP_EOL;
        return [];
    }
    $accessToken = $tokenResp['access_token'];

    // Build date parts
    [$y, $m, $d] = explode('-', $date);

    $reportBody = [
        'report_spec' => [
            'date_range' => [
                'start_date' => ['year' => (int)$y, 'month' => (int)$m, 'day' => (int)$d],
                'end_date'   => ['year' => (int)$y, 'month' => (int)$m, 'day' => (int)$d],
            ],
            'dimensions'  => ['DATE', 'CUSTOM_EVENT_LABEL'],
            'metrics'     => ['IMPRESSIONS', 'CLICKS'],
            'sort_conditions' => [['dimension' => 'DATE', 'order' => 'DESCENDING']],
        ],
    ];

    $url     = "https://admob.googleapis.com/v1/accounts/{$publisherId}/networkReport:generate";
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($reportBody),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || !$raw) {
        echo '[' . ts() . '] WARNING: AdMob API request failed.' . PHP_EOL;
        return [];
    }

    $lines  = array_filter(explode("\n", $raw));
    $result = [];
    foreach ($lines as $line) {
        $obj = json_decode(trim($line), true);
        if (empty($obj['row'])) {
            continue;
        }
        $dimensionValues = $obj['row']['dimensionValues'] ?? [];
        $metricValues    = $obj['row']['metricValues'] ?? [];
        $label           = $dimensionValues['CUSTOM_EVENT_LABEL']['value'] ?? '';
        if (empty($label)) {
            continue;
        }
        $result[$label] = [
            'impressions' => (int)($metricValues['IMPRESSIONS']['integerValue'] ?? 0),
            'clicks'      => (int)($metricValues['CLICKS']['integerValue'] ?? 0),
        ];
    }

    return $result;
}

/**
 * Update news.total_impressions and news.total_clicks from API data.
 * $apiData is keyed by external_id (stored as agency_external_id in news).
 */
function updateFromApiData(PDO $pdo, array $apiData): int
{
    $updated = 0;
    $stmt    = $pdo->prepare(
        'UPDATE news
         SET    total_impressions = total_impressions + ?,
                total_clicks      = total_clicks      + ?
         WHERE  agency_external_id = ?
           AND  agency_id IS NOT NULL'
    );
    foreach ($apiData as $externalId => $counts) {
        try {
            $stmt->execute([
                $counts['impressions'],
                $counts['clicks'],
                $externalId,
            ]);
            $updated += (int)$stmt->rowCount();
        } catch (PDOException $e) {
            echo '[' . ts() . "] WARNING: Could not update article '{$externalId}': " . $e->getMessage() . PHP_EOL;
        }
    }
    return $updated;
}

/**
 * Aggregate a day's revenue data for admin summary.
 */
function buildSummaryReport(PDO $pdo, string $date): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT agency_id)              AS agencies,
                    COALESCE(SUM(total_impressions), 0)    AS impressions,
                    COALESCE(SUM(total_clicks), 0)         AS clicks,
                    COALESCE(SUM(gross_revenue), 0)        AS gross_revenue,
                    COALESCE(SUM(agency_share), 0)         AS agency_share
             FROM   agency_revenue
             WHERE  revenue_date = ?'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch();

        return [
            'agencies'      => (int)($row['agencies']      ?? 0),
            'impressions'   => (int)($row['impressions']   ?? 0),
            'clicks'        => (int)($row['clicks']        ?? 0),
            'gross_revenue' => number_format((float)($row['gross_revenue'] ?? 0), 2),
            'agency_share'  => number_format((float)($row['agency_share']  ?? 0), 2),
        ];
    } catch (PDOException $e) {
        echo '[' . ts() . '] WARNING: Summary query failed — ' . $e->getMessage() . PHP_EOL;
        return ['agencies' => 0, 'impressions' => 0, 'clicks' => 0, 'gross_revenue' => '0.00', 'agency_share' => '0.00'];
    }
}

/**
 * Send daily revenue summary email to admin.
 */
function sendAdminReport(string $email, string $date, array $summary, array $rates): void
{
    $baseUrl = getenv('APP_BASE_URL') ?: 'https://newsxpresslive.com';
    $subject = "[NewsXpressLive] Daily Agency Revenue Report — {$date}";

    $body  = "Daily Agency Revenue Report\n";
    $body .= "Date: {$date}\n\n";
    $body .= "Agencies active    : {$summary['agencies']}\n";
    $body .= "Total Impressions  : {$summary['impressions']}\n";
    $body .= "Total Clicks       : {$summary['clicks']}\n";
    $body .= "Gross Revenue      : ₹{$summary['gross_revenue']}\n";
    $body .= "Agency Share       : ₹{$summary['agency_share']}\n\n";
    $body .= "Rates used         : CPM ₹{$rates['cpm_rate']} | CPC ₹{$rates['cpc_rate']} ({$rates['platform']})\n\n";
    $body .= "Admin Panel        : {$baseUrl}/admin/revenue\n";

    $headers = implode("\r\n", [
        'From: noreply@newsxpresslive.com',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: NewsXpressLive-Cron/1.0',
    ]);

    @mail($email, $subject, $body, $headers);
}

/**
 * Lightweight HTTP POST helper (no Guzzle dependency).
 */
function _httpPost(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ? (json_decode($body, true) ?? []) : [];
}
