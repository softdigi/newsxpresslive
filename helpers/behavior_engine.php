<?php
/**
 * BehaviorLearningEngine — User Category Behavior Tracker & Weight Calculator
 *
 * Tracks real-time reading/engagement events into user_category_behavior and
 * periodically recalculates per-category behavior weights used by the feed
 * algorithm and popup engine.
 *
 * Dependencies:
 *   - helpers/redis.php  (getRedis() → Redis instance)
 *   - config/db.php      (getPDO()   → PDO instance)
 *
 * Usage:
 *   BehaviorLearningEngine::trackRead($uid, $categoryId, 90, true);
 *   BehaviorLearningEngine::recalculateWeights($uid);
 */

declare(strict_types=1);

class BehaviorLearningEngine
{
    // Redis queue key for async weight recalculation
    private const QUEUE_KEY = 'behavior_update_queue';

    // ----------------------------------------------------------------
    // METHOD 1: trackRead
    // ----------------------------------------------------------------

    /**
     * Record that a user read (or partially read) an article in a category.
     *
     * Increments articles_read, optionally articles_completed,
     * total_read_seconds, and the hourly_pattern JSON counter.
     * Also pushes an async event to Redis for background processing.
     *
     * @param string $uid        User ID
     * @param int    $categoryId Category ID
     * @param int    $readSeconds Seconds spent reading
     * @param bool   $completed  Whether the article was fully read
     */
    public static function trackRead(
        string $uid,
        int    $categoryId,
        int    $readSeconds,
        bool   $completed
    ): void {
        $hour = (int) date('G'); // 0–23

        try {
            $pdo = self::getPDO();

            // Upsert the behavior row, updating hourly_pattern for this hour
            $stmt = $pdo->prepare(
                "INSERT INTO `user_category_behavior`
                    (`user_id`, `category_id`,
                     `articles_read`, `articles_completed`,
                     `total_read_seconds`, `hourly_pattern`)
                 VALUES
                    (:uid, :cid, 1, :comp, :secs,
                     JSON_OBJECT(:hkey, 1))
                 ON DUPLICATE KEY UPDATE
                    `articles_read`      = `articles_read` + 1,
                    `articles_completed` = `articles_completed` + :comp2,
                    `total_read_seconds` = `total_read_seconds` + :secs2,
                    `hourly_pattern`     = JSON_SET(
                        COALESCE(`hourly_pattern`, '{}'),
                        CONCAT('$.\"', :hkey2, '\"'),
                        COALESCE(
                            JSON_EXTRACT(
                                COALESCE(`hourly_pattern`, '{}'),
                                CONCAT('$.\"', :hkey3, '\"')
                            ),
                            0
                        ) + 1
                    )"
            );

            $hkey = (string) $hour;

            $stmt->execute([
                ':uid'   => $uid,
                ':cid'   => $categoryId,
                ':comp'  => (int) $completed,
                ':secs'  => $readSeconds,
                ':comp2' => (int) $completed,
                ':secs2' => $readSeconds,
                ':hkey'  => $hkey,
                ':hkey2' => $hkey,
                ':hkey3' => $hkey,
            ]);
        } catch (\Throwable $e) {
            error_log('BehaviorEngine::trackRead DB error: ' . $e->getMessage());
        }

        // Push async event to Redis queue
        $event = json_encode([
            'type'        => 'read',
            'uid'         => $uid,
            'category_id' => $categoryId,
            'read_seconds'=> $readSeconds,
            'completed'   => $completed,
            'ts'          => time(),
        ]);

        try {
            self::getRedis()->rpush(self::QUEUE_KEY, $event);
        } catch (\Throwable $e) {
            // Non-fatal — queue write failure
        }
    }

    // ----------------------------------------------------------------
    // METHOD 2: trackEngagement
    // ----------------------------------------------------------------

    /**
     * Record a social engagement action (share, bookmark, comment, like).
     *
     * @param string $uid        User ID
     * @param int    $categoryId Category ID
     * @param string $action     One of: 'share'|'bookmark'|'comment'|'like'
     */
    public static function trackEngagement(
        string $uid,
        int    $categoryId,
        string $action
    ): void {
        $columnMap = [
            'share'    => 'shares_count',
            'bookmark' => 'bookmarks_count',
            'comment'  => 'comments_count',
            'like'     => 'likes_count',
        ];

        if (!isset($columnMap[$action])) {
            return; // Unknown action — ignore
        }

        $column = $columnMap[$action];

        try {
            $pdo  = self::getPDO();
            $stmt = $pdo->prepare(
                "INSERT INTO `user_category_behavior`
                    (`user_id`, `category_id`, `{$column}`)
                 VALUES (:uid, :cid, 1)
                 ON DUPLICATE KEY UPDATE
                    `{$column}` = `{$column}` + 1"
            );
            $stmt->execute([':uid' => $uid, ':cid' => $categoryId]);
        } catch (\Throwable $e) {
            error_log('BehaviorEngine::trackEngagement DB error: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // METHOD 3: trackMoodSelection
    // ----------------------------------------------------------------

    /**
     * Record that a user manually selected certain categories in the mood popup.
     * Increments mood_selected_count for each category.
     *
     * @param string $uid         User ID
     * @param int[]  $categoryIds Array of category IDs selected
     */
    public static function trackMoodSelection(string $uid, array $categoryIds): void
    {
        if (empty($categoryIds)) {
            return;
        }

        try {
            $pdo  = self::getPDO();
            $stmt = $pdo->prepare(
                "INSERT INTO `user_category_behavior`
                    (`user_id`, `category_id`, `mood_selected_count`)
                 VALUES (:uid, :cid, 1)
                 ON DUPLICATE KEY UPDATE
                    `mood_selected_count` = `mood_selected_count` + 1"
            );

            foreach ($categoryIds as $categoryId) {
                $stmt->execute([':uid' => $uid, ':cid' => (int) $categoryId]);
            }
        } catch (\Throwable $e) {
            error_log('BehaviorEngine::trackMoodSelection DB error: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // METHOD 4: recalculateWeights
    // ----------------------------------------------------------------

    /**
     * Recalculate behavior_weight for every category of a given user
     * and update both user_category_behavior and user_preferences.
     *
     * Called by the daily 3 AM cron job.
     *
     * Weight formula:
     *   completionRate  = articles_completed / articles_read  (0 if 0 reads)
     *   readTimeScore   = min(avgReadSeconds / 120, 1.0)
     *   engagementScore = min((shares*4 + bookmarks*3 + comments*2 + likes) / 20, 1.0)
     *   moodScore       = min(mood_selected_count / 10, 1.0)
     *
     *   weight = completionRate * 0.35
     *          + readTimeScore  * 0.30
     *          + engagementScore* 0.25
     *          + moodScore      * 0.10
     *
     * @param string $uid User ID
     */
    public static function recalculateWeights(string $uid): void
    {
        try {
            $pdo = self::getPDO();

            // Fetch all behavior rows for this user, joining on categories for slug
            $stmt = $pdo->prepare(
                "SELECT ucb.*, c.slug
                 FROM `user_category_behavior` ucb
                 INNER JOIN `categories` c ON c.id = ucb.category_id
                 WHERE ucb.user_id = ?"
            );
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return;
            }

            $slugWeightMap  = [];
            $updateStmt     = $pdo->prepare(
                "UPDATE `user_category_behavior`
                 SET `behavior_weight`  = ?,
                     `weight_updated_at`= NOW()
                 WHERE `user_id` = ? AND `category_id` = ?"
            );

            foreach ($rows as $row) {
                $articlesRead = (int) $row['articles_read'];

                // Completion rate
                $completionRate = $articlesRead > 0
                    ? (float) $row['articles_completed'] / $articlesRead
                    : 0.0;

                // Read time score (120 s = 2 min → max)
                $avgReadSeconds = $articlesRead > 0
                    ? (float) $row['total_read_seconds'] / $articlesRead
                    : 0.0;
                $readTimeScore = min($avgReadSeconds / 120.0, 1.0);

                // Engagement score
                $totalEngagement = ((int) $row['shares_count']    * 4)
                                 + ((int) $row['bookmarks_count']  * 3)
                                 + ((int) $row['comments_count']   * 2)
                                 + ((int) $row['likes_count']      * 1);
                $engagementScore = min($totalEngagement / 20.0, 1.0);

                // Mood score
                $moodScore = min((int) $row['mood_selected_count'] / 10.0, 1.0);

                // Final weight
                $weight = ($completionRate  * 0.35)
                        + ($readTimeScore   * 0.30)
                        + ($engagementScore * 0.25)
                        + ($moodScore       * 0.10);

                $weight = round($weight, 3);

                // Update individual behavior row
                $updateStmt->execute([$weight, $uid, (int) $row['category_id']]);

                // Build slug→weight map for user_preferences
                if (!empty($row['slug'])) {
                    $slugWeightMap[$row['slug']] = $weight;
                }
            }

            // Update user_preferences.behavior_weights JSON
            $prefStmt = $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `behavior_weights`   = ?,
                     `behavior_updated_at`= NOW(),
                     `updated_at`         = NOW()
                 WHERE `user_id` = ?"
            );
            $prefStmt->execute([json_encode($slugWeightMap), $uid]);

        } catch (\Throwable $e) {
            error_log('BehaviorEngine::recalculateWeights DB error: ' . $e->getMessage());
            return;
        }

        // Bust the Redis prefs cache so the next popup check reads fresh weights
        try {
            self::getRedis()->del("user_prefs:{$uid}");
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    // ----------------------------------------------------------------
    // PRIVATE HELPERS
    // ----------------------------------------------------------------

    private static function getRedis(): \Redis
    {
        if (!function_exists('getRedis')) {
            require_once __DIR__ . '/redis.php';
        }
        return getRedis();
    }

    private static function getPDO(): \PDO
    {
        if (!function_exists('getPDO')) {
            require_once __DIR__ . '/../config/db.php';
        }
        return getPDO();
    }
}
