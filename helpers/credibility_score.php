<?php
/**
 * helpers/credibility_score.php
 *
 * CredibilityScoreService — calculates, persists, and ranks reporter scores.
 *
 * Score formula:
 *   (articles_approved * 10)
 *   + (total_views / 1000)
 *   + (followers_count * 5)
 *   + (avg_article_rating * 20)
 *   + (blue_tick_bonus: 50)    ← only when reporter has an active blue tick
 *   - (fake_news_flags * 30)
 *
 * Usage:
 *   // Rebuild ALL scores (daily cron):
 *   CredibilityScoreService::recalculateAll($pdo);
 *
 *   // Trigger single-reporter update on article event:
 *   CredibilityScoreService::recalculateOne($pdo, $reporterUserId);
 *
 *   // Leaderboard API:
 *   $top = CredibilityScoreService::getLeaderboard($pdo, 'weekly', 'all', 10, 0);
 *   $myRank = CredibilityScoreService::getReporterRank($pdo, $userId, 'weekly');
 */

declare(strict_types=1);

class CredibilityScoreService
{
    // ─────────────────────────────────────────────────────────────────────────
    // Score constants
    // ─────────────────────────────────────────────────────────────────────────
    private const SCORE_PER_ARTICLE      = 10;
    private const SCORE_PER_1K_VIEWS     = 1;      // total_views / 1000
    private const SCORE_PER_FOLLOWER     = 5;
    private const SCORE_PER_RATING_POINT = 20;
    private const BLUE_TICK_BONUS        = 50;
    private const PENALTY_PER_FLAG       = 30;

    // ─────────────────────────────────────────────────────────────────────────
    // Public: recalculate ALL reporters (run daily via cron)
    // ─────────────────────────────────────────────────────────────────────────
    public static function recalculateAll(PDO $pdo): array
    {
        $stats = ['processed' => 0, 'errors' => 0, 'skipped' => 0];

        // Fetch all active reporters
        $stmt = $pdo->query(
            "SELECT id FROM users WHERE role = 'reporter' AND status = 'active'"
        );
        $reporters = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($reporters as $userId) {
            try {
                self::recalculateOne($pdo, (int)$userId);
                $stats['processed']++;
            } catch (Throwable $e) {
                error_log("[CredibilityScore] Error for user {$userId}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        // Rebuild all-time and weekly ranks after score update
        self::rebuildRanks($pdo);

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: recalculate ONE reporter (call on article approve/reject/flag)
    // ─────────────────────────────────────────────────────────────────────────
    public static function recalculateOne(PDO $pdo, int $userId): float
    {
        $raw = self::fetchRawMetrics($pdo, $userId);
        $score = self::computeScore($raw);

        // Weekly score = sum of article-level events this ISO week
        $weeklyScore = self::computeWeeklyScore($pdo, $userId);

        $pdo->prepare(
            "INSERT INTO reporter_scores
                 (user_id, articles_approved, total_views, followers_count,
                  avg_article_rating, fake_news_flags, blue_tick_bonus,
                  credibility_score, weekly_score, last_calculated_at)
             VALUES
                 (:uid, :aa, :tv, :fc, :ar, :fn, :bt, :score, :wscore, NOW())
             ON DUPLICATE KEY UPDATE
                 articles_approved  = VALUES(articles_approved),
                 total_views        = VALUES(total_views),
                 followers_count    = VALUES(followers_count),
                 avg_article_rating = VALUES(avg_article_rating),
                 fake_news_flags    = VALUES(fake_news_flags),
                 blue_tick_bonus    = VALUES(blue_tick_bonus),
                 credibility_score  = VALUES(credibility_score),
                 weekly_score       = VALUES(weekly_score),
                 last_calculated_at = NOW()"
        )->execute([
            ':uid'    => $userId,
            ':aa'     => $raw['articles_approved'],
            ':tv'     => $raw['total_views'],
            ':fc'     => $raw['followers_count'],
            ':ar'     => $raw['avg_article_rating'],
            ':fn'     => $raw['fake_news_flags'],
            ':bt'     => $raw['blue_tick_bonus'],
            ':score'  => $score,
            ':wscore' => $weeklyScore,
        ]);

        // Archive daily snapshot
        $pdo->prepare(
            "INSERT INTO reporter_score_history (user_id, score_date, credibility_score)
             VALUES (:uid, CURDATE(), :score)
             ON DUPLICATE KEY UPDATE credibility_score = VALUES(credibility_score)"
        )->execute([':uid' => $userId, ':score' => $score]);

        return $score;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: leaderboard query
    // type = 'weekly' | 'all_time'
    // category = 'all' | specific category slug
    // ─────────────────────────────────────────────────────────────────────────
    public static function getLeaderboard(
        PDO    $pdo,
        string $type     = 'weekly',
        string $category = 'all',
        int    $limit    = 10,
        int    $offset   = 0
    ): array {
        $scoreCol = ($type === 'weekly') ? 'rs.weekly_score' : 'rs.credibility_score';
        $rankCol  = ($type === 'weekly') ? 'rs.weekly_rank'  : 'rs.all_time_rank';

        $categoryJoin  = '';
        $categoryWhere = '';
        $params        = [':limit' => $limit, ':offset' => $offset];

        if ($category !== 'all') {
            $categoryJoin  = "JOIN articles a ON a.user_id = u.id AND a.category_slug = :cat AND a.status = 'approved'";
            $categoryWhere = 'AND :cat IS NOT NULL';
            $params[':cat'] = $category;
        }

        $sql = "
            SELECT
                u.id              AS user_id,
                u.name            AS reporter_name,
                u.profile_photo   AS avatar_url,
                u.city            AS location,
                rs.credibility_score,
                rs.weekly_score,
                {$rankCol}        AS `rank`,
                rs.articles_approved,
                rs.followers_count,
                rs.blue_tick_bonus,
                rs.last_calculated_at
            FROM reporter_scores rs
            JOIN users u ON u.id = rs.user_id
            {$categoryJoin}
            WHERE u.role = 'reporter'
              AND u.status = 'active'
            ORDER BY {$scoreCol} DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: reporter's own rank
    // ─────────────────────────────────────────────────────────────────────────
    public static function getReporterRank(PDO $pdo, int $userId, string $type = 'weekly'): array
    {
        $scoreCol = ($type === 'weekly') ? 'weekly_score' : 'credibility_score';
        $rankCol  = ($type === 'weekly') ? 'weekly_rank'  : 'all_time_rank';

        $stmt = $pdo->prepare(
            "SELECT {$rankCol} AS `rank`, {$scoreCol} AS score,
                    credibility_score, weekly_score,
                    articles_approved, followers_count, blue_tick_bonus
             FROM reporter_scores WHERE user_id = :uid"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: fetch aggregated metrics for a single reporter
    // ─────────────────────────────────────────────────────────────────────────
    private static function fetchRawMetrics(PDO $pdo, int $userId): array
    {
        // Articles approved + views + rating
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                        AS articles_approved,
                COALESCE(SUM(view_count), 0)    AS total_views,
                COALESCE(AVG(rating), 0)        AS avg_article_rating
            FROM articles
            WHERE user_id = :uid AND status = 'approved'
        ");
        $stmt->execute([':uid' => $userId]);
        $art = $stmt->fetch(PDO::FETCH_ASSOC);

        // Followers
        $stmt = $pdo->prepare("
            SELECT COALESCE(followers_count, 0)
            FROM user_follow_counts WHERE user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $followers = (int)($stmt->fetchColumn() ?: 0);

        // Fake news flags (active strikes for fake_news reason)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reporter_strikes
            WHERE reporter_id = :uid
              AND reason = 'fake_news'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        ");
        $stmt->execute([':uid' => $userId]);
        $fakeFlags = (int)$stmt->fetchColumn();

        // Blue tick active?
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM blue_tick_assignments
            WHERE user_id = :uid AND status = 'active'
        ");
        $stmt->execute([':uid' => $userId]);
        $hasBlueTick = (int)$stmt->fetchColumn() > 0 ? 1 : 0;

        return [
            'articles_approved'  => (int)$art['articles_approved'],
            'total_views'        => (int)$art['total_views'],
            'avg_article_rating' => (float)$art['avg_article_rating'],
            'followers_count'    => $followers,
            'fake_news_flags'    => $fakeFlags,
            'blue_tick_bonus'    => $hasBlueTick,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: compute score from raw metrics
    // ─────────────────────────────────────────────────────────────────────────
    private static function computeScore(array $raw): float
    {
        $score = 0.0;
        $score += $raw['articles_approved']  * self::SCORE_PER_ARTICLE;
        $score += ($raw['total_views'] / 1000) * self::SCORE_PER_1K_VIEWS;
        $score += $raw['followers_count']    * self::SCORE_PER_FOLLOWER;
        $score += $raw['avg_article_rating'] * self::SCORE_PER_RATING_POINT;
        $score += $raw['blue_tick_bonus']    * self::BLUE_TICK_BONUS;
        $score -= $raw['fake_news_flags']    * self::PENALTY_PER_FLAG;

        return max(0.0, round($score, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: compute weekly score (articles approved this ISO week)
    // ─────────────────────────────────────────────────────────────────────────
    private static function computeWeeklyScore(PDO $pdo, int $userId): float
    {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                     AS approved,
                COALESCE(SUM(view_count), 0) AS views
            FROM articles
            WHERE user_id = :uid
              AND status = 'approved'
              AND YEARWEEK(approved_at, 3) = YEARWEEK(NOW(), 3)
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return max(0.0, round(
            ($row['approved'] * self::SCORE_PER_ARTICLE)
            + (($row['views'] / 1000) * self::SCORE_PER_1K_VIEWS),
            2
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: rebuild all-time and weekly rank columns
    // ─────────────────────────────────────────────────────────────────────────
    private static function rebuildRanks(PDO $pdo): void
    {
        // All-time ranks
        $pdo->exec("
            SET @r = 0;
            UPDATE reporter_scores rs
            JOIN (
                SELECT user_id, (@r := @r + 1) AS rk
                FROM reporter_scores
                ORDER BY credibility_score DESC
            ) ranked ON ranked.user_id = rs.user_id
            SET rs.all_time_rank = ranked.rk
        ");

        // Weekly ranks
        $pdo->exec("
            SET @w = 0;
            UPDATE reporter_scores rs
            JOIN (
                SELECT user_id, (@w := @w + 1) AS rk
                FROM reporter_scores
                ORDER BY weekly_score DESC
            ) ranked ON ranked.user_id = rs.user_id
            SET rs.weekly_rank = ranked.rk
        ");
    }
}
