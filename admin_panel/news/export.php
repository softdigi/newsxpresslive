<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

// Filters (same as index.php)
$search_title    = trim($_GET['title'] ?? '');
$filter_status   = trim($_GET['status'] ?? '');
$filter_reporter = trim($_GET['reporter_id'] ?? '');
$filter_agency   = trim($_GET['agency_id'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');

$where = ['1=1'];
$params = [];

if ($search_title !== '') {
    $where[] = 'n.title LIKE :stitle';
    $params[':stitle'] = '%' . $search_title . '%';
}
if ($filter_status !== '') {
    $where[] = 'n.status = :fstatus';
    $params[':fstatus'] = $filter_status;
}
if ($filter_reporter !== '') {
    $where[] = 'n.reporter_id = :freporter';
    $params[':freporter'] = (int) $filter_reporter;
}
if ($filter_agency !== '') {
    $where[] = 'r.agency_id = :fagency';
    $params[':fagency'] = (int) $filter_agency;
}
if ($date_from !== '') {
    $where[] = 'n.created_at >= :dfrom';
    $params[':dfrom'] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where[] = 'n.created_at <= :dto';
    $params[':dto'] = $date_to . ' 23:59:59';
}

$where_sql = implode(' AND ', $where);

$sql = "SELECT n.id, n.title, n.status, n.views, n.is_breaking, n.created_at,
            n.reporter_id,
            COALESCE(r.name, 'Unknown') AS reporter_name,
            COALESCE(r.email, '') AS reporter_email,
            COALESCE(r.agency_id, 0) AS agency_id,
            COALESCE(vb.boost_earnings, 0) AS boost_earnings
        FROM news n
        LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
        LEFT JOIN (
            SELECT reporter_id, SUM(reporter_bonus) AS boost_earnings
            FROM viral_boosts
            GROUP BY reporter_id
        ) vb ON vb.reporter_id = n.reporter_id
        WHERE {$where_sql}
        ORDER BY n.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
$filename = 'news_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'ID', 'Title', 'Status', 'Views', 'Is Breaking', 'Created At',
    'Reporter ID', 'Reporter Name', 'Reporter Email', 'Agency ID', 'Boost Earnings'
]);

foreach ($news_list as $n) {
    fputcsv($output, [
        $n['id'],
        $n['title'],
        $n['status'],
        $n['views'],
        $n['is_breaking'] ? 'Yes' : 'No',
        $n['created_at'],
        $n['reporter_id'],
        $n['reporter_name'],
        $n['reporter_email'],
        $n['agency_id'],
        number_format((float)$n['boost_earnings'], 2, '.', ''),
    ]);
}

fclose($output);
exit;
