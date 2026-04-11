<?php
/**
 * helpers/cache.php
 *
 * TIER 2 — Performance: ApiCache class
 *
 * A lightweight caching abstraction that uses Redis as the primary store
 * and falls back to APCu (in-process) when Redis is unavailable.
 * All keys are namespaced to avoid collisions with other applications
 * sharing the same Redis instance.
 *
 * Usage:
 *   require_once __DIR__ . '/cache.php';
 *   $cache = ApiCache::getInstance();
 *
 *   // Store with TTL (seconds)
 *   $cache->set('trending:news', $data, 300);
 *
 *   // Retrieve (returns null on miss)
 *   $data = $cache->get('trending:news');
 *
 *   // Delete
 *   $cache->delete('trending:news');
 *
 *   // Get-or-set pattern (recommended)
 *   $data = $cache->remember('categories', 3600, function() use ($pdo) {
 *       return fetchCategoriesFromDb($pdo);
 *   });
 *
 *   // Flush all keys in the app namespace
 *   $cache->flush();
 */
class ApiCache
{
    /** @var ApiCache|null */
    private static ?ApiCache $instance = null;

    /** @var Redis|null */
    private ?Redis $redis = null;

    /** @var bool */
    private bool $useApcu = false;

    /** Namespace prefix to avoid collisions */
    private string $prefix;

    private function __construct()
    {
        $this->prefix = (getenv('CACHE_PREFIX') ?: 'nxl') . ':';

        // Try Redis first
        require_once __DIR__ . '/redis.php';
        try {
            $this->redis = getRedis();
            // Validate connection with a ping
            if ($this->redis->ping() !== true && $this->redis->ping() !== '+PONG') {
                $this->redis = null;
            }
        } catch (Exception $e) {
            $this->redis = null;
            error_log('ApiCache: Redis unavailable (' . $e->getMessage() . '), using APCu fallback');
        }

        // Fall back to APCu if Redis is unavailable
        if ($this->redis === null && function_exists('apcu_fetch')) {
            $this->useApcu = true;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a cached value. Returns null on miss or error.
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;

        try {
            if ($this->redis !== null) {
                $raw = $this->redis->get($fullKey);
                if ($raw === false) return null;
                return json_decode($raw, true);
            }

            if ($this->useApcu) {
                $value = apcu_fetch($fullKey, $success);
                return $success ? $value : null;
            }
        } catch (Exception $e) {
            error_log('ApiCache::get error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Store a value. Returns true on success.
     *
     * @param int $ttl  Time-to-live in seconds (0 = no expiry)
     */
    public function set(string $key, mixed $value, int $ttl = 300): bool
    {
        $fullKey = $this->prefix . $key;

        try {
            if ($this->redis !== null) {
                $raw = json_encode($value, JSON_UNESCAPED_UNICODE);
                if ($ttl > 0) {
                    return $this->redis->setex($fullKey, $ttl, $raw) === true;
                }
                return $this->redis->set($fullKey, $raw) === true;
            }

            if ($this->useApcu) {
                return apcu_store($fullKey, $value, $ttl);
            }
        } catch (Exception $e) {
            error_log('ApiCache::set error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Delete a cached key.
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;

        try {
            if ($this->redis !== null) {
                return (bool)$this->redis->del($fullKey);
            }
            if ($this->useApcu) {
                return apcu_delete($fullKey);
            }
        } catch (Exception $e) {
            error_log('ApiCache::delete error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Get-or-set pattern.
     * If the key is in cache, return it. Otherwise execute $callback,
     * store the result with the given TTL, and return it.
     *
     * @param  callable $callback  Returns the value to cache
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Check if a key exists in the cache.
     */
    public function has(string $key): bool
    {
        $fullKey = $this->prefix . $key;

        try {
            if ($this->redis !== null) {
                return (bool)$this->redis->exists($fullKey);
            }
            if ($this->useApcu) {
                return apcu_exists($fullKey);
            }
        } catch (Exception $e) { /* ignore */ }

        return false;
    }

    /**
     * Flush all keys in the app namespace.
     * Uses SCAN on Redis to avoid blocking with FLUSHDB.
     */
    public function flush(): void
    {
        try {
            if ($this->redis !== null) {
                $iterator = null;
                $pattern  = $this->prefix . '*';
                do {
                    $keys = $this->redis->scan($iterator, $pattern, 100);
                    if ($keys) {
                        $this->redis->del($keys);
                    }
                } while ($iterator !== 0);
                return;
            }

            if ($this->useApcu) {
                apcu_clear_cache();
            }
        } catch (Exception $e) {
            error_log('ApiCache::flush error: ' . $e->getMessage());
        }
    }

    /**
     * Returns which backend is active: 'redis', 'apcu', or 'none'.
     */
    public function backend(): string
    {
        if ($this->redis !== null) return 'redis';
        if ($this->useApcu) return 'apcu';
        return 'none';
    }
}
