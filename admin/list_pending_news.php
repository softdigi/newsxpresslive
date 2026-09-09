<?php
header("Content-Type: application/json");
require __DIR__."/../geo/config.php";
require __DIR__."/../geo/response.php";

if ($_SERVER['REQUEST_METHOD']!=='POST') {
 jsonResponse(false,[],"POST required");
}

$input=json_decode(file_get_contents("php://input"),true);
$admin_uid=$input['admin_uid']??null;
$page=$input['page']??1;
$limit=20;
$offset=($page-1)*$limit;

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Fetch pending news */
$stmt=$pdo->prepare(
"SELECT n.id,n.title,n.created_at,
 u.name AS author,
 c.name AS category
 FROM news n
 LEFT JOIN users u ON u.id=n.user_id
 LEFT JOIN categories c ON c.id=n.category_id
 WHERE n.status='pending'
 ORDER BY n.created_at DESC
 LIMIT $limit OFFSET $offset"
);
$stmt->execute();

jsonResponse(true,$stmt->fetchAll(PDO::FETCH_ASSOC));
