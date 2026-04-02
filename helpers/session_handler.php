<?php
/**
 * helpers/session_handler.php
 *
 * Migrate PHP sessions from the local filesystem to Redis so that session
 * state is shared across every node behind a load balancer.
 *
 * Call initRedisSession() BEFORE session_start() at each entry point that
 * uses sessions (e.g. web portal pages, admin panel pages):
 *
 *   require_once __DIR__ . '/../helpers/session_handler.php';
 *   initRedisSession();
 *   session_start();
 *
 * Environment variables (consumed from helpers/redis.php):
 *   REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, REDIS_DB, REDIS_TIMEOUT
 *
 * Additional optional variable:
 *   SESSION_TTL  — session lifetime in seconds (default: 7200 = 2 hours)
 *
 * Implementation uses RedisSessionHandler from ext-redis (available since
 * redis extension ≥ 5.3) which is fully thread-safe and lock-aware.
 * Falls back gracefully to the default file handler if Redis is unavailable,
 * so a Redis outage does not take down the web tier.
 */

require_once __DIR__ . '/redis.php';

function initRedisSession(): void
{
    $ttl = (int)(getenv('SESSION_TTL') ?: 7200);

    try {
        $redis   = getRedis();
        $handler = new RedisSessionHandler($redis, ['prefix' => 'sess:', 'ttl' => $ttl]);

        session_set_save_handler($handler, true);

        // Harden the session cookie
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure',   '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime',  (string) $ttl);

    } catch (Throwable $e) {
        // Redis unavailable — fall back to file sessions and log the error
        error_log('Redis session handler unavailable, using file fallback: ' . $e->getMessage());
        // File-based sessions still work on single-node deployments
    }
}
