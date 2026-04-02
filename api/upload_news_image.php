<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require __DIR__ . "/../geo/response.php";
require __DIR__ . "/../helpers/media.php";
require_once __DIR__ . "/../../auth/firebase.php";

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

/* Required */
if (empty($_POST['news_id']) || empty($_FILES['image'])) {
    jsonResponse(false, [], "news_id & image required");
}

$newsId = (int) $_POST['news_id'];

// FIX 2: Authenticate the caller via Firebase ID token before touching any
// article. The token must arrive either as a Bearer token in the
// Authorization header or in the request body as 'id_token'.
$id_token = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $id_token = substr($auth_header, 7);
}
if (empty($id_token)) {
    $id_token = $_POST['id_token'] ?? '';
}
$authUser = requireAppUser($pdo, $id_token);

/* Check news exists AND belongs to the authenticated user.
 * Without this check any reporter could overwrite another reporter's
 * (or even an approved/published) article image by supplying a different
 * news_id. We now select user_id alongside id and compare it to the
 * verified caller. */
$stmt = $pdo->prepare("SELECT id, user_id FROM news WHERE id=?");
$stmt->execute([$newsId]);
$news = $stmt->fetch();

if (!$news) {
    jsonResponse(false, [], "news not found");
}

// FIX 2 (continued): Ownership enforcement — return 403 if the article
// does not belong to the authenticated user. Previously any authenticated
// reporter could overwrite a different reporter's (or published) article
// image by supplying a different news_id.
if ((int)$news['user_id'] !== (int)$authUser['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: you do not own this article']);
    exit;
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
