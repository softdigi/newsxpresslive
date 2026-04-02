<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../auth/firebase.php";
require __DIR__ . "/response.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

// FIX 1 (caller update): Switched from firebase_uid body param to
// verified Firebase ID token. Token is read from the Authorization header
// (preferred) or from the JSON body's 'id_token' field.
$id_token    = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $id_token = substr($auth_header, 7);
}
if (empty($id_token)) {
    $id_token = $input['id_token'] ?? '';
}

if (empty($id_token) || empty($input['categories'])) {
    jsonResponse(false, [], "Authorization token & categories required");
}

$user = requireAppUser($pdo, $id_token);

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
