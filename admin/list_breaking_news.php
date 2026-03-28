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
"SELECT id,title,is_breaking,is_featured,created_at
 FROM news
 WHERE is_breaking=1 OR is_featured=1
 ORDER BY updated_at DESC"
);

jsonResponse(true,$stmt->fetchAll(PDO::FETCH_ASSOC));
