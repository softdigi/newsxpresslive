<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=payouts_'.date('Ymd').'.csv');

$output = fopen('php://output','w');

fputcsv($output,['Reporter','Bonus','Status','Date']);

$stmt = $pdo->prepare("
    SELECT r.name, vb.reporter_bonus, vb.status, vb.created_at
    FROM viral_boosts vb
    JOIN admin_users r ON vb.reporter_id=r.id
    WHERE vb.status='paid'
    ORDER BY vb.created_at DESC
");
$stmt->execute();

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
    fputcsv($output,$row);
}

fclose($output);
exit;
