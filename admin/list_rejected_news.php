<?php
header("Content-Type: application/json");
require __DIR__."/../geo/config.php";
require __DIR__."/../geo/response.php";

if ($_SERVER['REQUEST_METHOD']!=='POST') {
 jsonResponse(false,[],"POST required");
}

$input=json_decode(file_get_contents("php://input"),true);
$admin_uid=$input['admin_uid']??null;

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

$stmt=$pdo->query(
"SELECT id,title,created_at
 FROM news
 WHERE status='rejected'
 ORDER BY created_at DESC
 LIMIT 50"
);

jsonResponse(true,$stmt->fetchAll(PDO::FETCH_ASSOC));
