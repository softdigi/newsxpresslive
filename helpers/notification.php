<?php
// ============================================================
// FIXED: helpers/notification.php
// BUGS:
//   1. CRITICAL: JWT base64 encoding is STANDARD base64, not
//      URL-safe base64. OAuth2/JWT spec requires base64url
//      (replaces +→-, /→_, strips =padding).
//      Standard base64 breaks the JWT signature → FCM rejects
//      every token → all notifications silently fail.
//      Fixed: proper base64url encoding.
//
//   2. No check if firebase_service_key.json exists —
//      file_get_contents() returns false, json_decode(false)
//      returns null, $jsonKey['client_email'] crashes with
//      "Cannot use null as array" fatal error.
//
//   3. curl_exec() return value not checked for curl errors
//      (network failure etc) — json_decode(false) on curl
//      failure causes "empty access_token" silent fail.
//
//   4. $title/$body/$topic passed directly to JSON payload
//      with no sanitization — fine for json_encode but
//      $topic should be validated (covered in notification
//      callers, noted here too).
//
//   5. Access token not cached — every notification call
//      makes 2 HTTP requests (token + send). With 100
//      breaking news pushes, that's 200 OAuth calls.
//      Fixed: static cache for access token within request.
// ============================================================

/**
 * URL-safe base64 encode (RFC 4648 §5) — required for JWT
 */
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Get FCM access token via Service Account JWT
 * Cached for the duration of the PHP process.
 */
function getFCMAccessToken(array $jsonKey): string|false
{
    static $cached_token   = null;
    static $cached_expires = 0;

    // Return cached token if still valid (with 60s buffer)
    if ($cached_token && time() < $cached_expires - 60) {
        return $cached_token;
    }

    $now = time();

    // FIXED: base64url_encode (not standard base64)
    $header = base64url_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
    ]));

    $claim = base64url_encode(json_encode([
        'iss'   => $jsonKey['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signing_input = $header . '.' . $claim;

    // Sign with private key
    $signature = '';
    if (!openssl_sign($signing_input, $signature, $jsonKey['private_key'], 'SHA256')) {
        error_log('FCM JWT signing failed');
        return false;
    }

    $jwt = $signing_input . '.' . base64url_encode($signature);

    // Exchange JWT for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);

    $raw    = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // FIXED: check curl error
    if ($raw === false) {
        error_log('FCM token request curl error: ' . $curlErr);
        return false;
    }

    $result = json_decode($raw, true);

    if (empty($result['access_token'])) {
        error_log('FCM token request failed: ' . $raw);
        return false;
    }

    // Cache token
    $cached_token   = $result['access_token'];
    $cached_expires = $now + (int)($result['expires_in'] ?? 3600);

    return $cached_token;
}

/**
 * Send FCM notification via HTTP v1 API
 *
 * @param string $title    Notification title
 * @param string $body     Notification body
 * @param array  $data     Key-value data payload
 * @param string $topic    FCM topic (default: 'all')
 * @return string|false    FCM API response or false on failure
 */
function sendFCMNotification(
    string $title,
    string $body,
    array  $data  = [],
    string $topic = 'all'
): string|false {

    $serviceAccountPath = __DIR__ . '/firebase_service_key.json';

    // FIXED: check file exists before reading
    if (!file_exists($serviceAccountPath)) {
        error_log('FCM service key file not found: ' . $serviceAccountPath);
        return false;
    }

    $jsonKey = json_decode(file_get_contents($serviceAccountPath), true);

    if (empty($jsonKey['client_email']) || empty($jsonKey['private_key']) || empty($jsonKey['project_id'])) {
        error_log('FCM service key is missing required fields');
        return false;
    }

    // Get (cached) access token
    $accessToken = getFCMAccessToken($jsonKey);
    if (!$accessToken) {
        return false;
    }

    // Ensure all data values are strings (FCM requirement)
    $string_data = array_map('strval', $data);

    $payload = [
        'message' => [
            'topic'        => $topic,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => $string_data,
        ],
    ];

    $url = "https://fcm.googleapis.com/v1/projects/{$jsonKey['project_id']}/messages:send";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('FCM send curl error: ' . $curlErr);
        return false;
    }

    // Log FCM errors (but still return response)
    $decoded = json_decode($response, true);
    if (!empty($decoded['error'])) {
        error_log('FCM send error: ' . $response);
    }

    return $response;
}
