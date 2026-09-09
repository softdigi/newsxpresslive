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

if (empty($id_token) || empty($input['languages'])) {
    jsonResponse(false, [], "Authorization token & languages required");
}

$user = requireAppUser($pdo, $id_token);

$userId = $user['id'];

/* Clear old */
$pdo->prepare(
    "DELETE FROM user_languages WHERE user_id=?"
)->execute([$userId]);

/* Insert new */
$stmt = $pdo->prepare(
    "INSERT INTO user_languages (user_id, language_id, priority)
     VALUES (?, ?, ?)"
);

foreach ($input['languages'] as $lang) {
    if (!isset($lang['language_id'], $lang['priority'])) continue;

    $stmt->execute([
        $userId,
        $lang['language_id'],
        $lang['priority']
    ]);
}

jsonResponse(true, [], "languages saved");
