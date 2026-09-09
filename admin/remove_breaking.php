<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../auth/firebase.php";
require __DIR__ . "/../geo/response.php";
require_once __DIR__ . "/../helpers/firebase_rtdb.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['news_id']) || empty($input['admin_uid'])) {
    jsonResponse(false, [], "news_id & admin_uid required");
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Remove breaking */
$stmt = $pdo->prepare(
    "UPDATE news 
     SET is_breaking=0 
     WHERE id=?"
);
$stmt->execute([$input['news_id']]);

// Remove the RTDB node so the breaking ticker disappears on all clients
rtdbDelete('/live/breaking/latest');

jsonResponse(true, [], "breaking removed");
