<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require __DIR__ . "/../geo/response.php";
require __DIR__ . "/../helpers/upload.php";

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

/* Required */
if (empty($_POST['news_id']) || empty($_FILES['image'])) {
    jsonResponse(false, [], "news_id & image required");
}

$newsId = (int) $_POST['news_id'];

/* Check news exists */
$stmt = $pdo->prepare("SELECT id FROM news WHERE id=?");
$stmt->execute([$newsId]);
$news = $stmt->fetch();

if (!$news) {
    jsonResponse(false, [], "news not found");
}

/* Upload */
$result = uploadImage($_FILES['image'], 'news/images');

if (isset($result['error'])) {
    jsonResponse(false, [], $result['error']);
}

/* Save image path */
$stmt = $pdo->prepare(
    "UPDATE news SET image=? WHERE id=?"
);
$stmt->execute([$result['url'], $newsId]);

jsonResponse(true, [
    "image_url" => $result['url']
], "image uploaded successfully");
