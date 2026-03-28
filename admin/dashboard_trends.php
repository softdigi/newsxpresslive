<?php
header("Content-Type: application/json");

require __DIR__."/../geo/config.php";
require __DIR__."/../geo/response.php";

if ($_SERVER['REQUEST_METHOD']!=='POST') {
 jsonResponse(false,[],"POST request required");
}

$input=json_decode(file_get_contents("php://input"),true);
$admin_uid=$input['admin_uid']??null;
$days=$input['days']??7;

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Trends */
$newsTrend=$pdo->prepare(
 "SELECT DATE(created_at) as date, COUNT(*) as total
  FROM news
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
  GROUP BY DATE(created_at)"
);
$newsTrend->execute([$days]);

$userTrend=$pdo->prepare(
 "SELECT DATE(created_at) as date, COUNT(*) as total
  FROM users
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
  GROUP BY DATE(created_at)"
);
$userTrend->execute([$days]);

jsonResponse(true,[
 "news"=>$newsTrend->fetchAll(PDO::FETCH_ASSOC),
 "users"=>$userTrend->fetchAll(PDO::FETCH_ASSOC)
],"trends loaded");
