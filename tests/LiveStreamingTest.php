<?php
/**
 * Tests for the Live News Streaming API (v12).
 *
 * Tests validation and business logic without a real HTTP stack or database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ── Inline helpers mirroring live API logic ────────────────────────────────

/**
 * Validate heartbeat request parameters.
 */
function validateHeartbeatParams(int $streamId, string $sessionId): array
{
    if ($streamId <= 0) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_params'];
    }
    if (strlen($sessionId) < 4 || strlen($sessionId) > 64) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_params'];
    }
    if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_session_id'];
    }
    return ['ok' => true, 'code' => 200, 'error' => null];
}

/**
 * Validate reaction request parameters.
 */
function validateReactionParams(int $streamId, string $sessionId, string $emoji): array
{
    if ($streamId <= 0 || strlen($sessionId) < 4) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_params'];
    }
    if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_session_id'];
    }
    $trimmed = mb_substr(trim($emoji), 0, 10);
    if ($trimmed === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_emoji'];
    }
    return ['ok' => true, 'code' => 200, 'error' => null];
}

/**
 * Validate chat message parameters.
 */
function validateChatParams(int $streamId, string $sessionId, string $message): array
{
    if ($streamId <= 0 || strlen($sessionId) < 4 || $message === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_params'];
    }
    if (!preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $sessionId)) {
        return ['ok' => false, 'code' => 400, 'error' => 'invalid_session_id'];
    }
    if (mb_strlen($message) > 300) {
        return ['ok' => false, 'code' => 400, 'error' => 'message_too_long'];
    }
    return ['ok' => true, 'code' => 200, 'error' => null];
}

/**
 * Compute new viewer count after heartbeat upsert/prune cycle.
 *
 * @param int $existing   Active viewers before this heartbeat.
 * @param bool $isNewSession  Whether this session_id is new.
 * @return int
 */
function computeViewerCount(int $existing, bool $isNewSession): int
{
    return $isNewSession ? $existing + 1 : $existing;
}

/**
 * Build a feed status filter from a comma-separated status param.
 *
 * @param string $param
 * @return array<string>
 */
function parseFeedStatusParam(string $param): array
{
    $allowed   = ['live', 'scheduled', 'ended', 'cancelled'];
    $requested = array_filter(
        array_map('trim', explode(',', $param)),
        static fn($s) => in_array($s, $allowed, true)
    );
    return empty($requested) ? ['live', 'scheduled'] : array_values($requested);
}

// ── Test class ─────────────────────────────────────────────────────────────

class LiveStreamingTest extends TestCase
{
    // ── Heartbeat validation ──────────────────────────────────────────────

    public function testHeartbeatAcceptsValidParams(): void
    {
        $result = validateHeartbeatParams(1, 'abc123');
        $this->assertTrue($result['ok']);
        $this->assertEquals(200, $result['code']);
    }

    public function testHeartbeatRejectsZeroStreamId(): void
    {
        $result = validateHeartbeatParams(0, 'abc123');
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testHeartbeatRejectsNegativeStreamId(): void
    {
        $result = validateHeartbeatParams(-5, 'abc123');
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testHeartbeatRejectsShortSessionId(): void
    {
        $result = validateHeartbeatParams(1, 'ab');
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testHeartbeatRejectsSessionIdWithSpecialChars(): void
    {
        $result = validateHeartbeatParams(1, 'bad!session@id');
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
        $this->assertEquals('invalid_session_id', $result['error']);
    }

    public function testHeartbeatRejectsSessionIdTooLong(): void
    {
        $result = validateHeartbeatParams(1, str_repeat('a', 65));
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testHeartbeatAcceptsUuidStyleSessionId(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $result = validateHeartbeatParams(42, $uuid);
        $this->assertTrue($result['ok']);
    }

    // ── Reaction validation ───────────────────────────────────────────────

    public function testReactionAcceptsValidParams(): void
    {
        $result = validateReactionParams(1, 'sess-1234', '❤️');
        $this->assertTrue($result['ok']);
    }

    public function testReactionRejectsZeroStreamId(): void
    {
        $result = validateReactionParams(0, 'sess-1234', '❤️');
        $this->assertFalse($result['ok']);
        $this->assertEquals('invalid_params', $result['error']);
    }

    public function testReactionRejectsBadSessionId(): void
    {
        $result = validateReactionParams(1, 'x!y', '❤️');
        $this->assertFalse($result['ok']);
    }

    public function testReactionAcceptsVariousEmojis(): void
    {
        foreach (['❤️', '🔥', '👏', '😮', '😂'] as $emoji) {
            $result = validateReactionParams(1, 'sess-1234', $emoji);
            $this->assertTrue($result['ok'], "Failed for emoji: $emoji");
        }
    }

    // ── Chat validation ───────────────────────────────────────────────────

    public function testChatAcceptsValidMessage(): void
    {
        $result = validateChatParams(1, 'sess-1234', 'Hello world!');
        $this->assertTrue($result['ok']);
    }

    public function testChatRejectsEmptyMessage(): void
    {
        $result = validateChatParams(1, 'sess-1234', '');
        $this->assertFalse($result['ok']);
        $this->assertEquals('invalid_params', $result['error']);
    }

    public function testChatRejectsMessageOver300Chars(): void
    {
        $result = validateChatParams(1, 'sess-1234', str_repeat('a', 301));
        $this->assertFalse($result['ok']);
        $this->assertEquals('message_too_long', $result['error']);
    }

    public function testChatAcceptsMessageExactly300Chars(): void
    {
        $result = validateChatParams(1, 'sess-1234', str_repeat('a', 300));
        $this->assertTrue($result['ok']);
    }

    public function testChatRejectsBadSessionId(): void
    {
        $result = validateChatParams(1, 'bad session!', 'Hello');
        $this->assertFalse($result['ok']);
    }

    // ── Viewer count logic ────────────────────────────────────────────────

    public function testNewSessionIncrementsCount(): void
    {
        $count = computeViewerCount(10, true);
        $this->assertEquals(11, $count);
    }

    public function testExistingSessionDoesNotIncrementCount(): void
    {
        $count = computeViewerCount(10, false);
        $this->assertEquals(10, $count);
    }

    public function testFirstViewerCount(): void
    {
        $count = computeViewerCount(0, true);
        $this->assertEquals(1, $count);
    }

    // ── Feed status filter parsing ─────────────────────────────────────────

    public function testParseLiveStatus(): void
    {
        $result = parseFeedStatusParam('live');
        $this->assertEquals(['live'], $result);
    }

    public function testParseMultipleStatuses(): void
    {
        $result = parseFeedStatusParam('live,scheduled');
        $this->assertContains('live',      $result);
        $this->assertContains('scheduled', $result);
        $this->assertCount(2, $result);
    }

    public function testParseInvalidStatusDefaultsToLiveScheduled(): void
    {
        $result = parseFeedStatusParam('bogus');
        $this->assertEquals(['live', 'scheduled'], $result);
    }

    public function testParseEmptyStatusDefaultsToLiveScheduled(): void
    {
        $result = parseFeedStatusParam('');
        $this->assertEquals(['live', 'scheduled'], $result);
    }

    public function testParseStripsInvalidStatuses(): void
    {
        $result = parseFeedStatusParam('live,bogus,scheduled');
        $this->assertContains('live',      $result);
        $this->assertContains('scheduled', $result);
        $this->assertNotContains('bogus',  $result);
    }

    public function testParseAllStatuses(): void
    {
        foreach (['live', 'scheduled', 'ended', 'cancelled'] as $status) {
            $result = parseFeedStatusParam($status);
            $this->assertContains($status, $result);
        }
    }

    public function testParseAllKeyword(): void
    {
        // 'all' is not a DB status — the feed.php handles it separately,
        // but parseFeedStatusParam should default for unknown tokens.
        $result = parseFeedStatusParam('all');
        $this->assertEquals(['live', 'scheduled'], $result);
    }
}
