<?php
// ============================================================
// auth/agency_auth.php
// News Agency Partner — Authentication Middleware
//
// Reads X-Agency-Key and X-Agency-Secret from request headers,
// verifies credentials against the agencies table, enforces
// rate limiting via Redis (1 000 req / hour per agency), and
// returns the authenticated agency row.
//
// Usage:
//   require_once __DIR__ . '/../auth/agency_auth.php';
//   $agency = requireAgency($pdo);   // exits with JSON error on failure
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../helpers/redis.php';

/**
 * Authenticate the current request as a valid, active agency.
 *
 * Reads headers:
 *   X-Agency-Key    — the agency's api_key (UUID)
 *   X-Agency-Secret — plaintext secret (compared against the stored hash)
 *
 * Rate limit: 1 000 requests per hour per agency_id (Redis INCR/EXPIRE).
 *
 * @param  PDO   $pdo   Active database connection.
 * @return array        The agencies row from the database.
 *
 * Exits with JSON + appropriate HTTP status on any failure:
 *   401 — missing or invalid credentials
 *   403 — agency suspended / pending
 *   429 — rate limit exceeded
 */
function requireAgency(PDO $pdo): array
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

    // ── 2. Look up agency by key ──────────────────────────────────────────────
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
        _agencyAuthFail(401, 'Invalid API key');
    }

    // ── 3. Verify secret ──────────────────────────────────────────────────────
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
        _agencyAuthFail(401, 'Invalid API secret');
    }

    // ── 4. Status check ───────────────────────────────────────────────────────
    if ($agency['status'] === 'pending') {
        _agencyAuthFail(403, 'Agency account is pending approval');
    }
    if ($agency['status'] === 'suspended') {
        _agencyAuthFail(403, 'Agency account is suspended');
    }
    if ($agency['status'] !== 'active') {
        _agencyAuthFail(403, 'Agency account is not active');
    }

    // ── 5. Rate limiting: 1 000 req / hour per agency ─────────────────────────
    _agencyRateLimit((int)$agency['id']);

    // Remove secret from returned array — never expose hash downstream
    unset($agency['api_secret']);

    return $agency;
}

// ── Private helpers ───────────────────────────────────────────────────────────

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
