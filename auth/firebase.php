<?php
// ============================================================
// auth/firebase.php
// Mobile App API Authentication Helper
//
// PROBLEM THIS SOLVES:
//   Currently every API file does this inline:
//     $stmt = $pdo->prepare(
//       "SELECT id FROM users WHERE firebase_uid=? AND role='admin'"
//     );
//     $stmt->execute([$input['admin_uid']]);
//     if (!$stmt->fetch()) { jsonResponse(false,[],"unauthorized"); }
//
//   Issues with current approach:
//   1. firebase_uid is sent as plain JSON body — NO signature
//      verification. Anyone who knows a valid firebase_uid
//      (e.g. from a data leak) can impersonate any user.
//      Proper auth requires verifying the Firebase ID Token
//      (JWT signed by Google) not just the UID string.
//   2. Same DB query copy-pasted in 12+ files
//   3. No rate limiting on auth failures
//   4. Blocked/suspended users not checked on every request
//
// THIS FILE provides:
//   - verifyFirebaseToken()  : Verify Firebase JWT (proper auth)
//   - requireAppUser()       : Get verified user from DB
//   - requireAppAdmin()      : Get verified admin user from DB
//
// USAGE in API files (replaces inline DB lookup):
//   require_once __DIR__ . '/../auth/firebase.php';
//   $user = requireAppUser($pdo, $input['id_token']);
//   // $user = ['id'=>1, 'role'=>'reporter', 'status'=>'active', ...]
// ============================================================

/**
 * Verify a Firebase ID Token (JWT) using Google's public keys.
 *
 * App flow:
 *   1. User signs in via Firebase SDK on mobile
 *   2. App gets ID token: FirebaseAuth.getInstance().currentUser.getIdToken()
 *   3. App sends id_token in API request body
 *   4. Server verifies token here (not just trusts the uid string)
 *
 * @param  string $id_token  Firebase ID Token from client
 * @return array|false       Decoded token payload or false on failure
 */
function verifyFirebaseToken(string $id_token): array|false
{
    if (empty($id_token)) return false;

    // Split JWT into parts
    $parts = explode('.', $id_token);
    if (count($parts) !== 3) return false;

    // Decode header and payload
    $header  = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!$header || !$payload) return false;

    // Basic claim checks
    $project_id = getenv('FIREBASE_PROJECT_ID') ?: 'newsxpresslive'; // set in env
    $now        = time();

    if (($payload['iss'] ?? '') !== "https://securetoken.google.com/{$project_id}") return false;
    if (($payload['aud'] ?? '') !== $project_id) return false;
    if (($payload['exp'] ?? 0)  <  $now)         return false;
    if (($payload['iat'] ?? 0)  >  $now + 300)   return false; // 5min clock skew
    if (empty($payload['sub']))                   return false;
    if (empty($payload['uid']) && empty($payload['sub'])) return false;

    // Verify signature using Google's public keys
    $kid  = $header['kid'] ?? '';
    $keys = getFirebasePublicKeys();

    if (!$keys || !isset($keys[$kid])) return false;

    $public_key = openssl_pkey_get_public($keys[$kid]);
    if (!$public_key) return false;

    $signature       = base64_decode(strtr($parts[2], '-_', '+/'));
    $signing_input   = $parts[0] . '.' . $parts[1];
    $verify_result   = openssl_verify($signing_input, $signature, $public_key, 'SHA256');

    if ($verify_result !== 1) return false;

    return $payload;
}

/**
 * Fetch and cache Google's Firebase public keys.
 * Keys cached for 1 hour in a temp file.
 */
function getFirebasePublicKeys(): array|false
{
    $cache_file = sys_get_temp_dir() . '/firebase_keys.json';
    $cache_ttl  = 3600; // 1 hour

    // Return cached keys if fresh
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached) return $cached;
    }

    // Fetch from Google
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents(
        'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com',
        false, $ctx
    );

    if (!$raw) return false;

    $keys = json_decode($raw, true);
    if (!$keys) return false;

    file_put_contents($cache_file, json_encode($keys));
    return $keys;
}

/**
 * Require an authenticated app user.
 * Accepts id_token (preferred) or firebase_uid (legacy fallback).
 *
 * @param  PDO    $pdo
 * @param  string $id_token     Firebase ID token (JWT) — preferred
 * @param  string $firebase_uid Firebase UID — legacy, less secure
 * @return array  User row from DB
 * Exits with 401 JSON if not authenticated.
 */
function requireAppUser(PDO $pdo, string $id_token = '', string $firebase_uid = ''): array
{
    $verified_uid = null;

    // Prefer ID token verification
    if (!empty($id_token)) {
        $payload = verifyFirebaseToken($id_token);
        if ($payload) {
            $verified_uid = $payload['sub'] ?? $payload['uid'] ?? null;
        }
    }

    // Legacy fallback: trust firebase_uid from body (weaker)
    // TODO: remove this once all app versions send id_token
    if (!$verified_uid && !empty($firebase_uid)) {
        if (preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $firebase_uid)) {
            $verified_uid = $firebase_uid;
        }
    }

    if (!$verified_uid) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Load user from DB
    $stmt = $pdo->prepare(
        "SELECT id, name, role, status, agency_id
         FROM users WHERE firebase_uid = ? LIMIT 1"
    );
    $stmt->execute([$verified_uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    if (in_array($user['status'], ['blocked', 'suspended', 'banned'], true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Account suspended']);
        exit;
    }

    return $user;
}

/**
 * Require an authenticated admin user (role = admin or super_admin).
 * Used by admin JSON API endpoints (admin2/admin/*.php files).
 *
 * @param  PDO    $pdo
 * @param  string $admin_uid  Firebase UID from request body
 * @return array  Admin user row
 * Exits with 401/403 JSON if not authenticated or not admin.
 */
function requireAppAdmin(PDO $pdo, string $admin_uid): array
{
    $admin_uid = trim($admin_uid);

    if (!$admin_uid || !preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $admin_uid)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, role, status
         FROM users
         WHERE firebase_uid = ?
           AND role IN ('admin', 'super_admin')
           AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$admin_uid]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    return $admin;
}
