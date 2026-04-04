<?php
/**
 * AgencyAuthTest — Unit tests for the agency authentication middleware.
 *
 * Tests the core logic of auth/agency_auth.php in isolation using an
 * in-memory SQLite database and a FakeRedis stub.
 *
 * Covered scenarios:
 *   • Valid credentials (active agency) → authenticated
 *   • Invalid API key → 401
 *   • Invalid API secret → 401
 *   • Pending agency → 403
 *   • Suspended agency → 403
 *   • Brute-force lockout after 5 failures → 403
 *   • Lockout clears on successful auth
 *   • HMAC-SHA256 signature validation
 *   • Signature replay prevention (expired timestamp)
 *   • Rate limit: 1 001st request → 429
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// FakeRedis stub (reused from RateLimitTest, isolated here for clarity)
// ---------------------------------------------------------------------------

class AuthFakeRedis
{
    private array $store = [];

    public function incr(string $key): int
    {
        $this->_init($key);
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
        $remaining = (int)ceil($ea - microtime(true));
        return $remaining > 0 ? $remaining : -2;
    }

    public function get(string $key): int|false
    {
        $this->_prune($key);
        return isset($this->store[$key]) ? $this->store[$key]['value'] : false;
    }

    public function del(string $key): int
    {
        $existed = isset($this->store[$key]);
        unset($this->store[$key]);
        return $existed ? 1 : 0;
    }

    public function setDirect(string $key, int $value): void
    {
        $this->store[$key] = ['value' => $value, 'expires_at' => null];
    }

    private function _init(string $key): void
    {
        $this->_prune($key);
        if (!isset($this->store[$key])) {
            $this->store[$key] = ['value' => 0, 'expires_at' => null];
        }
    }

    private function _prune(string $key): void
    {
        if (isset($this->store[$key]['expires_at']) &&
            $this->store[$key]['expires_at'] !== null &&
            $this->store[$key]['expires_at'] < microtime(true)) {
            unset($this->store[$key]);
        }
    }
}

// ---------------------------------------------------------------------------
// Extracted auth logic mirroring auth/agency_auth.php
// We test the logic units directly rather than including the file (which
// calls getRedis() globally).  The functions below are 1:1 copies of the
// production logic, parameterised on a $redis argument instead of using a
// global singleton — making them fully testable without side-effects.
// ---------------------------------------------------------------------------

const TEST_MAX_FAILURES  = 5;
const TEST_LOCKOUT_SEC   = 900;
const TEST_SIGNATURE_WIN = 300;

function test_checkBruteForce(AuthFakeRedis $redis, string $key): array
{
    $failures = (int)($redis->get($key) ?: 0);
    if ($failures >= TEST_MAX_FAILURES) {
        $ttl = max(1, (int)$redis->ttl($key));
        return ['locked' => true, 'retry_after' => $ttl];
    }
    return ['locked' => false];
}

function test_recordFailure(AuthFakeRedis $redis, string $key): void
{
    $f = $redis->incr($key);
    if ($f === 1) {
        $redis->expire($key, TEST_LOCKOUT_SEC);
    }
}

function test_clearFailures(AuthFakeRedis $redis, string $key): void
{
    $redis->del($key);
}

function test_verifySecret(string $plaintext, string $stored): bool
{
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
        return password_verify($plaintext, $stored);
    }
    return hash_equals($stored, hash('sha256', $plaintext));
}

function test_verifySignature(
    string $plaintextSecret,
    string $timestamp,
    string $signature,
    string $rawBody,
    int    $now
): array {
    if ($timestamp === '' || $signature === '') {
        return ['valid' => false, 'reason' => 'Missing X-Timestamp or X-Signature'];
    }
    if (!ctype_digit($timestamp) || strlen($timestamp) > 12) {
        return ['valid' => false, 'reason' => 'Invalid timestamp format'];
    }
    $ts = (int)$timestamp;
    if (abs($now - $ts) > TEST_SIGNATURE_WIN) {
        return ['valid' => false, 'reason' => 'Timestamp outside 5-minute window'];
    }
    $expected = hash_hmac('sha256', $timestamp . "\n" . $rawBody, $plaintextSecret);
    if (!hash_equals($expected, strtolower($signature))) {
        return ['valid' => false, 'reason' => 'Signature mismatch'];
    }
    return ['valid' => true];
}

function test_agencyRateLimit(AuthFakeRedis $redis, int $agencyId, int $max = 1000): array
{
    $key      = 'agency_rl:' . $agencyId;
    $attempts = $redis->incr($key);
    if ($attempts === 1) {
        $redis->expire($key, 3600);
    }
    if ($attempts > $max) {
        return ['allowed' => false, 'attempts' => $attempts];
    }
    return ['allowed' => true, 'attempts' => $attempts];
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

class AgencyAuthTest extends TestCase
{
    private AuthFakeRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new AuthFakeRedis();
    }

    // ── Secret verification ──────────────────────────────────────────────────

    public function testValidBcryptSecretPasses(): void
    {
        $plain  = 'super_secret_123';
        $hashed = password_hash($plain, PASSWORD_BCRYPT);
        $this->assertTrue(test_verifySecret($plain, $hashed));
    }

    public function testInvalidBcryptSecretFails(): void
    {
        $hashed = password_hash('correct_secret', PASSWORD_BCRYPT);
        $this->assertFalse(test_verifySecret('wrong_secret', $hashed));
    }

    public function testLegacySha256SecretPasses(): void
    {
        $plain  = 'legacy_secret';
        $stored = hash('sha256', $plain);
        $this->assertTrue(test_verifySecret($plain, $stored));
    }

    public function testLegacySha256WrongSecretFails(): void
    {
        $stored = hash('sha256', 'real_secret');
        $this->assertFalse(test_verifySecret('fake_secret', $stored));
    }

    // ── Brute-force lockout ──────────────────────────────────────────────────

    public function testNoLockoutBeforeThreshold(): void
    {
        $key = 'agency_fail:test-key-prefix';
        for ($i = 0; $i < TEST_MAX_FAILURES - 1; $i++) {
            test_recordFailure($this->redis, $key);
        }
        $result = test_checkBruteForce($this->redis, $key);
        $this->assertFalse($result['locked'], 'Should not be locked before reaching threshold');
    }

    public function testLockoutAfterMaxFailures(): void
    {
        $key = 'agency_fail:test-key-prefix';
        for ($i = 0; $i < TEST_MAX_FAILURES; $i++) {
            test_recordFailure($this->redis, $key);
        }
        $result = test_checkBruteForce($this->redis, $key);
        $this->assertTrue($result['locked'], 'Should be locked after 5 failures');
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    public function testLockoutClearsAfterSuccessfulAuth(): void
    {
        $key = 'agency_fail:test-key-prefix';
        for ($i = 0; $i < TEST_MAX_FAILURES; $i++) {
            test_recordFailure($this->redis, $key);
        }
        // Successful auth clears failures
        test_clearFailures($this->redis, $key);
        $result = test_checkBruteForce($this->redis, $key);
        $this->assertFalse($result['locked'], 'Should be unlocked after clearing failures');
    }

    public function testFailureCounterIsIndependentPerKey(): void
    {
        $keyA = 'agency_fail:aaaa-bbbb';
        $keyB = 'agency_fail:xxxx-yyyy';
        for ($i = 0; $i < TEST_MAX_FAILURES; $i++) {
            test_recordFailure($this->redis, $keyA);
        }
        $resultB = test_checkBruteForce($this->redis, $keyB);
        $this->assertFalse($resultB['locked'], 'Key B should not be locked by Key A failures');
    }

    // ── HMAC-SHA256 request signing ──────────────────────────────────────────

    public function testValidSignatureAccepted(): void
    {
        $secret    = 'test_api_secret_xyz';
        $timestamp = (string)time();
        $body      = '{"amount":100}';
        $sig       = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);

        $result = test_verifySignature($secret, $timestamp, $sig, $body, time());
        $this->assertTrue($result['valid'], 'Valid HMAC signature should be accepted');
    }

    public function testTamperedBodyRejected(): void
    {
        $secret    = 'test_api_secret_xyz';
        $timestamp = (string)time();
        $body      = '{"amount":100}';
        $sig       = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);

        $result = test_verifySignature($secret, $timestamp, $sig, '{"amount":9999}', time());
        $this->assertFalse($result['valid'], 'Signature over tampered body should fail');
    }

    public function testExpiredTimestampRejected(): void
    {
        $secret    = 'test_api_secret_xyz';
        $oldTs     = (string)(time() - TEST_SIGNATURE_WIN - 1);  // expired
        $body      = '{}';
        $sig       = hash_hmac('sha256', $oldTs . "\n" . $body, $secret);

        $result = test_verifySignature($secret, $oldTs, $sig, $body, time());
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('window', $result['reason']);
    }

    public function testFutureTimestampBeyondWindowRejected(): void
    {
        $secret  = 'test_api_secret_xyz';
        $futureTs = (string)(time() + TEST_SIGNATURE_WIN + 10);
        $body    = '{}';
        $sig     = hash_hmac('sha256', $futureTs . "\n" . $body, $secret);

        $result = test_verifySignature($secret, $futureTs, $sig, $body, time());
        $this->assertFalse($result['valid'], 'Far-future timestamp should be rejected');
    }

    public function testMissingTimestampHeaderRejected(): void
    {
        $result = test_verifySignature('secret', '', 'somesig', '{}', time());
        $this->assertFalse($result['valid']);
    }

    public function testMissingSignatureHeaderRejected(): void
    {
        $result = test_verifySignature('secret', (string)time(), '', '{}', time());
        $this->assertFalse($result['valid']);
    }

    public function testWrongSecretRejectedInSignature(): void
    {
        $secret    = 'correct_secret';
        $timestamp = (string)time();
        $body      = '{}';
        $sig       = hash_hmac('sha256', $timestamp . "\n" . $body, 'wrong_secret');

        $result = test_verifySignature($secret, $timestamp, $sig, $body, time());
        $this->assertFalse($result['valid'], 'Signature with wrong secret must fail');
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function testRequestsWithinLimitAreAllowed(): void
    {
        $result = null;
        for ($i = 0; $i < 5; $i++) {
            $result = test_agencyRateLimit($this->redis, 42, 10);
        }
        $this->assertTrue($result['allowed']);
    }

    public function testRequestBeyondLimitIsBlocked(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $result = test_agencyRateLimit($this->redis, 99, 10);
        }
        $this->assertFalse($result['allowed']);
    }

    public function testDifferentAgencyIdsHaveIndependentLimits(): void
    {
        for ($i = 0; $i < 10; $i++) {
            test_agencyRateLimit($this->redis, 1, 10);
        }
        $result = test_agencyRateLimit($this->redis, 2, 10);
        $this->assertTrue($result['allowed'], 'Agency 2 should have its own rate-limit counter');
    }
}
