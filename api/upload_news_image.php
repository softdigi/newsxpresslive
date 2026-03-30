<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require __DIR__ . "/../geo/response.php";
require __DIR__ . "/../helpers/media.php";

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

/* Process image: convert to WebP, generate 3 sizes, apply CDN URLs */
$result = processUploadedImage($_FILES['image'], 'news/images');

if (isset($result['error'])) {
    jsonResponse(false, [], $result['error']);
}

/* Persist:
 *   image       — medium URL (backward compat; existing code reads this column)
 *   image_sizes — JSON object with thumbnail / medium / original URLs
 */
$stmt = $pdo->prepare(
    "UPDATE news SET image = ?, image_sizes = ? WHERE id = ?"
);
$stmt->execute([
    $result['primary_url'],
    json_encode($result['image_sizes']),
    $newsId,
]);

jsonResponse(true, [
    "image_url"   => $result['primary_url'],
    "image_sizes" => $result['image_sizes'],
    "lazy_load"   => true,
], "image uploaded successfully");
