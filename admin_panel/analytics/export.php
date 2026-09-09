<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=analytics_export.csv');

$output=fopen('php://output','w');
fputcsv($output,['Title','Views']);

$stmt=$pdo->prepare("SELECT title,views FROM news ORDER BY views DESC");
$stmt->execute();

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
    fputcsv($output,$row);
}

fclose($output);
exit;
