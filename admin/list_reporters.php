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
"SELECT id,name,is_verified,points,created_at
 FROM users
 WHERE role='reporter'
 ORDER BY created_at DESC"
);

jsonResponse(true,$stmt->fetchAll(PDO::FETCH_ASSOC));
