<?php
/**
 * helpers/badge_service.php
 *
 * BadgeService — checks reporter activity and awards badges + sends FCM.
 *
 * Badge catalogue:
 *   early_bird   — first 100 reporters (checked on blue tick claim)
 *   views_10k    — any article crosses 10K views
 *   viral_story  — any article crosses 50K views
 *   rising_star  — 500 followers
 *   top_reporter — weekly leaderboard #1
 *
 * Usage:
 *   BadgeService::checkAndAward($pdo, $reporterId);
 *   BadgeService::checkAndAward($pdo, $reporterId, $articleId); // on view update
 */

declare(strict_types=1);

class BadgeService
{
    // ─────────────────────────────────────────────────────────────────────────
    // Public: check all applicable badges for a reporter
    // ─────────────────────────────────────────────────────────────────────────
    public static function checkAndAward(PDO $pdo, int $reporterId, ?int $articleId = null): array
    {
        $awarded = [];

        $awarded = array_merge($awarded, self::checkViewsBadges($pdo, $reporterId, $articleId));
        $awarded = array_merge($awarded, self::checkFollowersBadge($pdo, $reporterId));
        $awarded = array_merge($awarded, self::checkTopReporterBadge($pdo, $reporterId));

        return $awarded;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: award early bird badge (called from blue_tick/purchase.php)
    // ─────────────────────────────────────────────────────────────────────────
    public static function checkEarlyBird(PDO $pdo, int $reporterId, int $slotNumber): void
    {
        if ($slotNumber <= 100) {
            self::awardBadge($pdo, $reporterId, 'early_bird', null);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: get all badges for a reporter
    // ─────────────────────────────────────────────────────────────────────────
    public static function getReporterBadges(PDO $pdo, int $reporterId): array
    {
        $stmt = $pdo->prepare("
            SELECT b.slug, b.name, b.description, b.icon_url,
                   rb.unlocked_at, rb.article_id
            FROM reporter_badges rb
            JOIN badges b ON b.id = rb.badge_id
            WHERE rb.reporter_id = :rid
            ORDER BY rb.unlocked_at ASC
        ");
        $stmt->execute([':rid' => $reporterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: get all badges (for UI display — locked/unlocked)
    // ─────────────────────────────────────────────────────────────────────────
    public static function getAllBadgesWithStatus(PDO $pdo, int $reporterId): array
    {
        $stmt = $pdo->prepare("
            SELECT b.*, rb.unlocked_at, rb.article_id,
                   IF(rb.id IS NOT NULL, 1, 0) AS is_unlocked
            FROM badges b
            LEFT JOIN reporter_badges rb ON rb.badge_id = b.id AND rb.reporter_id = :rid
            WHERE b.is_active = 1
            ORDER BY is_unlocked DESC, b.id ASC
        ");
        $stmt->execute([':rid' => $reporterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: check views badges
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkViewsBadges(PDO $pdo, int $reporterId, ?int $articleId): array
    {
        $awarded = [];

        $sql = "SELECT id, view_count FROM articles
                WHERE user_id=:rid AND status='approved'"
             . ($articleId ? " AND id=:aid" : "");

        $stmt = $pdo->prepare($sql);
        $params = [':rid' => $reporterId];
        if ($articleId) $params[':aid'] = $articleId;
        $stmt->execute($params);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($articles as $article) {
            $views = (int)$article['view_count'];
            $aid   = (int)$article['id'];

            // 10K milestone
            if ($views >= 10000) {
                $awarded[] = self::awardBadge($pdo, $reporterId, 'views_10k', $aid);
                self::recordMilestone($pdo, $reporterId, 'article_views', 10000, $aid);
            }
            // 50K milestone
            if ($views >= 50000) {
                $awarded[] = self::awardBadge($pdo, $reporterId, 'viral_story', $aid);
                self::recordMilestone($pdo, $reporterId, 'article_views', 50000, $aid);
            }
        }

        return array_filter($awarded);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: check followers badge
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkFollowersBadge(PDO $pdo, int $reporterId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(followers_count, 0) FROM user_follow_counts WHERE user_id=:rid"
        );
        $stmt->execute([':rid' => $reporterId]);
        $followers = (int)($stmt->fetchColumn() ?: 0);

        if ($followers >= 500) {
            $result = self::awardBadge($pdo, $reporterId, 'rising_star', null);
            self::recordMilestone($pdo, $reporterId, 'total_followers', 500, null);
            return array_filter([$result]);
        }

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: check leaderboard #1
    // ─────────────────────────────────────────────────────────────────────────
    private static function checkTopReporterBadge(PDO $pdo, int $reporterId): array
    {
        $stmt = $pdo->prepare(
            "SELECT weekly_rank FROM reporter_scores WHERE user_id=:rid"
        );
        $stmt->execute([':rid' => $reporterId]);
        $rank = (int)($stmt->fetchColumn() ?: PHP_INT_MAX);

        if ($rank === 1) {
            $result = self::awardBadge($pdo, $reporterId, 'top_reporter', null);
            return array_filter([$result]);
        }

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: award a badge (idempotent)
    // ─────────────────────────────────────────────────────────────────────────
    private static function awardBadge(PDO $pdo, int $reporterId, string $slug, ?int $articleId): ?string
    {
        // Get badge id
        $stmt = $pdo->prepare("SELECT id, name FROM badges WHERE slug=:slug AND is_active=1");
        $stmt->execute([':slug' => $slug]);
        $badge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$badge) return null;

        // Check if already awarded
        $check = $pdo->prepare(
            "SELECT id FROM reporter_badges WHERE reporter_id=:rid AND badge_id=:bid"
        );
        $check->execute([':rid' => $reporterId, ':bid' => $badge['id']]);
        if ($check->fetch()) return null; // already has it

        // Award
        $pdo->prepare(
            "INSERT IGNORE INTO reporter_badges (reporter_id, badge_id, article_id) VALUES (:rid, :bid, :aid)"
        )->execute([':rid' => $reporterId, ':bid' => $badge['id'], ':aid' => $articleId]);

        // Send FCM
        try {
            if (function_exists('sendFCMToUser')) {
                sendFCMToUser($pdo, $reporterId, '🏆 New Badge Unlocked!',
                    "You earned the \"{$badge['name']}\" badge. Keep it up!");
            }
        } catch (Throwable $e) {
            error_log("[BadgeService] FCM error: " . $e->getMessage());
        }

        return $slug;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: record a milestone (idempotent)
    // ─────────────────────────────────────────────────────────────────────────
    private static function recordMilestone(PDO $pdo, int $reporterId, string $type, int $threshold, ?int $articleId): void
    {
        $pdo->prepare(
            "INSERT IGNORE INTO milestones (reporter_id, milestone_type, threshold, article_id)
             VALUES (:rid, :type, :thr, :aid)"
        )->execute([':rid' => $reporterId, ':type' => $type, ':thr' => $threshold, ':aid' => $articleId]);
    }
}
