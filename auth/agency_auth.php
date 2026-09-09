<?php
// ============================================================
// auth/agency_auth.php
// News Agency Partner — Authentication Middleware
//
// Reads X-Agency-Key and X-Agency-Secret from request headers,
// verifies credentials against the agencies table, enforces:
//   • Brute-force lockout: 5 failed auth attempts → 15-min lockout (Redis)
//   • HMAC-SHA256 request signing: X-Signature + X-Timestamp headers,
//     5-minute expiry window
//   • Rate limiting: 1 000 requests per hour per agency_id (Redis)
//
// Usage:
//   require_once __DIR__ . '/../auth/agency_auth.php';
//   $agency = requireAgency($pdo);   // exits with JSON error on failure
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../helpers/redis.php';

// ── Brute-force lockout constants ─────────────────────────────────────────────
const AGENCY_AUTH_MAX_FAILURES  = 5;    // failed attempts before lockout
const AGENCY_AUTH_LOCKOUT_SEC   = 900;  // 15 minutes in seconds

// ── Request-signing constants ─────────────────────────────────────────────────
const AGENCY_SIGNATURE_WINDOW_SEC = 300;  // 5-minute replay-prevention window

/**
 * Authenticate the current request as a valid, active agency.
 *
 * Reads headers:
 *   X-Agency-Key    — the agency's api_key (UUID)
 *   X-Agency-Secret — plaintext secret (compared against the stored hash)
 *   X-Timestamp     — Unix timestamp (seconds) of the request
 *   X-Signature     — HMAC-SHA256(api_secret, "{timestamp}\n{raw_body}")
 *
 * Security checks in order:
 *   1. Header presence & format validation
 *   2. Brute-force lockout check (Redis) — before any DB query
 *   3. DB lookup of agency by api_key
 *   4. Secret verification (bcrypt / legacy SHA-256)
 *   5. HMAC-SHA256 request signature validation (5-min window)
 *   6. Agency status check (active only)
 *   7. Per-agency request rate limit (1 000 req/hour)
 *
 * @param  PDO   $pdo             Active database connection.
 * @param  bool  $requireSigning  When true (default) the X-Signature
 *                                and X-Timestamp headers are required
 *                                and validated.  Pass false for legacy
 *                                clients that do not yet sign requests.
 * @return array                  The agencies row (api_secret removed).
 *
 * Exits with JSON + appropriate HTTP status on any failure:
 *   401 — missing or invalid credentials / signature
 *   403 — agency suspended / pending / locked out
 *   429 — rate limit exceeded
 */
function requireAgency(PDO $pdo, bool $requireSigning = true): array
{
    // ── 1. Read credentials from headers ─────────────────────────────────────
    $apiKey    = trim($_SERVER['HTTP_X_AGENCY_KEY']    ?? '');
    $apiSecret = trim($_SERVER['HTTP_X_AGENCY_SECRET'] ?? '');

    if ($apiKey === '' || $apiSecret === '') {
        _agencyAuthFail(401, 'Missing X-Agency-Key or X-Agency-Secret header');
    }

    // Basic sanity-check on key format (UUID v4)
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $apiKey)) {
        _agencyAuthFail(401, 'Invalid API key format');
    }

    // ── 2. Brute-force lockout check (Redis, keyed on api_key prefix) ─────────
    $lockoutKey = 'agency_fail:' . substr($apiKey, 0, 18);  // partial key, not full
    _checkBruteForce($lockoutKey);

    // ── 3. Look up agency by key ──────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, email, status, api_secret, revenue_share_percent,
                wallet_balance, total_earned
         FROM   agencies
         WHERE  api_key = ?
         LIMIT  1'
    );
    $stmt->execute([$apiKey]);
    $agency = $stmt->fetch();

    if (!$agency) {
        _recordFailedAttempt($lockoutKey);
        _agencyAuthFail(401, 'Invalid API key');
    }

    // ── 4. Verify secret ──────────────────────────────────────────────────────
    // api_secret is stored as a password_hash() — use password_verify().
    // Falls back to hash_equals(sha256) for legacy secrets.
    $secretValid = false;

    if (str_starts_with($agency['api_secret'], '$2y$') || str_starts_with($agency['api_secret'], '$argon')) {
        $secretValid = password_verify($apiSecret, $agency['api_secret']);
    } else {
        // Legacy SHA-256 path
        $secretValid = hash_equals($agency['api_secret'], hash('sha256', $apiSecret));
    }

    if (!$secretValid) {
        _recordFailedAttempt($lockoutKey);
        _agencyAuthFail(401, 'Invalid API secret');
    }

    // Auth succeeded — clear the failure counter
    _clearFailedAttempts($lockoutKey);

    // ── 5. HMAC-SHA256 request signature verification ─────────────────────────
    if ($requireSigning) {
        _verifyRequestSignature($apiSecret);
    }

    // ── 6. Status check ───────────────────────────────────────────────────────
    if ($agency['status'] === 'pending') {
        _agencyAuthFail(403, 'Agency account is pending approval');
    }
    if ($agency['status'] === 'suspended') {
        _agencyAuthFail(403, 'Agency account is suspended');
    }
    if ($agency['status'] !== 'active') {
        _agencyAuthFail(403, 'Agency account is not active');
    }

    // ── 7. Rate limiting: 1 000 req / hour per agency ─────────────────────────
    _agencyRateLimit((int)$agency['id']);

    // Remove secret from returned array — never expose hash downstream
    unset($agency['api_secret']);

    return $agency;
}

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Check Redis for an active brute-force lockout.
 * Exits with 403 if the key is locked out.
 */
function _checkBruteForce(string $lockoutKey): void
{
    try {
        $redis    = getRedis();
        $failures = (int)($redis->get($lockoutKey) ?: 0);

        if ($failures >= AGENCY_AUTH_MAX_FAILURES) {
            $ttl = max(1, (int)$redis->ttl($lockoutKey));
            http_response_code(403);
            header('Content-Type: application/json');
            header('Retry-After: ' . $ttl);
            echo json_encode([
                'success'     => false,
                'error'       => 'Too many failed attempts. Account temporarily locked.',
                'retry_after' => $ttl,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        // Redis unavailable — allow through (fail-open) but log
        error_log('agency_auth brute_force Redis error: ' . $e->getMessage());
    }
}

/**
 * Increment the failure counter in Redis.
 * Sets a 15-minute expiry on the first failure.
 */
function _recordFailedAttempt(string $lockoutKey): void
{
    try {
        $redis    = getRedis();
        $failures = $redis->incr($lockoutKey);
        if ($failures === 1) {
            $redis->expire($lockoutKey, AGENCY_AUTH_LOCKOUT_SEC);
        }
    } catch (Throwable $e) {
        error_log('agency_auth record_failure Redis error: ' . $e->getMessage());
    }
}

/**
 * Remove the failure counter after a successful auth.
 */
function _clearFailedAttempts(string $lockoutKey): void
{
    try {
        getRedis()->del($lockoutKey);
    } catch (Throwable $e) {
        // Non-critical — ignore
    }
}

/**
 * Verify the HMAC-SHA256 request signature.
 *
 * Expected headers:
 *   X-Timestamp  — Unix timestamp (seconds, integer string)
 *   X-Signature  — hex HMAC-SHA256(plaintext_secret, "{timestamp}\n{raw_body}")
 *
 * Exits with 401 on any signature failure.
 *
 * @param string $plaintextSecret  The plaintext api_secret from the request
 *                                 (already verified against the DB hash above).
 */
function _verifyRequestSignature(string $plaintextSecret): void
{
    $timestamp = trim($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
    $signature = trim($_SERVER['HTTP_X_SIGNATURE'] ?? '');

    if ($timestamp === '' || $signature === '') {
        _agencyAuthFail(401, 'Missing X-Timestamp or X-Signature header');
    }

    // Validate timestamp is a positive integer
    if (!ctype_digit($timestamp) || strlen($timestamp) > 12) {
        _agencyAuthFail(401, 'X-Timestamp must be a Unix timestamp (integer seconds)');
    }

    $ts = (int)$timestamp;
    $now = time();

    // Reject requests outside the ±5-minute window (replay prevention)
    if (abs($now - $ts) > AGENCY_SIGNATURE_WINDOW_SEC) {
        _agencyAuthFail(401, 'Request timestamp is outside the allowed 5-minute window');
    }

    // Read raw request body (cached so it can be read once)
    $rawBody = _getRawBody();

    // Compute expected signature: HMAC-SHA256(secret, "{timestamp}\n{body}")
    $message  = $timestamp . "\n" . $rawBody;
    $expected = hash_hmac('sha256', $message, $plaintextSecret);

    if (!hash_equals($expected, strtolower($signature))) {
        _agencyAuthFail(401, 'Invalid request signature');
    }
}

/**
 * Return the raw request body, reading and caching it once.
 */
function _getRawBody(): string
{
    static $cached = null;
    if ($cached === null) {
        $cached = (string)file_get_contents('php://input');
    }
    return $cached;
}

/**
 * Apply Redis-based rate limit: 1 000 requests per 3 600 seconds.
 * Exits with 429 if exceeded.
 */
function _agencyRateLimit(int $agencyId): void
{
    $maxRequests = 1000;
    $window      = 3600; // 1 hour in seconds
    $key         = 'agency_rl:' . $agencyId;

    try {
        $redis    = getRedis();
        $attempts = $redis->incr($key);

        if ($attempts === 1) {
            $redis->expire($key, $window);
        }

        if ($attempts > $maxRequests) {
            $ttl = max($window, (int)$redis->ttl($key));
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $ttl);
            echo json_encode([
                'success'     => false,
                'error'       => 'Rate limit exceeded. Max 1000 requests per hour.',
                'retry_after' => $ttl,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        // Redis unavailable — log and allow through (fail-open)
        error_log('agency_auth rate_limit Redis error: ' . $e->getMessage());
    }
}

/**
 * Output a JSON error response and exit.
 */
function _agencyAuthFail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}
