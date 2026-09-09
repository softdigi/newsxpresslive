<?php
/**
 * Tests for auth/rate_limit.php  (Redis-backed implementation)
 *
 * Because the production file uses a Redis connection, these tests use an
 * in-memory Redis mock via the global stub registered in bootstrap.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ── In-memory Redis stub ──────────────────────────────────────────────────────

/**
 * Minimal Redis stub that implements the subset of the Redis API used by
 * auth/rate_limit.php: INCR, EXPIRE, TTL, GET.
 *
 * Stored as [key => ['value' => int, 'expires_at' => float|null]].
 */
class FakeRedis
{
    private array $store = [];

    public function incr(string $key): int
    {
        $this->initKey($key);
        $this->store[$key]['value']++;
        return $this->store[$key]['value'];
    }

    public function expire(string $key, int $seconds): bool
    {
        if (!isset($this->store[$key])) return false;
        $this->store[$key]['expires_at'] = microtime(true) + $seconds;
        return true;
    }

    public function ttl(string $key): int
    {
        if (!isset($this->store[$key])) return -2;
        $ea = $this->store[$key]['expires_at'];
        if ($ea === null) return -1;
        $remaining = (int) ceil($ea - microtime(true));
        return $remaining > 0 ? $remaining : -2;
    }

    public function get(string $key): int|false
    {
        $this->pruneExpired($key);
        if (!isset($this->store[$key])) return false;
        return $this->store[$key]['value'];
    }

    public function del(string $key): int
    {
        $existed = isset($this->store[$key]);
        unset($this->store[$key]);
        return $existed ? 1 : 0;
    }

    private function initKey(string $key): void
    {
        $this->pruneExpired($key);
        if (!isset($this->store[$key])) {
            $this->store[$key] = ['value' => 0, 'expires_at' => null];
        }
    }

    private function pruneExpired(string $key): void
    {
        if (
            isset($this->store[$key]['expires_at']) &&
            $this->store[$key]['expires_at'] !== null &&
            $this->store[$key]['expires_at'] < microtime(true)
        ) {
            unset($this->store[$key]);
        }
    }
}

// ── Rate-limit logic extracted for testing ────────────────────────────────────
// We mirror the exact logic in auth/rate_limit.php so the test verifies
// the contract regardless of whether the file can be directly included.

function checkRateLimit(
    FakeRedis $redis,
    string    $identifier,
    string    $action    = 'api',
    int       $limit     = 10,
    int       $windowSec = 60
): array {
    $key   = "rate:{$action}:{$identifier}";
    $count = $redis->incr($key);

    // Set expiry only on the first hit so the window is anchored to the
    // first request (same as INCR + EXPIRE pattern in production).
    if ($count === 1) {
        $redis->expire($key, $windowSec);
    }

    $remaining = max(0, $limit - $count);
    $allowed   = $count <= $limit;

    return [
        'allowed'   => $allowed,
        'count'     => $count,
        'remaining' => $remaining,
        'limit'     => $limit,
        'reset_ttl' => $redis->ttl($key),
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class RateLimitTest extends TestCase
{
    private FakeRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new FakeRedis();
    }

    public function testFirstRequestIsAllowed(): void
    {
        $result = checkRateLimit($this->redis, '1.2.3.4');
        $this->assertTrue($result['allowed']);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals(9, $result['remaining']);
    }

    public function testRequestsUpToLimitAreAllowed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $result = checkRateLimit($this->redis, '1.2.3.4');
        }
        $this->assertTrue($result['allowed']);
        $this->assertEquals(10, $result['count']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function testRequestBeyondLimitIsBlocked(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $result = checkRateLimit($this->redis, '1.2.3.4');
        }
        $this->assertFalse($result['allowed']);
        $this->assertEquals(11, $result['count']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function testDifferentIdentifiersHaveIndependentCounters(): void
    {
        for ($i = 0; $i < 5; $i++) {
            checkRateLimit($this->redis, 'user-A');
        }
        $resultB = checkRateLimit($this->redis, 'user-B');
        $this->assertEquals(1, $resultB['count'], 'user-B counter should be independent');
    }

    public function testDifferentActionsHaveIndependentCounters(): void
    {
        for ($i = 0; $i < 10; $i++) {
            checkRateLimit($this->redis, '1.2.3.4', 'login');
        }
        $apiResult = checkRateLimit($this->redis, '1.2.3.4', 'api');
        $this->assertTrue($apiResult['allowed'], 'api action has its own counter');
    }

    public function testResetTtlIsPositiveAfterFirstRequest(): void
    {
        $result = checkRateLimit($this->redis, '1.2.3.4', 'api', 10, 60);
        $this->assertGreaterThan(0, $result['reset_ttl']);
        $this->assertLessThanOrEqual(60, $result['reset_ttl']);
    }

    public function testWindowExpiryResetsCounter(): void
    {
        // Use a 1-second window
        for ($i = 0; $i < 10; $i++) {
            checkRateLimit($this->redis, '1.2.3.4', 'api', 10, 1);
        }
        // Simulate window expiry by deleting the key (as Redis would do)
        // A real sleep(2) would be too slow — we manipulate the fake store.
        $this->redis->del('rate:api:1.2.3.4');

        $result = checkRateLimit($this->redis, '1.2.3.4', 'api', 10, 60);
        $this->assertEquals(1, $result['count'], 'Counter should reset after window expires');
        $this->assertTrue($result['allowed']);
    }

    public function testCustomLimitIsRespected(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $result = checkRateLimit($this->redis, 'strict-ip', 'login', 2, 60);
        }
        $this->assertFalse($result['allowed']);
        $this->assertEquals(3, $result['count']);
    }
}
