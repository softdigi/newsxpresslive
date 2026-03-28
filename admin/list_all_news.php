<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../../auth/firebase.php";
require __DIR__ . "/../geo/response.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['admin_uid'])) {
    jsonResponse(false, [], "admin_uid required");
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

$stmt = $pdo->prepare(
    "SELECT id, title, status, is_breaking, is_featured, created_at
     FROM news
     ORDER BY created_at DESC"
);
$stmt->execute();

jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), "all news list");
