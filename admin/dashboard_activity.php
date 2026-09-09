<?php
header("Content-Type: application/json");

require __DIR__."/../geo/config.php";
require __DIR__."/../geo/response.php";

if ($_SERVER['REQUEST_METHOD']!=='POST') {
 jsonResponse(false,[],"POST request required");
}

$input=json_decode(file_get_contents("php://input"),true);
$admin_uid=$input['admin_uid']??null;

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Recent activity */
$recentNews=$pdo->query(
 "SELECT title,status,updated_at
  FROM news
  ORDER BY updated_at DESC
  LIMIT 10"
);

$recentReporters=$pdo->query(
 "SELECT name,created_at
  FROM users
  WHERE role='reporter'
  ORDER BY created_at DESC
  LIMIT 5"
);

jsonResponse(true,[
 "recent_news"=>$recentNews->fetchAll(PDO::FETCH_ASSOC),
 "recent_reporters"=>$recentReporters->fetchAll(PDO::FETCH_ASSOC)
],"activity loaded");
