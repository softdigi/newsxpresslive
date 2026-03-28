<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../auth/firebase.php";
require __DIR__ . "/response.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['firebase_uid']) || empty($input['languages'])) {
    jsonResponse(false, [], "firebase_uid & languages required");
}

$user = requireAppUser($pdo, '', $input['firebase_uid'] ?? '');

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
