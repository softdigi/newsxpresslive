<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../auth/firebase.php";
require __DIR__ . "/response.php";

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['firebase_uid'])) {
    jsonResponse(false, [], "firebase_uid required");
}

$stmt = $pdo->prepare(
    "UPDATE users 
     SET country_id=?, state_id=?, district_id=? 
     WHERE firebase_uid=?"
);

$stmt->execute([
    $input['country_id'] ?? null,
    $input['state_id'] ?? null,
    $input['district_id'] ?? null,
    $input['firebase_uid']
]);

jsonResponse(true, [], "location saved");
