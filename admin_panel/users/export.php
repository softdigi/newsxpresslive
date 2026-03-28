<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=users_export_'.date('Ymd_His').'.csv');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'ID',
    'Name',
    'Email',
    'Role',
    'Agency',
    'Status',
    'Created At'
]);

$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.role, 
           COALESCE(a.name, '') as agency_name,
           u.status, u.created_at
    FROM admin_users u
    LEFT JOIN agencies a ON u.agency_id = a.id
    ORDER BY u.id DESC
");
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['email'],
        $row['role'],
        $row['agency_name'],
        $row['status'],
        $row['created_at']
    ]);
}

fclose($output);
exit;
