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

$required = [
    'admin_uid',
    'news_id',
    'location_type',   // country | state | district
    'location_id',
    'category_id',
    'language_code'
];

foreach ($required as $r) {
    if (empty($input[$r])) {
        jsonResponse(false, [], "$r required");
    }
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* Validate */
$allowedTypes = ['country','state','district'];
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
$topic = "loc_{$input['location_type']}_{$input['location_id']}"
       . "__cat_{$input['category_id']}"
       . "__lang_{$input['language_code']}";

/* Send notification */
sendFCMNotification(
    "Breaking News 🔴",
    $news['title'],
    [
        "news_id" => (string)$input['news_id'],
        "type" => "breaking",
        "category_id" => (string)$input['category_id'],
        "language" => $input['language_code']
    ],
    $topic
);

/* Log */
file_put_contents(
    __DIR__ . "/../logs/fcm.log",
    date("Y-m-d H:i:s") . " | $topic | News {$input['news_id']}\n",
    FILE_APPEND
);

jsonResponse(true, [
    "topic" => $topic
], "combined breaking notification sent");
