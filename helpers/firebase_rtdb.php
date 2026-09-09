<?php
/**
 * helpers/firebase_rtdb.php — Firebase Realtime Database REST helper
 *
 * Uses the same Google service-account key already required by the FCM helper
 * (helpers/firebase_service_key.json).  Generates a short-lived OAuth2 access
 * token (cached for 55 minutes) and performs PUT / PATCH / POST requests
 * against the Firebase RTDB REST API.
 *
 * Environment variables:
 *   FIREBASE_DB_URL — e.g. https://your-project-default-rtdb.firebaseio.com
 *                     Loaded from service-key "databaseURL" field as fallback.
 *
 * Public API:
 *   rtdbPut   (string $path, array $data) : bool   — SET  (overwrites)
 *   rtdbPatch (string $path, array $data) : bool   — MERGE (update fields)
 *   rtdbPush  (string $path, array $data) : string|false — APPEND (new key)
 *   rtdbDelete(string $path)             : bool   — DELETE node
 */

declare(strict_types=1);

// ── Service-key path (shared with notification.php) ──────────────────────────
define('RTDB_SERVICE_KEY_PATH', __DIR__ . '/firebase_service_key.json');

// ── In-process token cache ────────────────────────────────────────────────────
$_rtdb_token_cache = ['token' => null, 'expires' => 0];

// ── Public functions ─────────────────────────────────────────────────────────

/**
 * SET a value at a RTDB path (overwrites existing data).
 */
function rtdbPut(string $path, array $data): bool
{
    return _rtdbRequest('PUT', $path, $data) !== false;
}

/**
 * MERGE data at a RTDB path (only supplied keys are changed).
 */
function rtdbPatch(string $path, array $data): bool
{
    return _rtdbRequest('PATCH', $path, $data) !== false;
}

/**
 * APPEND a new child node under a RTDB path (Firebase auto-generates key).
 * Returns the generated key on success, false on failure.
 *
 * @return string|false
 */
function rtdbPush(string $path, array $data)
{
    $body = _rtdbRequest('POST', $path, $data);
    if ($body === false) {
        return false;
    }
    $decoded = json_decode($body, true);
    return $decoded['name'] ?? false;
}

/**
 * DELETE a node and all its children.
 */
function rtdbDelete(string $path): bool
{
    return _rtdbRequest('DELETE', $path, null) !== false;
}

// ── Private helpers ──────────────────────────────────────────────────────────

/**
 * Execute an HTTP request against the RTDB REST API.
 *
 * @param  string      $method  HTTP verb: PUT | PATCH | POST | DELETE
 * @param  string      $path    RTDB path, e.g. '/live/breaking/latest'
 * @param  array|null  $data    Payload; null for DELETE
 * @return string|false         Raw response body on success, false on failure
 */
function _rtdbRequest(string $method, string $path, ?array $data)
{
    $token   = _rtdbGetAccessToken();
    $baseUrl = _rtdbBaseUrl();

    if ($token === null || $baseUrl === null) {
        error_log('firebase_rtdb: missing access token or database URL');
        return false;
    }

    // Ensure path starts with /
    $path = '/' . ltrim($path, '/');
    $url  = rtrim($baseUrl, '/') . $path . '.json?access_token=' . urlencode($token);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        error_log("firebase_rtdb: curl error {$errno} on {$method} {$path}");
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("firebase_rtdb: HTTP {$httpCode} on {$method} {$path} — {$body}");
        return false;
    }

    return $body;
}

/**
 * Obtain and cache a Google OAuth2 access token for the service account.
 * Token lifetime is 1 hour; we refresh 5 minutes early.
 *
 * @return string|null
 */
function _rtdbGetAccessToken(): ?string
{
    global $_rtdb_token_cache;

    if ($_rtdb_token_cache['token'] && time() < $_rtdb_token_cache['expires']) {
        return $_rtdb_token_cache['token'];
    }

    if (!file_exists(RTDB_SERVICE_KEY_PATH)) {
        error_log('firebase_rtdb: service key not found at ' . RTDB_SERVICE_KEY_PATH);
        return null;
    }

    $key = json_decode(file_get_contents(RTDB_SERVICE_KEY_PATH), true);
    if (!$key) {
        error_log('firebase_rtdb: could not parse service key JSON');
        return null;
    }

    // Build JWT assertion
    $now    = time();
    $header = _rtdbBase64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = _rtdbBase64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase https://www.googleapis.com/auth/userinfo.email',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $payload = $header . '.' . $claims;

    $privateKey = openssl_pkey_get_private($key['private_key']);
    if ($privateKey === false) {
        error_log('firebase_rtdb: invalid private key in service account');
        return null;
    }

    openssl_sign($payload, $sig, $privateKey, 'SHA256');
    $jwt = $payload . '.' . _rtdbBase64url($sig);

    // Exchange JWT for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 || $resp === false) {
        error_log("firebase_rtdb: OAuth2 curl error {$errno}");
        return null;
    }

    $result = json_decode($resp, true);
    $token  = $result['access_token'] ?? null;

    if ($token === null) {
        error_log('firebase_rtdb: no access_token in OAuth2 response: ' . $resp);
        return null;
    }

    $_rtdb_token_cache = [
        'token'   => $token,
        'expires' => $now + ($result['expires_in'] ?? 3600) - 300, // 5-min buffer
    ];

    return $token;
}

/**
 * Resolve the Firebase RTDB base URL from env var or service-key databaseURL.
 *
 * @return string|null
 */
function _rtdbBaseUrl(): ?string
{
    $url = getenv('FIREBASE_DB_URL') ?: null;
    if ($url) {
        return rtrim($url, '/');
    }

    if (!file_exists(RTDB_SERVICE_KEY_PATH)) {
        return null;
    }

    $key = json_decode(file_get_contents(RTDB_SERVICE_KEY_PATH), true);
    return isset($key['databaseURL']) ? rtrim($key['databaseURL'], '/') : null;
}

/**
 * URL-safe base64 encode (no padding).
 */
function _rtdbBase64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
