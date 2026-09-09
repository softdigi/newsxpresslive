<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'agency'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['admin']['role'];
$admin_id = (int) $_SESSION['admin']['id'];

// Build WHERE with same filters as index.php
$search_name   = trim($_GET['name'] ?? '');
$search_email  = trim($_GET['email'] ?? '');
$search_status = trim($_GET['status'] ?? '');
$search_agency = trim($_GET['agency_id'] ?? '');

$where = ["u.role = 'reporter'"];
$params = [];

if ($role === 'agency') {
    $where[] = 'u.agency_id = :agency_filter';
    $params[':agency_filter'] = $admin_id;
}

if ($search_name !== '') {
    $where[] = 'u.name LIKE :sname';
    $params[':sname'] = '%' . $search_name . '%';
}
if ($search_email !== '') {
    $where[] = 'u.email LIKE :semail';
    $params[':semail'] = '%' . $search_email . '%';
}
if ($search_status !== '') {
    $where[] = 'u.status = :sstatus';
    $params[':sstatus'] = $search_status;
}
if ($search_agency !== '' && $role !== 'agency') {
    $where[] = 'u.agency_id = :sagency';
    $params[':sagency'] = (int) $search_agency;
}

$where_sql = implode(' AND ', $where);

$sql = "SELECT u.id, u.name, u.email, u.status, u.agency_id, u.created_at,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
            COALESCE(SUM(n.views), 0) AS total_views,
            COALESCE(vb.total_earnings, 0) AS total_earnings
        FROM admin_users u
        LEFT JOIN news n ON n.reporter_id = u.id
        LEFT JOIN (
            SELECT reporter_id, SUM(reporter_bonus) AS total_earnings
            FROM viral_boosts
            GROUP BY reporter_id
        ) vb ON vb.reporter_id = u.id
        WHERE {$where_sql}
        GROUP BY u.id
        ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reporters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
$filename = 'reporters_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    'ID', 'Name', 'Email', 'Status', 'Agency ID', 'Created At',
    'Total News', 'Approved News', 'Rejected News', 'Total Views', 'Total Earnings'
]);

foreach ($reporters as $r) {
    fputcsv($output, [
        $r['id'],
        $r['name'],
        $r['email'],
        $r['status'],
        $r['agency_id'],
        $r['created_at'],
        $r['total_news'],
        $r['approved_news'],
        $r['rejected_news'],
        $r['total_views'],
        number_format((float)$r['total_earnings'], 2, '.', ''),
    ]);
}

fclose($output);
exit;
