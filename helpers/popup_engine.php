<?php
/**
 * SmartPopupEngine — User Preference Popup Decision Engine
 *
 * Decides whether to show a preference popup (onboarding / daily-mood /
 * category-change) to a given user, based on a weighted signal score.
 *
 * Dependencies:
 *   - helpers/redis.php   (getRedis() → Redis instance)
 *   - config/db.php       (getPDO()   → PDO instance)
 *
 * Usage:
 *   $result = SmartPopupEngine::shouldShowPopup($uid, $sessionData);
 *   // $result = ['show' => true, 'type' => 'daily_mood', 'score' => 6, ...]
 */

declare(strict_types=1);

class SmartPopupEngine
{
    // Redis TTL for cached user prefs (seconds)
    private const PREFS_CACHE_TTL = 300; // 5 minutes

    // Score threshold to show the popup
    private const SHOW_THRESHOLD = 5;

    // Skip-streak limit before auto-snooze
    private const MAX_SKIP_STREAK = 3;

    // Auto-snooze duration when streak limit hit (seconds = 3 days)
    private const SNOOZE_DURATION_SECONDS = 259200;

    // ----------------------------------------------------------------
    // MAIN PUBLIC METHOD
    // ----------------------------------------------------------------

    /**
     * Decide whether to show a preference popup for this user.
     *
     * @param  string $uid         Firebase/app user ID
     * @param  array  $sessionData {
     *   duration_minutes: int   — length of the current / last session
     * }
     * @return array {
     *   show:      bool,
     *   type:      string  ('onboarding'|'daily_mood'|'category_change'),
     *   score:     int,
     *   reasons:   string[],
     *   time_slot: string,
     *   context:   string,
     *   reason:    string   (set only when show=false)
     * }
     */
    public static function shouldShowPopup(string $uid, array $sessionData): array
    {
        $hour      = (int) date('G');
        $timeSlot  = self::getTimeSlot($hour);

        // ── Step 1: Onboarding check ─────────────────────────────────
        $prefs = self::getUserPrefs($uid);

        if ($prefs === null || (int) ($prefs['onboarding_completed'] ?? 0) === 0) {
            return [
                'show'      => true,
                'type'      => 'onboarding',
                'score'     => 10,
                'reasons'   => ['first_login'],
                'time_slot' => $timeSlot,
                'context'   => self::getContext($timeSlot, false),
            ];
        }

        // ── Step 2: Snooze check ─────────────────────────────────────
        if (!empty($prefs['popup_snoozed_until'])) {
            $snoozedUntil = strtotime($prefs['popup_snoozed_until']);
            if ($snoozedUntil !== false && $snoozedUntil > time()) {
                return [
                    'show'      => false,
                    'type'      => 'daily_mood',
                    'score'     => 0,
                    'reasons'   => [],
                    'time_slot' => $timeSlot,
                    'context'   => self::getContext($timeSlot, false),
                    'reason'    => 'snoozed',
                ];
            }
        }

        // ── Step 3: Signal scoring ───────────────────────────────────
        $score   = 0;
        $reasons = [];

        // Signal 1 — Last mood age
        if (!empty($prefs['last_mood_set_at'])) {
            $hoursSinceLastMood = (time() - strtotime($prefs['last_mood_set_at'])) / 3600;

            if ($hoursSinceLastMood > 18) {
                $score   += 3;
                $reasons[] = 'preference_stale_18h';
            } elseif ($hoursSinceLastMood > 12) {
                $score   += 1;
                $reasons[] = 'preference_stale_12h';
            } elseif ($hoursSinceLastMood < 6) {
                $score -= 2;
            }
        } else {
            // Never set — treat as very stale
            $score   += 3;
            $reasons[] = 'preference_stale_18h';
        }

        // Signal 2 — New content in user's interests (last 3 hours)
        $lastMoodCategories = [];
        if (!empty($prefs['last_mood_categories'])) {
            $decoded = json_decode($prefs['last_mood_categories'], true);
            if (is_array($decoded)) {
                $lastMoodCategories = $decoded;
            }
        }

        if (!empty($lastMoodCategories)) {
            $recentCount = self::countRecentArticles($lastMoodCategories, 3);
            if ($recentCount >= 5) {
                $score   += 2;
                $reasons[] = 'new_content_available';
            }
        }

        // Signal 3 — Last session engagement
        $durationMinutes = isset($sessionData['duration_minutes'])
            ? (int) $sessionData['duration_minutes']
            : 999;

        if ($durationMinutes < 2) {
            $score   += 2;
            $reasons[] = 'low_last_engagement';
        }

        // Signal 4 — Time of day
        if ($hour >= 6 && $hour <= 9) {
            $score   += 1;
            $reasons[] = 'morning_peak';
        } elseif ($hour >= 19 && $hour <= 22) {
            $score   += 1;
            $reasons[] = 'evening_peak';
        } elseif ($hour >= 23 || $hour < 5) {
            $score -= 3;
        }

        // Signal 5 — Skip streak
        $skipStreak = (int) ($prefs['popup_skip_streak'] ?? 0);

        if ($skipStreak >= self::MAX_SKIP_STREAK) {
            // Auto-snooze and reset
            self::snoozeUser($uid);

            return [
                'show'      => false,
                'type'      => 'daily_mood',
                'score'     => $score,
                'reasons'   => $reasons,
                'time_slot' => $timeSlot,
                'context'   => self::getContext($timeSlot, false),
                'reason'    => 'skip_streak_snoozed',
            ];
        }

        if ($skipStreak <= 2) {
            $score += 1;
        } else {
            $score -= 2;
        }

        // Breaking news check
        $hasBreaking = false;
        $preferredState = isset($prefs['preferred_state_id'])
            ? (int) $prefs['preferred_state_id']
            : null;

        if ($preferredState !== null) {
            $hasBreaking = self::hasBreakingNewsInArea($preferredState, 2);
            if ($hasBreaking) {
                $score   += 3;
                $reasons[] = 'breaking_news_area';
            }
        }

        // ── Step 4: Threshold decision ───────────────────────────────
        $show    = $score >= self::SHOW_THRESHOLD;
        $context = self::getContext($timeSlot, $hasBreaking);

        return [
            'show'      => $show,
            'type'      => 'daily_mood',
            'score'     => $score,
            'reasons'   => $reasons,
            'time_slot' => $timeSlot,
            'context'   => $context,
        ];
    }

    // ----------------------------------------------------------------
    // HELPER METHODS
    // ----------------------------------------------------------------

    /**
     * Map hour (0–23) to a named time slot.
     */
    public static function getTimeSlot(int $hour): string
    {
        if ($hour >= 5 && $hour <= 6) {
            return 'early_morning';
        }
        if ($hour >= 7 && $hour <= 11) {
            return 'morning';
        }
        if ($hour >= 12 && $hour <= 16) {
            return 'afternoon';
        }
        if ($hour >= 17 && $hour <= 20) {
            return 'evening';
        }
        if ($hour >= 21 && $hour <= 22) {
            return 'night';
        }
        return 'late_night';
    }

    /**
     * Derive a human-readable context string from slot + breaking flag.
     */
    public static function getContext(string $timeSlot, bool $hasBreaking): string
    {
        if ($hasBreaking) {
            return 'breaking_news';
        }

        return match ($timeSlot) {
            'early_morning' => 'morning_brief',
            'morning'       => 'morning',
            'afternoon'     => 'afternoon_update',
            'evening'       => 'evening',
            'night'         => 'night_digest',
            default         => 'general',
        };
    }

    /**
     * Fetch user preferences — Redis first (5-min cache), fallback to DB.
     *
     * @return array|null  Row from user_preferences, or null if not found.
     */
    public static function getUserPrefs(string $uid): ?array
    {
        $cacheKey = "user_prefs:{$uid}";

        try {
            $redis  = self::getRedis();
            $cached = $redis->get($cacheKey);

            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable — fall through to DB
        }

        // DB fetch
        try {
            $pdo  = self::getPDO();
            $stmt = $pdo->prepare(
                'SELECT * FROM `user_preferences` WHERE `user_id` = ? LIMIT 1'
            );
            $stmt->execute([$uid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            // Cache the result
            try {
                $redis = self::getRedis();
                $redis->setex($cacheKey, self::PREFS_CACHE_TTL, json_encode($row));
            } catch (\Throwable $e) {
                // Cache write failure is non-fatal
            }

            return $row;

        } catch (\Throwable $e) {
            return null;
        }
    }

    // ----------------------------------------------------------------
    // PRIVATE HELPERS
    // ----------------------------------------------------------------

    /**
     * Count articles published in the last $hours hours for the given
     * category slugs.
     *
     * @param  string[] $categorySlugs
     * @param  int      $hours
     * @return int
     */
    private static function countRecentArticles(array $categorySlugs, int $hours): int
    {
        if (empty($categorySlugs)) {
            return 0;
        }

        try {
            $pdo         = self::getPDO();
            $placeholders = implode(',', array_fill(0, count($categorySlugs), '?'));
            $params      = $categorySlugs;
            $params[]    = date('Y-m-d H:i:s', time() - $hours * 3600);

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt
                 FROM `news` n
                 INNER JOIN `categories` c ON c.id = n.category_id
                 WHERE c.slug IN ({$placeholders})
                   AND n.created_at >= ?
                   AND n.status = 'published'"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int) ($row['cnt'] ?? 0);

        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Check whether any breaking news exists for a state in the last
     * $hours hours.
     */
    private static function hasBreakingNewsInArea(int $stateId, int $hours): bool
    {
        try {
            $pdo  = self::getPDO();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt
                 FROM `news`
                 WHERE `is_breaking` = 1
                   AND `state_id`    = ?
                   AND `created_at` >= ?
                   AND `status`     = 'published'"
            );
            $stmt->execute([
                $stateId,
                date('Y-m-d H:i:s', time() - $hours * 3600),
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int) ($row['cnt'] ?? 0) > 0;

        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set popup_snoozed_until = NOW + 3 days and reset popup_skip_streak.
     * Also invalidates the Redis cache for this user.
     */
    private static function snoozeUser(string $uid): void
    {
        try {
            $pdo  = self::getPDO();
            $stmt = $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `popup_snoozed_until` = DATE_ADD(NOW(), INTERVAL 3 DAY),
                     `popup_skip_streak`   = 0,
                     `updated_at`          = NOW()
                 WHERE `user_id` = ?"
            );
            $stmt->execute([$uid]);
        } catch (\Throwable $e) {
            // Non-fatal — best effort
        }

        // Bust cache so next call reads fresh state
        try {
            $redis = self::getRedis();
            $redis->del("user_prefs:{$uid}");
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Obtain a Redis connection via the project's helper.
     *
     * @return \Redis
     */
    private static function getRedis(): \Redis
    {
        if (!function_exists('getRedis')) {
            require_once __DIR__ . '/redis.php';
        }
        return getRedis();
    }

    /**
     * Obtain a PDO connection via the project's config.
     *
     * @return \PDO
     */
    private static function getPDO(): \PDO
    {
        if (!function_exists('getPDO')) {
            require_once __DIR__ . '/../config/db.php';
        }
        return getPDO();
    }
}
