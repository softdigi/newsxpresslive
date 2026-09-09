<?php
// ============================================================
// auth/rate_limit.php
// Redis-based Atomic Rate Limiting
//
// Replaces the previous DB-based implementation.
//
// WHY REDIS:
//   The DB-based implementation used SELECT + UPDATE/INSERT on every
//   request — a read-modify-write cycle that creates a contention
//   hotspot under high traffic.  Redis INCR + EXPIRE is a single
//   round-trip, O(1), and fully atomic with no row locking.
//
// USAGE (unchanged public API — callers need no changes):
//   require_once __DIR__ . '/../auth/rate_limit.php';
//
//   // Block if more than 5 login attempts in 15 minutes:
//   rateLimit($pdo, 'login', $ip, 5, 900);
//
//   // Block if more than 10 news submissions per hour:
//   rateLimit($pdo, 'submit_news', $user_id, 10, 3600);
//
// $pdo is still accepted for API compatibility but is no longer used.
// Redis is configured via environment variables (see helpers/redis.php).
//
// Graceful degradation: if Redis is unavailable, requests are allowed
// through (fail-open) so a cache outage does not bring down the API.
// ============================================================

require_once __DIR__ . '/../helpers/redis.php';

// ── Redis key helper ──────────────────────────────────────────────────────────

function _rlKey(string $action, string $identifier): string
{
    // e.g.  rl:login:192.168.1.1
    return 'rl:' . $action . ':' . $identifier;
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Check and increment rate limit.
 * Exits with 429 JSON + Retry-After header if limit is exceeded.
 *
 * Uses Redis INCR (atomic) so there are no race conditions or DB writes.
 * On the first call within a window, EXPIRE is set to window_sec so the
 * counter automatically disappears when the window closes.
 *
 * @param PDO|null $pdo        Accepted for API compatibility; not used.
 * @param string   $action     Unique action name e.g. 'login', 'submit_news'
 * @param string   $identifier IP address, user_id, or firebase_uid
 * @param int      $max        Maximum allowed attempts in window
 * @param int      $window_sec Time window in seconds
 */
function rateLimit(?PDO $pdo, string $action, string $identifier, int $max = 10, int $window_sec = 60): void
{
    $identifier = substr(trim($identifier), 0, 128);
    $action     = substr(trim($action),     0, 64);
    $key        = _rlKey($action, $identifier);

    try {
        $redis = getRedis();

        // Atomically increment counter
        $attempts = $redis->incr($key);

        // Set expiry on the first increment only (do not reset TTL on subsequent hits)
        if ($attempts === 1) {
            $redis->expire($key, $window_sec);
        }

        if ($attempts > $max) {
            // Calculate seconds until the key expires (how long to wait)
            $ttl = max($window_sec, (int) $redis->ttl($key));

            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $ttl);
            echo json_encode([
                'success'     => false,
                'message'     => 'Too many requests. Please try again later.',
                'retry_after' => $ttl,
            ]);
            exit;
        }

    } catch (Throwable $e) {
        // Redis unavailable — log and allow the request through (fail-open)
        error_log('rate_limit Redis error: ' . $e->getMessage());
    }
}

/**
 * Reset rate limit counter for an identifier (e.g. after a successful login).
 *
 * @param PDO|null $pdo Accepted for API compatibility; not used.
 */
function resetRateLimit(?PDO $pdo, string $action, string $identifier): void
{
    $identifier = substr(trim($identifier), 0, 128);
    $action     = substr(trim($action),     0, 64);

    try {
        getRedis()->del(_rlKey($action, $identifier));
    } catch (Throwable $e) {
        error_log('resetRateLimit Redis error: ' . $e->getMessage());
    }
}

/**
 * Get remaining attempts for an action/identifier in the current window.
 *
 * @param PDO|null $pdo Accepted for API compatibility; not used.
 * @return int  Remaining attempts (0 = currently blocked).
 */
function getRemainingAttempts(?PDO $pdo, string $action, string $identifier, int $max, int $window_sec): int
{
    $identifier = substr(trim($identifier), 0, 128);
    $action     = substr(trim($action),     0, 64);

    try {
        $current = (int) getRedis()->get(_rlKey($action, $identifier));
        return max(0, $max - $current);
    } catch (Throwable $e) {
        error_log('getRemainingAttempts Redis error: ' . $e->getMessage());
        return $max; // fail open
    }
}
