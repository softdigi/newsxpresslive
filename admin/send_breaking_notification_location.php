<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../../auth/firebase.php";
require __DIR__ . "/../geo/response.php";
require __DIR__ . "/../helpers/notification.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (
    empty($input['admin_uid']) ||
    empty($input['news_id']) ||
    empty($input['location_type']) ||
    empty($input['location_id'])
) {
    jsonResponse(false, [], "admin_uid, news_id, location_type, location_id required");
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Validate location type */
$allowedTypes = ['country', 'state', 'district'];
if (!in_array($input['location_type'], $allowedTypes)) {
    jsonResponse(false, [], "invalid location_type");
}

/* Get breaking news */
$stmt = $pdo->prepare(
    "SELECT title FROM news 
     WHERE id=? AND status='approved' AND is_breaking=1"
);
$stmt->execute([$input['news_id']]);
$news = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news) {
    jsonResponse(false, [], "breaking news not found");
}

/* Build topic */
$topic = $input['location_type'] . "_" . (int)$input['location_id'];

/* Send notification */
$response = sendFCMNotification(
    "Breaking News 🔴",
    $news['title'],
    [
        "news_id" => (string)$input['news_id'],
        "type" => "breaking",
        "location_type" => $input['location_type']
    ],
    $topic   // ← topic specific
);

/* Log */
file_put_contents(
    __DIR__ . "/../logs/fcm.log",
    date("Y-m-d H:i:s") . " | $topic | News ID: {$input['news_id']}\n",
    FILE_APPEND
);

jsonResponse(true, [
    "topic" => $topic
], "location wise breaking notification sent");
