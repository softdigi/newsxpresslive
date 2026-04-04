<?php
// ============================================================
// api/v1/agency/revenue.php
// GET /api/v1/agency/revenue
//
// Revenue report for the authenticated agency.
//
// Query params:
//   date_from  — YYYY-MM-DD (default: first day of current month)
//   date_to    — YYYY-MM-DD (default: today)
//   group_by   — daily | weekly | monthly  (default: daily)
//
// Response includes per-period breakdowns plus a summary of
// total_earned, wallet_balance, and pending_payout.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);

// ── Parameters ────────────────────────────────────────────────────────────────
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));   // first day of current month
$dateTo   = trim($_GET['date_to']   ?? date('Y-m-d'));    // today
$groupBy  = trim($_GET['group_by']  ?? 'daily');

// Validate dates
foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $name => $val) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ||
        !checkdate((int)substr($val,5,2), (int)substr($val,8,2), (int)substr($val,0,4))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Invalid {$name} format. Use YYYY-MM-DD"]);
        exit;
    }
}

if (strtotime($dateFrom) > strtotime($dateTo)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'date_from must be <= date_to']);
    exit;
}

// Validate group_by
$allowedGroup = ['daily', 'weekly', 'monthly'];
if (!in_array($groupBy, $allowedGroup, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'group_by must be: daily, weekly, or monthly']);
    exit;
}

// ── Build GROUP BY expression ──────────────────────────────────────────────────
$groupExpr = match ($groupBy) {
    'weekly'  => 'YEARWEEK(ar.date, 1)',   // ISO week
    'monthly' => 'DATE_FORMAT(ar.date, \'%Y-%m\')',
    default   => 'ar.date',
};

$periodLabel = match ($groupBy) {
    'weekly'  => 'CONCAT(YEAR(ar.date), \'-W\', LPAD(WEEK(ar.date, 1), 2, \'0\'))',
    'monthly' => 'DATE_FORMAT(ar.date, \'%Y-%m\')',
    default   => 'ar.date',
};

// ── Revenue data query ────────────────────────────────────────────────────────
try {
    $revSql = "
        SELECT
            {$periodLabel}              AS period,
            SUM(ar.impressions)         AS impressions,
            SUM(ar.clicks)              AS clicks,
            SUM(ar.gross_revenue)       AS gross_revenue,
            SUM(ar.agency_share)        AS agency_share,
            SUM(ar.platform_share)      AS platform_share,
            AVG(ar.cpm_rate)            AS avg_cpm_rate,
            AVG(ar.cpc_rate)            AS avg_cpc_rate,
            COUNT(DISTINCT ar.news_id)  AS article_count
        FROM  agency_revenue ar
        WHERE ar.agency_id = :agency_id
          AND ar.date BETWEEN :date_from AND :date_to
        GROUP BY {$groupExpr}
        ORDER BY {$groupExpr} ASC
    ";

    $stmt = $pdo->prepare($revSql);
    $stmt->execute([
        ':agency_id' => $agency['id'],
        ':date_from' => $dateFrom,
        ':date_to'   => $dateTo,
    ]);
    $periods = $stmt->fetchAll();

    // ── Totals for the selected range ─────────────────────────────────────────
    $totalsStmt = $pdo->prepare("
        SELECT
            SUM(impressions)    AS total_impressions,
            SUM(clicks)         AS total_clicks,
            SUM(gross_revenue)  AS total_gross_revenue,
            SUM(agency_share)   AS total_agency_share,
            COUNT(DISTINCT news_id) AS total_articles
        FROM agency_revenue
        WHERE agency_id = ? AND date BETWEEN ? AND ?
    ");
    $totalsStmt->execute([$agency['id'], $dateFrom, $dateTo]);
    $totals = $totalsStmt->fetch();

    // ── Wallet summary (live) ─────────────────────────────────────────────────
    $walletStmt = $pdo->prepare(
        'SELECT wallet_balance, total_earned FROM agencies WHERE id = ? LIMIT 1'
    );
    $walletStmt->execute([$agency['id']]);
    $wallet = $walletStmt->fetch();

    // Pending payout = sum of requested/processing withdrawals
    $pendingStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS pending_payout
         FROM   agency_withdrawals
         WHERE  agency_id = ? AND status IN ('requested','processing')"
    );
    $pendingStmt->execute([$agency['id']]);
    $pendingPayout = (float)$pendingStmt->fetchColumn();

} catch (Throwable $e) {
    error_log('agency revenue error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch revenue data']);
    exit;
}

// ── Cast numerics ─────────────────────────────────────────────────────────────
foreach ($periods as &$p) {
    $p['impressions']    = (int)$p['impressions'];
    $p['clicks']         = (int)$p['clicks'];
    $p['gross_revenue']  = round((float)$p['gross_revenue'], 4);
    $p['agency_share']   = round((float)$p['agency_share'],  4);
    $p['platform_share'] = round((float)$p['platform_share'],4);
    $p['avg_cpm_rate']   = round((float)$p['avg_cpm_rate'],  4);
    $p['avg_cpc_rate']   = round((float)$p['avg_cpc_rate'],  4);
    $p['article_count']  = (int)$p['article_count'];
}
unset($p);

echo json_encode([
    'success'  => true,
    'filters'  => [
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'group_by'  => $groupBy,
    ],
    'summary'  => [
        'total_impressions'   => (int)($totals['total_impressions']   ?? 0),
        'total_clicks'        => (int)($totals['total_clicks']        ?? 0),
        'total_gross_revenue' => round((float)($totals['total_gross_revenue'] ?? 0), 4),
        'total_agency_share'  => round((float)($totals['total_agency_share']  ?? 0), 4),
        'total_articles'      => (int)($totals['total_articles']      ?? 0),
        'total_earned'        => (float)$wallet['total_earned'],
        'wallet_balance'      => (float)$wallet['wallet_balance'],
        'pending_payout'      => $pendingPayout,
    ],
    'periods'  => $periods,
]);
