<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

// Filters (same as index.php)
$search_name   = trim($_GET['name'] ?? '');
$search_email  = trim($_GET['email'] ?? '');
$search_status = trim($_GET['status'] ?? '');

$where = ["a.role = 'agency'"];
$params = [];

if ($search_name !== '') {
    $where[] = 'a.name LIKE :sname';
    $params[':sname'] = '%' . $search_name . '%';
}
if ($search_email !== '') {
    $where[] = 'a.email LIKE :semail';
    $params[':semail'] = '%' . $search_email . '%';
}
if ($search_status !== '') {
    $where[] = 'a.status = :sstatus';
    $params[':sstatus'] = $search_status;
}

$where_sql = implode(' AND ', $where);

$sql = "SELECT a.id, a.name, a.email, a.status, a.created_at,
            COUNT(DISTINCT r.id) AS total_reporters,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
            COALESCE(SUM(n.views), 0) AS total_views,
            COALESCE(earn.total_earnings, 0) AS total_earnings
        FROM admin_users a
        LEFT JOIN admin_users r ON r.agency_id = a.id AND r.role = 'reporter'
        LEFT JOIN news n ON n.reporter_id = r.id
        LEFT JOIN (
            SELECT r2.agency_id, SUM(vb.reporter_bonus) AS total_earnings
            FROM viral_boosts vb
            INNER JOIN admin_users r2 ON r2.id = vb.reporter_id AND r2.role = 'reporter'
            GROUP BY r2.agency_id
        ) earn ON earn.agency_id = a.id
        WHERE {$where_sql}
        GROUP BY a.id
        ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
$filename = 'agencies_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    'ID', 'Name', 'Email', 'Status', 'Created At',
    'Total Reporters', 'Total News', 'Approved News', 'Rejected News',
    'Total Views', 'Total Earnings'
]);

foreach ($agencies as $a) {
    fputcsv($output, [
        $a['id'],
        $a['name'],
        $a['email'],
        $a['status'],
        $a['created_at'],
        $a['total_reporters'],
        $a['total_news'],
        $a['approved_news'],
        $a['rejected_news'],
        $a['total_views'],
        number_format((float)$a['total_earnings'], 2, '.', ''),
    ]);
}

fclose($output);
exit;
