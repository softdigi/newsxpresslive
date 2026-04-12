<?php
/**
 * web/api/blue_tick/profile.php
 *
 * GET   → Return the authenticated user's public profile + blue-tick status.
 *   Headers: Authorization: Bearer <firebase_id_token>
 *
 * POST  → Update the authenticated user's profile fields.
 *   Headers: Authorization: Bearer <firebase_id_token>
 *   Body (JSON):
 *     {
 *       "display_name":      "string",
 *       "bio":               "string",
 *       "avatar_url":        "string",
 *       "website":           "string",
 *       "twitter":           "string",
 *       "instagram":         "string",
 *       "youtube":           "string",
 *       "location":          "string",
 *       "specialization":    "string",
 *       "years_experience":  int
 *     }
 *   All fields are optional — only provided keys are updated.
 *
 * Response (GET / POST success):
 *   {
 *     success,
 *     profile: {
 *       firebase_uid, display_name, bio, avatar_url, is_reporter, is_verified,
 *       website, twitter, instagram, youtube, location, specialization,
 *       years_experience, articles_count, followers_count, total_views
 *     },
 *     blue_tick: {
 *       is_blue_tick, blue_tick_type, blue_tick_granted_at,
 *       verification_status, account_type
 *     }
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['GET', 'POST', 'OPTIONS']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET or POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Authentication ─────────────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}
$tokenPayload = verifyFirebaseToken($idToken);
if (!$tokenPayload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$uid = $tokenPayload['sub'] ?? $tokenPayload['uid'] ?? '';
if (empty($uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Helper: load full profile ──────────────────────────────────────────────
function loadProfile(PDO $pdo, string $uid): array
{
    // user_profiles row
    $profStmt = $pdo->prepare(
        "SELECT firebase_uid, display_name, bio, avatar_url,
                is_reporter, is_verified,
                website, twitter, instagram, youtube, location,
                specialization, years_experience,
                articles_count, followers_count, total_views
         FROM user_profiles WHERE firebase_uid=? LIMIT 1"
    );
    $profStmt->execute([$uid]);
    $profile = $profStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        // Return minimal defaults
        $profile = [
            'firebase_uid'     => $uid,
            'display_name'     => null,
            'bio'              => null,
            'avatar_url'       => null,
            'is_reporter'      => 0,
            'is_verified'      => 0,
            'website'          => null,
            'twitter'          => null,
            'instagram'        => null,
            'youtube'          => null,
            'location'         => null,
            'specialization'   => null,
            'years_experience' => 0,
            'articles_count'   => 0,
            'followers_count'  => 0,
            'total_views'      => 0,
        ];
    }

    // Cast numeric fields
    $profile['is_reporter']      = (bool)$profile['is_reporter'];
    $profile['is_verified']      = (bool)$profile['is_verified'];
    $profile['years_experience'] = (int)$profile['years_experience'];
    $profile['articles_count']   = (int)$profile['articles_count'];
    $profile['followers_count']  = (int)$profile['followers_count'];
    $profile['total_views']      = (int)$profile['total_views'];

    return $profile;
}

// ── Helper: load blue-tick status ─────────────────────────────────────────
function loadBlueTick(PDO $pdo, string $uid): array
{
    $stmt = $pdo->prepare(
        "SELECT is_blue_tick, blue_tick_type, blue_tick_granted_at,
                verification_status, account_type
         FROM users WHERE firebase_uid=? LIMIT 1"
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'is_blue_tick'         => (bool)($row['is_blue_tick']         ?? false),
        'blue_tick_type'       => $row['blue_tick_type']               ?? null,
        'blue_tick_granted_at' => $row['blue_tick_granted_at']         ?? null,
        'verification_status'  => $row['verification_status']         ?? 'unverified',
        'account_type'         => $row['account_type']                 ?? 'user',
    ];
}

// ══════════════════════════════════════════════════════════════════════════
// GET
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success'   => true,
        'profile'   => loadProfile($pdo, $uid),
        'blue_tick' => loadBlueTick($pdo, $uid),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST — update profile
// ══════════════════════════════════════════════════════════════════════════
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Allowed fields and their max lengths
$allowedText = [
    'display_name'   => 120,
    'bio'            => 1000,
    'avatar_url'     => 512,
    'website'        => 500,
    'twitter'        => 200,
    'instagram'      => 200,
    'youtube'        => 200,
    'location'       => 200,
    'specialization' => 200,
];

$updates = [];
$params  = [];

foreach ($allowedText as $field => $maxLen) {
    if (!array_key_exists($field, $body)) continue;
    $val = $body[$field];
    if ($val === null) {
        $updates[] = "{$field} = NULL";
    } else {
        $val = substr(trim((string)$val), 0, $maxLen);
        // Validate URLs
        if (in_array($field, ['website', 'avatar_url'], true) && $val !== '') {
            if (!filter_var($val, FILTER_VALIDATE_URL)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => "{$field} must be a valid URL"]);
                exit;
            }
        }
        $updates[] = "{$field} = ?";
        $params[]  = $val;
    }
}

if (array_key_exists('years_experience', $body)) {
    $ye = max(0, min(99, (int)$body['years_experience']));
    $updates[] = 'years_experience = ?';
    $params[]  = $ye;
}

if (empty($updates)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No valid fields provided']);
    exit;
}

// Upsert user_profiles
$params[] = $uid; // for WHERE clause

$pdo->prepare(
    "INSERT INTO user_profiles (firebase_uid) VALUES (?)
     ON DUPLICATE KEY UPDATE firebase_uid = firebase_uid"
)->execute([$uid]);

$sql = 'UPDATE user_profiles SET ' . implode(', ', $updates) . ' WHERE firebase_uid = ?';
$pdo->prepare($sql)->execute($params);

// Update profile_complete flag: require display_name, bio, avatar_url
$checkStmt = $pdo->prepare(
    "SELECT display_name, bio, avatar_url FROM user_profiles WHERE firebase_uid=? LIMIT 1"
);
$checkStmt->execute([$uid]);
$check = $checkStmt->fetch(PDO::FETCH_ASSOC);
$isComplete = !empty($check['display_name']) && !empty($check['bio']) && !empty($check['avatar_url']) ? 1 : 0;

$pdo->prepare("UPDATE users SET profile_complete=? WHERE firebase_uid=?")
    ->execute([$isComplete, $uid]);

echo json_encode([
    'success'   => true,
    'message'   => 'Profile updated',
    'profile'   => loadProfile($pdo, $uid),
    'blue_tick' => loadBlueTick($pdo, $uid),
]);
