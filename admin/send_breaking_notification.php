<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../../auth/firebase.php";
require __DIR__ . "/../geo/response.php";
require __DIR__ . "/../helpers/notification.php";

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['admin_uid']) || empty($input['news_id'])) {
    jsonResponse(false, [], "admin_uid & news_id required");
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Get news */
$stmt = $pdo->prepare(
    "SELECT title FROM news 
     WHERE id=? AND status='approved' AND is_breaking=1"
);
$stmt->execute([$input['news_id']]);
$news = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news) {
    jsonResponse(false, [], "breaking news not found");
}

/* Send notification */
$response = sendFCMNotification(
    "Breaking News 🔴",
    $news['title'],
    [
        "news_id" => (string)$input['news_id'],
        "type" => "breaking"
    ]
);

/* Log */
file_put_contents(
    __DIR__ . "/../logs/fcm.log",
    date("Y-m-d H:i:s") . " | News ID: {$input['news_id']}\n",
    FILE_APPEND
);

jsonResponse(true, [
    "fcm_response" => $response
], "breaking notification sent");
