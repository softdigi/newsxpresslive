<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../auth/firebase.php";
require __DIR__ . "/response.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['firebase_uid']) || empty($input['categories'])) {
    jsonResponse(false, [], "firebase_uid & categories required");
}

$user = requireAppUser($pdo, '', $input['firebase_uid'] ?? '');

$userId = $user['id'];

/* Remove old */
$pdo->prepare(
    "DELETE FROM user_interests WHERE user_id=?"
)->execute([$userId]);

$stmt = $pdo->prepare(
    "INSERT INTO user_interests (user_id, category_id)
     VALUES (?, ?)"
);

foreach ($input['categories'] as $catId) {
    $stmt->execute([$userId, $catId]);
}

jsonResponse(true, [], "interests saved");
