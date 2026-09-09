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

$newsId = (int) $input['news_id'];

/* Mark breaking */
$stmt = $pdo->prepare(
    "UPDATE news 
     SET is_breaking=1, is_featured=1 
     WHERE id=? AND status='approved'"
);
$stmt->execute([$newsId]);

// Push the breaking article stub to RTDB so Flutter clients can update the
// feed instantly without waiting for the next FCM notification.
// Path: /live/breaking/latest
$article = $pdo->prepare(
    "SELECT id, title, slug, image, created_at FROM news WHERE id = ? LIMIT 1"
);
$article->execute([$newsId]);
$row = $article->fetch();

if ($row) {
    rtdbPut('/live/breaking/latest', [
        'id'         => (int) $row['id'],
        'title'      => $row['title'],
        'slug'       => $row['slug'],
        'image'      => $row['image'],
        'created_at' => $row['created_at'],
        'ts'         => time(),
    ]);
}

jsonResponse(true, [], "news marked as breaking");
