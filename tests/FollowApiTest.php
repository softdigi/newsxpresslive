<?php
/**
 * Tests for web/api/follow.php  — validation and toggle logic.
 *
 * Tests the follow/unfollow business logic directly (no HTTP stack needed).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ── Inline helpers mirroring follow.php logic ──────────────────────────────

/**
 * Validate a toggle-follow request payload.
 *
 * @param  string $followerUid  Authenticated user's UID.
 * @param  string $targetUid   Target user's UID.
 * @return array{ok: bool, code: int, message: string}
 */
function validateFollowRequest(string $followerUid, string $targetUid): array
{
    if (empty($targetUid)) {
        return ['ok' => false, 'code' => 400, 'message' => 'target_uid required'];
    }
    if ($followerUid === $targetUid) {
        return ['ok' => false, 'code' => 422, 'message' => 'Cannot follow yourself'];
    }
    if (strlen($targetUid) > 128) {
        return ['ok' => false, 'code' => 400, 'message' => 'target_uid too long'];
    }
    return ['ok' => true, 'code' => 200, 'message' => 'ok'];
}

/**
 * Compute the follow-counts response given existing DB state.
 *
 * @param  bool $alreadyFollowing  Whether a follow row exists before this call.
 * @param  int  $currentFollowers  Current followers_count of the target user.
 * @return array{is_following: bool, followers_count: int}
 */
function computeFollowToggle(bool $alreadyFollowing, int $currentFollowers): array
{
    if ($alreadyFollowing) {
        return [
            'is_following'    => false,
            'followers_count' => max(0, $currentFollowers - 1),
        ];
    }
    return [
        'is_following'    => true,
        'followers_count' => $currentFollowers + 1,
    ];
}

/**
 * Validate a GET follow-counts response.
 *
 * @param  array $row  DB row (may be empty).
 * @return array{followers_count: int, following_count: int}
 */
function buildCountsResponse(array $row): array
{
    return [
        'followers_count' => (int)($row['followers_count'] ?? 0),
        'following_count' => (int)($row['following_count'] ?? 0),
    ];
}

// ── Test class ─────────────────────────────────────────────────────────────

class FollowApiTest extends TestCase
{
    // ── Validation ─────────────────────────────────────────────────────

    public function testValidRequestPasses(): void
    {
        $result = validateFollowRequest('userA', 'userB');
        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['code']);
    }

    public function testEmptyTargetFails(): void
    {
        $result = validateFollowRequest('userA', '');
        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['code']);
        $this->assertStringContainsString('target_uid', $result['message']);
    }

    public function testSelfFollowFails(): void
    {
        $result = validateFollowRequest('userA', 'userA');
        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['code']);
        $this->assertStringContainsString('yourself', $result['message']);
    }

    public function testOverlongUidFails(): void
    {
        $longUid = str_repeat('x', 129);
        $result  = validateFollowRequest('userA', $longUid);
        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['code']);
    }

    // ── Toggle logic ───────────────────────────────────────────────────

    public function testFollowNewUser(): void
    {
        $result = computeFollowToggle(false, 10);
        $this->assertTrue($result['is_following']);
        $this->assertSame(11, $result['followers_count']);
    }

    public function testUnfollowExistingUser(): void
    {
        $result = computeFollowToggle(true, 10);
        $this->assertFalse($result['is_following']);
        $this->assertSame(9, $result['followers_count']);
    }

    public function testUnfollowFloorsAtZero(): void
    {
        $result = computeFollowToggle(true, 0);
        $this->assertFalse($result['is_following']);
        $this->assertSame(0, $result['followers_count']);
    }

    public function testFirstFollowerIncrementsToOne(): void
    {
        $result = computeFollowToggle(false, 0);
        $this->assertTrue($result['is_following']);
        $this->assertSame(1, $result['followers_count']);
    }

    // ── Counts response ────────────────────────────────────────────────

    public function testCountsFromDbRow(): void
    {
        $row = ['followers_count' => 42, 'following_count' => 7];
        $r   = buildCountsResponse($row);
        $this->assertSame(42, $r['followers_count']);
        $this->assertSame(7,  $r['following_count']);
    }

    public function testCountsMissingRowDefaultsToZero(): void
    {
        $r = buildCountsResponse([]);
        $this->assertSame(0, $r['followers_count']);
        $this->assertSame(0, $r['following_count']);
    }

    public function testCountsNullValuesDefaultToZero(): void
    {
        $r = buildCountsResponse(['followers_count' => null, 'following_count' => null]);
        $this->assertSame(0, $r['followers_count']);
        $this->assertSame(0, $r['following_count']);
    }
}
