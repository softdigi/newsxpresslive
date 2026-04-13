<?php
/**
 * cron/calculate_reporter_scores.php
 * CLI-only daily cron — recalculate reporter scores and award growth badges.
 *
 * Score formula:
 *   total_score = (approved_articles * 10) + (total_views / 100)
 *               + (followers * 5) + (blue_tick ? 100 : 0)
 *               + (avg_engagement * 20) - (fake_news_strikes * 50)
 *
 * Run at midnight: 0 0 * * * php /path/to/cron/calculate_reporter_scores.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

declare(strict_types=1);

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/notification.php';

$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " calculate_reporter_scores starting...\n";

// ── Fetch all active reporters ────────────────────────────────────────────────
$reporters = $pdo->query(
    "SELECT u.id AS user_id, u.firebase_uid,
            COALESCE(u.display_name, u.name, u.firebase_uid) AS display_name,
            COALESCE(rp.is_verified, 0) AS blue_tick,
            u.fcm_token
     FROM users u
     LEFT JOIN reporter_profiles rp ON rp.user_id = u.id
     WHERE u.role = 'reporter' AND u.status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($reporters)) {
    echo "No active reporters found.\n";
    exit(0);
}

// ── Load badge catalogue ──────────────────────────────────────────────────────
$badges_catalogue = $pdo->query(
    "SELECT id, slug, trigger_type, trigger_value FROM badges"
)->fetchAll(PDO::FETCH_ASSOC);

// Index by slug for quick lookup
$badges_by_slug = [];
foreach ($badges_catalogue as $b) {
    $badges_by_slug[$b['slug']] = $b;
}

$processed = 0;
$errors    = 0;

foreach ($reporters as $reporter) {
    $uid      = (int)$reporter['user_id'];
    $firebase = $reporter['firebase_uid'];

    try {
        // ── Gather metrics ────────────────────────────────────────────────
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS approved_articles
             FROM news WHERE reporter_id = ? AND status = 'approved'"
        );
        $stmt->execute([$uid]);
        $approved_articles = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(views), 0) AS total_views FROM news
             WHERE reporter_id = ? AND status = 'approved'"
        );
        $stmt->execute([$uid]);
        $total_views = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM user_follows WHERE following_uid = ?"
        );
        $stmt->execute([$firebase]);
        $followers = (int)$stmt->fetchColumn();

        // Weekly approved articles (for weekly_score)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM news
             WHERE reporter_id = ? AND status = 'approved'
               AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)"
        );
        $stmt->execute([$uid]);
        $weekly_articles = (int)$stmt->fetchColumn();

        // Weekly views
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(views),0) FROM news
             WHERE reporter_id = ? AND status = 'approved'
               AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)"
        );
        $stmt->execute([$uid]);
        $weekly_views = (int)$stmt->fetchColumn();

        // Fake news strikes (accumulated in reporter_scores)
        $rs_stmt = $pdo->prepare(
            "SELECT fake_news_flags AS fake_news_strikes FROM reporter_scores WHERE user_id = ?"
        );
        $rs_stmt->execute([$uid]);
        $fake_news_strikes = (int)($rs_stmt->fetchColumn() ?: 0);

        $blue_tick      = (bool)$reporter['blue_tick'];
        $avg_engagement = $total_views > 0 && $approved_articles > 0
                          ? min(10.0, (float)$total_views / $approved_articles / 1000)
                          : 0.0;

        // ── Calculate scores ──────────────────────────────────────────────
        $total_score = ($approved_articles * 10)
                     + ($total_views / 100)
                     + ($followers * 5)
                     + ($blue_tick ? 100 : 0)
                     + ($avg_engagement * 20)
                     - ($fake_news_strikes * 50);
        $total_score = max(0.0, $total_score);

        $weekly_score = ($weekly_articles * 10)
                      + ($weekly_views / 100);
        $weekly_score = max(0.0, $weekly_score);

        // ── Upsert reporter_scores ────────────────────────────────────────
        $pdo->prepare(
            "INSERT INTO reporter_scores
               (user_id, articles_approved, total_views, followers_count,
                credibility_score, weekly_score, blue_tick_bonus)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               articles_approved = VALUES(articles_approved),
               total_views       = VALUES(total_views),
               followers_count   = VALUES(followers_count),
               credibility_score = VALUES(credibility_score),
               weekly_score      = VALUES(weekly_score),
               blue_tick_bonus   = VALUES(blue_tick_bonus),
               last_calculated_at = NOW()"
        )->execute([
            $uid, $approved_articles, $total_views, $followers,
            $total_score, $weekly_score, $blue_tick ? 1 : 0,
        ]);

        // ── Award badges ──────────────────────────────────────────────────
        $new_badges = [];

        // Helper — award badge if not already held
        $award_badge = function (string $slug) use ($pdo, $uid, $badges_by_slug, &$new_badges): void {
            if (!isset($badges_by_slug[$slug])) return;
            $badge_id = (int)$badges_by_slug[$slug]['id'];
            $check = $pdo->prepare(
                'SELECT 1 FROM reporter_badges WHERE reporter_id = ? AND badge_id = ?'
            );
            $check->execute([$uid, $badge_id]);
            if (!$check->fetchColumn()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO reporter_badges (reporter_id, badge_id) VALUES (?,?)'
                )->execute([$uid, $badge_id]);
                $new_badges[] = $badges_by_slug[$slug]['name'];
            }
        };

        if ($approved_articles >= 1)   $award_badge('first_article');
        if ($approved_articles >= 10)  $award_badge('articles_10');
        if ($approved_articles >= 50)  $award_badge('articles_50');
        if ($approved_articles >= 100) $award_badge('articles_100');
        if ($total_views >= 1000)      $award_badge('views_1k');
        if ($total_views >= 10000)     $award_badge('views_10k');
        if ($total_views >= 100000)    $award_badge('views_100k');
        if ($blue_tick)                $award_badge('blue_tick');
        if ($fake_news_strikes === 0 && $approved_articles >= 5) $award_badge('no_fake_news');

        // ── FCM notification for new badges ──────────────────────────────
        if (!empty($new_badges) && !empty($reporter['fcm_token'])) {
            $badge_names = implode(', ', $new_badges);
            sendPushNotification(
                $reporter['fcm_token'],
                '🏅 New Badge Unlocked!',
                "Congratulations! You earned: {$badge_names}",
                ['type' => 'badge_awarded']
            );
        }

        $processed++;
    } catch (Throwable $e) {
        echo "ERROR user_id={$uid}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

// ── Rebuild weekly rank ───────────────────────────────────────────────────────
$pdo->exec(
    'UPDATE reporter_scores rs
     JOIN (
       SELECT user_id, RANK() OVER (ORDER BY weekly_score DESC) AS rk
       FROM reporter_scores
     ) ranked ON ranked.user_id = rs.user_id
     SET rs.weekly_rank = ranked.rk'
);

// ── Rebuild all-time rank ─────────────────────────────────────────────────────
$pdo->exec(
    'UPDATE reporter_scores rs
     JOIN (
       SELECT user_id, RANK() OVER (ORDER BY credibility_score DESC) AS rk
       FROM reporter_scores
     ) ranked ON ranked.user_id = rs.user_id
     SET rs.all_time_rank = ranked.rk'
);

// ── Award top_weekly badge to rank-1 reporter ─────────────────────────────────
$top = $pdo->query(
    "SELECT rs.user_id, u.firebase_uid, u.fcm_token
     FROM reporter_scores rs JOIN users u ON u.id = rs.user_id
     WHERE rs.weekly_rank = 1 LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($top && isset($badges_by_slug['top_weekly'])) {
    $badge_id = (int)$badges_by_slug['top_weekly']['id'];
    $pdo->prepare(
        'INSERT IGNORE INTO reporter_badges (reporter_id, badge_id) VALUES (?,?)'
    )->execute([$top['user_id'], $badge_id]);

    if (!empty($top['fcm_token'])) {
        sendPushNotification(
            $top['fcm_token'],
            '🏆 Weekly Champion!',
            'You are ranked #1 on this week\'s leaderboard!',
            ['type' => 'badge_awarded']
        );
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]')
     . " Done. Processed={$processed}, Errors={$errors}, Time={$elapsed}s\n";
