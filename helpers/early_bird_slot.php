<?php
/**
 * helpers/early_bird_slot.php
 *
 * EarlyBirdSlotManager — atomic slot claiming for blue-tick early-bird offers.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  PRIMARY path  — Redis INCR (lock-free, O(1), no transaction overhead)  │
 * │  FALLBACK path — MySQL SELECT … FOR UPDATE (when Redis is unavailable)  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Redis key:  early_bird:{type}:count   (e.g. early_bird:reporter:count)
 *
 * Redis commands used:
 *   EXISTS key          — check whether the key has been seeded
 *   SETNX  key value    — seed from MySQL on cold start (atomic, race-safe)
 *   INCR   key          — atomically claim the next slot number
 *   DECR   key          — undo the claim when slot > freeLimit
 *
 * MySQL table referenced:
 *   early_bird_counters (type ENUM, count INT, free_limit INT)
 *
 * Usage (production):
 *   require_once __DIR__ . '/early_bird_slot.php';
 *   require_once __DIR__ . '/redis.php';
 *
 *   try {
 *       $redis = getRedis();
 *   } catch (RuntimeException $e) {
 *       $redis = null; // fall back to MySQL
 *   }
 *   $result = EarlyBirdSlotManager::claimSlot('reporter', 100, $pdo, $redis);
 *   if (!$result['success']) { ... }
 *   $slot = $result['slot']; // 1-based sequential slot number
 */

declare(strict_types=1);

class EarlyBirdSlotManager
{
    /** Redis key prefix */
    private const KEY_PREFIX = 'early_bird:';

    /** Redis key suffix */
    private const KEY_SUFFIX = ':count';

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Claim the next early-bird slot.
     *
     * Tries the Redis path first.  If $redis is null, or if any Redis
     * operation throws, falls back to the MySQL FOR UPDATE path.
     *
     * @param string      $type       'reporter' or 'agency'
     * @param int         $freeLimit  Max free slots (e.g. 100 for reporter)
     * @param PDO         $pdo        Active database connection
     * @param object|null $redis      Redis instance (real or stub); null → MySQL
     *
     * @return array{
     *   success: bool,
     *   slot:    int|null,
     *   source:  string,
     *   message: string
     * }
     */
    public static function claimSlot(
        string  $type,
        int     $freeLimit,
        PDO     $pdo,
        ?object $redis = null
    ): array {
        if ($redis !== null) {
            try {
                return self::claimViaRedis($type, $freeLimit, $pdo, $redis);
            } catch (\Exception $e) {
                // Redis unavailable or INCR/DECR failed — fall through to MySQL
            }
        }

        return self::claimViaMysql($type, $freeLimit, $pdo);
    }

    /**
     * Re-seed the Redis counter from MySQL.
     *
     * Call this after a Redis flush or reconnect to ensure the counter starts
     * from the current authoritative MySQL value rather than 0.
     *
     * Uses SET (overwrites) rather than SETNX because this is an explicit
     * admin-triggered sync, not just a cold-start initialisation.
     *
     * @param string $type   'reporter' or 'agency'
     * @param PDO    $pdo
     * @param object $redis  Redis instance (real or stub)
     */
    public static function syncFromMysql(string $type, PDO $pdo, object $redis): void
    {
        $stmt = $pdo->prepare(
            'SELECT count FROM early_bird_counters WHERE type = ?'
        );
        $stmt->execute([$type]);
        $count = (int)($stmt->fetchColumn() ?: 0);

        $key = self::KEY_PREFIX . $type . self::KEY_SUFFIX;
        $redis->set($key, (string)$count);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Redis-based slot claiming.
     *
     * Algorithm:
     *   1. Cold-start seed — if the key is absent, load the current MySQL count
     *      and seed it with SETNX (only one process wins; others skip silently,
     *      then all INCR on the correct value).
     *   2. INCR — atomically advance the counter and capture the resulting slot.
     *   3. If slot > freeLimit — DECR immediately and return "exhausted".
     *   4. Mirror the new count to MySQL with a non-blocking, forward-only
     *      UPDATE so MySQL stays eventually consistent with Redis.
     *
     * @throws \Exception  Re-thrown if Redis throws (triggers MySQL fallback).
     */
    private static function claimViaRedis(
        string  $type,
        int     $freeLimit,
        PDO     $pdo,
        object  $redis
    ): array {
        $key = self::KEY_PREFIX . $type . self::KEY_SUFFIX;

        // ── Cold-start seed ──────────────────────────────────────────────────
        // EXISTS and SETNX are each atomic in Redis.  The small window between
        // them is safe: the worst case is two processes both see EXISTS=0, both
        // attempt SETNX — one succeeds and sets the seed, the other fails (key
        // already exists).  Both then INCR on the correct base value.
        if (!$redis->exists($key)) {
            $stmt = $pdo->prepare(
                'SELECT count FROM early_bird_counters WHERE type = ?'
            );
            $stmt->execute([$type]);
            $dbCount = (int)($stmt->fetchColumn() ?: 0);
            $redis->setnx($key, (string)$dbCount);
        }

        // ── Atomic slot claim ────────────────────────────────────────────────
        $slot = (int)$redis->incr($key);

        if ($slot > $freeLimit) {
            // Undo immediately so the key never drifts above freeLimit
            $redis->decr($key);
            return [
                'success' => false,
                'slot'    => null,
                'source'  => 'redis',
                'message' => 'Free early-bird slots exhausted',
            ];
        }

        // ── Best-effort MySQL mirror ─────────────────────────────────────────
        // Non-transactional and forward-only: the WHERE count < $slot guard
        // ensures we never move the MySQL counter backward, even if Redis and
        // MySQL temporarily diverge (e.g. after a Redis flush).
        $pdo->prepare(
            'UPDATE early_bird_counters SET count = ? WHERE type = ? AND count < ?'
        )->execute([$slot, $type, $slot]);

        return [
            'success' => true,
            'slot'    => $slot,
            'source'  => 'redis',
            'message' => '',
        ];
    }

    /**
     * MySQL-based slot claiming via SELECT … FOR UPDATE.
     *
     * InnoDB row-locks serialise the critical section, making this approach
     * correct under concurrent load.  It is slower than the Redis path because
     * each request acquires and releases a row lock, but it requires no extra
     * infrastructure.
     *
     * FOR UPDATE is omitted automatically for non-MySQL drivers (e.g. SQLite
     * used in unit tests) so the same code path runs in both environments.
     */
    private static function claimViaMysql(
        string $type,
        int    $freeLimit,
        PDO    $pdo
    ): array {
        $isMySQL  = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $forUpdate = $isMySQL ? 'FOR UPDATE' : '';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT count FROM early_bird_counters WHERE type = ? {$forUpdate}"
            );
            $stmt->execute([$type]);
            $current = $stmt->fetchColumn();

            if ($current === false || (int)$current >= $freeLimit) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'slot'    => null,
                    'source'  => 'mysql',
                    'message' => 'Free early-bird slots exhausted',
                ];
            }

            $newCount = (int)$current + 1;
            $pdo->prepare(
                'UPDATE early_bird_counters SET count = ? WHERE type = ?'
            )->execute([$newCount, $type]);

            $pdo->commit();

            return [
                'success' => true,
                'slot'    => $newCount,
                'source'  => 'mysql',
                'message' => '',
            ];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
