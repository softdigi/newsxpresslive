<?php
// ============================================================
// api/v1/agency/articles.php
// GET /api/v1/agency/articles
//
// List articles submitted by the authenticated agency.
// Supports filtering by status, date range, category.
// Uses cursor-based pagination (after=last_seen_id).
// Each article includes cumulative impressions, clicks, revenue.
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

// ── Query parameters ──────────────────────────────────────────────────────────
$status    = trim($_GET['status']    ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$category  = (int)($_GET['category'] ?? 0);
$after     = (int)($_GET['after']    ?? 0);    // cursor: last seen agency_articles.id
$limit     = min(50, max(1, (int)($_GET['limit'] ?? 20)));

// Validate status filter
$allowedStatuses = ['pending', 'approved', 'rejected'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid status filter. Allowed: pending, approved, rejected']);
    exit;
}

// Validate date formats
if ($dateFrom !== '' && !_validateDate($dateFrom)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid date_from format. Use YYYY-MM-DD']);
    exit;
}
if ($dateTo !== '' && !_validateDate($dateTo)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid date_to format. Use YYYY-MM-DD']);
    exit;
}

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['aa.agency_id = :agency_id'];
$params = [':agency_id' => $agency['id']];

// Cursor pagination
if ($after > 0) {
    $where[]         = 'aa.id < :after';
    $params[':after'] = $after;
}

// Status filter
if ($status !== '') {
    $where[]          = 'aa.status = :status';
    $params[':status'] = $status;
}

// Date range on submission date
if ($dateFrom !== '') {
    $where[]             = 'DATE(aa.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]           = 'DATE(aa.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

// Category filter
if ($category > 0) {
    $where[]             = 'n.category_id = :category_id';
    $params[':category_id'] = $category;
}

$whereClause = implode(' AND ', $where);

$sql = "
    SELECT
        aa.id                   AS agency_article_id,
        aa.news_id,
        aa.external_id,
        aa.source_url,
        aa.submitted_via,
        aa.status               AS submission_status,
        aa.rejection_reason,
        aa.created_at           AS submitted_at,

        n.title,
        n.slug,
        n.description,
        n.image,
        n.status                AS news_status,
        n.category_id,
        n.language,
        n.created_at            AS published_at,

        -- Revenue metrics (from news table, updated daily by cron)
        COALESCE(n.total_impressions, 0)  AS impressions,
        COALESCE(n.total_clicks,      0)  AS clicks,
        COALESCE(n.total_revenue,  0.0000) AS total_revenue,

        -- Category name (if categories table exists)
        COALESCE(c.name, '') AS category_name

    FROM  agency_articles aa
    LEFT JOIN news       n ON n.id          = aa.news_id
    LEFT JOIN categories c ON c.id          = n.category_id
    WHERE {$whereClause}
    ORDER BY aa.id DESC
    LIMIT :lim
";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit + 1, PDO::PARAM_INT);   // fetch one extra to detect has_more
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('agency articles list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch articles']);
    exit;
}

// Determine if there are more results
$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);
}

// Cast numeric fields
foreach ($rows as &$row) {
    $row['agency_article_id'] = (int)$row['agency_article_id'];
    $row['news_id']           = $row['news_id'] ? (int)$row['news_id'] : null;
    $row['category_id']       = $row['category_id'] ? (int)$row['category_id'] : null;
    $row['impressions']       = (int)$row['impressions'];
    $row['clicks']            = (int)$row['clicks'];
    $row['total_revenue']     = (float)$row['total_revenue'];
}
unset($row);

$nextCursor = $hasMore ? end($rows)['agency_article_id'] : null;

echo json_encode([
    'success'     => true,
    'count'       => count($rows),
    'has_more'    => $hasMore,
    'next_cursor' => $nextCursor,
    'articles'    => $rows,
]);

// ── Helpers ───────────────────────────────────────────────────────────────────

function _validateDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
