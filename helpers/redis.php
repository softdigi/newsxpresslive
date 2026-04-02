<?php
/**
 * helpers/redis.php
 *
 * Returns a shared Redis connection configured entirely from environment
 * variables. No credentials are hard-coded.
 *
 * Environment variables (all optional — sensible defaults provided):
 *   REDIS_HOST      — hostname / socket path  (default: 127.0.0.1)
 *   REDIS_PORT      — TCP port                (default: 6379)
 *   REDIS_PASSWORD  — AUTH password           (default: none)
 *   REDIS_DB        — logical DB index        (default: 0)
 *   REDIS_TIMEOUT   — connection timeout (s)  (default: 2.0)
 *
 * Requires the PHP redis extension (ext-redis).
 * Install: apt-get install php-redis  or  pecl install redis
 *
 * Usage:
 *   require_once __DIR__ . '/redis.php';
 *   $redis = getRedis();
 *   $redis->set('key', 'value');
 */

function getRedis(): Redis
{
    static $instance = null;

    if ($instance !== null) {
        return $instance;
    }

    $host     = getenv('REDIS_HOST')     ?: '127.0.0.1';
    $port     = (int)(getenv('REDIS_PORT')     ?: 6379);
    $password = getenv('REDIS_PASSWORD') ?: null;
    $db       = (int)(getenv('REDIS_DB')       ?: 0);
    $timeout  = (float)(getenv('REDIS_TIMEOUT') ?: 2.0);

    $redis = new Redis();

    if (!$redis->connect($host, $port, $timeout)) {
        throw new RuntimeException('Redis connection failed');
    }

    if ($password !== null && $password !== '') {
        if (!$redis->auth($password)) {
            throw new RuntimeException('Redis authentication failed');
        }
    }

    if ($db !== 0) {
        $redis->select($db);
    }

    $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

    $instance = $redis;

    return $instance;
}
