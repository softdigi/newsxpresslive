<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../../auth/firebase.php";
require __DIR__ . "/response.php";

/* Only POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

/* Read JSON */
$input = json_decode(file_get_contents("php://input"), true);

/* Required fields */
$required = [
    'title',
    'description',
    'category_id',
    'language_id'
];

foreach ($required as $field) {
    if (empty($input[$field])) {
        jsonResponse(false, [], "$field required");
    }
}

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
$user = requireAppUser($pdo, $id_token);

/* Location fallback (user profile based) */
$countryId  = $input['country_id']  ?? $user['country_id'];
$stateId    = $input['state_id']    ?? $user['state_id'];
$districtId = $input['district_id'] ?? $user['district_id'];

/* Optional fields */
$metaTitle = $input['meta_title'] ?? $input['title'];
$metaDesc  = $input['meta_description'] ?? substr($input['description'], 0, 150);

/* Slug generate (simple & safe) */
$slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['title'])));
$slug = $slugBase . "-" . time();

/* Insert news */
$stmt = $pdo->prepare(
    "INSERT INTO news
    (
        title,
        slug,
        description,
        category_id,
        language_id,
        country_id,
        state_id,
        district_id,
        user_id,
        status,
        meta_title,
        meta_description,
        created_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())"
);

$stmt->execute([
    $input['title'],
    $slug,
    $input['description'],
    $input['category_id'],
    $input['language_id'],
    $countryId,
    $stateId,
    $districtId,
    $user['id'],
    $metaTitle,
    $metaDesc
]);

$newsId = $pdo->lastInsertId();

/* Reporter bonus logic (future-ready) */
if ($user['role'] === 'reporter') {
    // reward entry after approval (not here)
}

/* Response */
jsonResponse(true, [
    "news_id" => $newsId,
    "status"  => "pending"
], "news submitted successfully");
