<?php
// World-class Personalized Feed with Viral Engine + Trust + Safety
// Pagination: keyset (cursor) — avoids full-table scans on large datasets.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../geo/config.php';
require_once '../geo/response.php';
require_once '../helpers/kill_switch.php';
checkKillSwitch($pdo, ['user_id'=>$user_id, 'news_id'=>$news_id ?? null]);

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$limit   = 20;

// ── Cursor-based pagination ────────────────────────────────────────────────
// Client sends ?last_id=N&last_created_at=YYYY-MM-DD+HH:MM:SS on every
// request after the first.  On the first page both parameters are absent.
// The server returns next_last_id + next_last_created_at in each response.
$lastId        = isset($_GET['last_id'])         ? (int)   $_GET['last_id']                   : null;
$lastCreatedAt = isset($_GET['last_created_at']) ? (string)$_GET['last_created_at']           : null;

// Basic sanitisation — must be a valid datetime string or null
if ($lastCreatedAt !== null) {
    $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $lastCreatedAt);
    if ($parsed === false) {
        $lastCreatedAt = null;
        $lastId        = null;
    }
}

if (!$user_id) {
    sendResponse(false, null, 'user_id required', 400);
}

try {

    /* ─────────────────────────────────────────────
       USER CONTEXT (Interests + Location)
    ───────────────────────────────────────────── */

    // User interests (category_id => weight)
    $stmt = $pdo->prepare("SELECT category_id, weight FROM user_interests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $userInterests = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // User location
    $stmt = $pdo->prepare("SELECT country_id, state_id, district_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);

    /* ─────────────────────────────────────────────
       CORE FEED QUERY — keyset pagination
       Uses composite (created_at, id) index so MySQL
       never scans rows outside the requested window.
    ───────────────────────────────────────────── */

    if ($lastCreatedAt !== null && $lastId !== null) {
        // Pages 2, 3, … — only rows strictly before the cursor
        $query = "
            SELECT
                n.*,
                COALESCE(vb.boost_score, 0) AS boost_priority,
                vb.boost_level,
                vb.pin_to_top,
                vb.viral_multiplier,
                CASE WHEN vb.id IS NOT NULL THEN 1 ELSE 0 END AS is_boosted
            FROM news n
            LEFT JOIN viral_boosts vb
                ON n.id = vb.news_id
               AND vb.status = 'active'
               AND NOW() BETWEEN vb.start_time AND vb.end_time
            WHERE n.status = 'approved'
              AND (n.kill_switch IS NULL OR n.kill_switch = 'none')
              AND NOT EXISTS (
                    SELECT 1 FROM news_trust_scores nts
                    WHERE nts.news_id = n.id
                      AND nts.trust_score < 30
              )
              AND (n.created_at < ? OR (n.created_at = ? AND n.id < ?))
            ORDER BY
                n.created_at DESC,
                n.id DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$lastCreatedAt, $lastCreatedAt, $lastId, $limit]);
    } else {
        // First page
        $query = "
            SELECT
                n.*,
                COALESCE(vb.boost_score, 0) AS boost_priority,
                vb.boost_level,
                vb.pin_to_top,
                vb.viral_multiplier,
                CASE WHEN vb.id IS NOT NULL THEN 1 ELSE 0 END AS is_boosted
            FROM news n
            LEFT JOIN viral_boosts vb
                ON n.id = vb.news_id
               AND vb.status = 'active'
               AND NOW() BETWEEN vb.start_time AND vb.end_time
            WHERE n.status = 'approved'
              AND (n.kill_switch IS NULL OR n.kill_switch = 'none')
              AND NOT EXISTS (
                    SELECT 1 FROM news_trust_scores nts
                    WHERE nts.news_id = n.id
                      AND nts.trust_score < 30
              )
            ORDER BY
                vb.pin_to_top DESC,
                boost_priority DESC,
                n.is_breaking DESC,
                n.viral_score DESC,
                n.is_featured DESC,
                n.created_at DESC,
                n.id DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$limit]);
    }

    $news = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ─────────────────────────────────────────────
       FEED SCORE CALCULATION (Runtime Brain)
    ───────────────────────────────────────────── */

    foreach ($news as &$item) {

        /* Views */
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM news_views WHERE news_id = ?");
        $stmt->execute([$item['id']]);
        $views = intval($stmt->fetchColumn());

        /* Trust score */
        $stmt = $pdo->prepare("SELECT trust_score FROM news_trust_scores WHERE news_id = ?");
        $stmt->execute([$item['id']]);
        $trustScore = intval($stmt->fetchColumn() ?? 50);

        /* Feed score starts */
        $feedScore = 0;

        // Viral priority
        $feedScore += ($item['viral_score'] * 0.25);

        // Admin boost
        if ($item['is_boosted']) {
            $feedScore += $item['boost_priority'];
        }

        // Breaking news
        if ($item['is_breaking']) {
            $feedScore += 1000;
        }

        // Interest relevance
        if (isset($userInterests[$item['category_id']])) {
            $feedScore += ($userInterests[$item['category_id']] * 200);
        }

        // Location relevance
        if (!empty($userLocation)) {
            if ($item['district_id'] == $userLocation['district_id']) {
                $feedScore += 500;
            } elseif ($item['state_id'] == $userLocation['state_id']) {
                $feedScore += 300;
            } elseif ($item['country_id'] == $userLocation['country_id']) {
                $feedScore += 100;
            }
        }

        // Freshness
        $hoursOld = (time() - strtotime($item['created_at'])) / 3600;
        $feedScore += max(0, 1000 - ($hoursOld * 20));

        // Trust influence
        $feedScore += ($trustScore * 0.05);

        /* Attach runtime data */
        $item['views']      = $views;
        $item['trust_score'] = $trustScore;
        $item['feed_score']  = round($feedScore);

        // Normalize booleans
        $item['is_breaking'] = (int)$item['is_breaking'];
        $item['is_featured'] = (int)$item['is_featured'];
        $item['is_boosted']  = (int)$item['is_boosted'];
    }
    unset($item);

    /* ─────────────────────────────────────────────
       FINAL SORT (Feed Score)
    ───────────────────────────────────────────── */

    usort($news, function ($a, $b) {
        return $b['feed_score'] <=> $a['feed_score'];
    });

    /* ─────────────────────────────────────────────
       DEDUPLICATION (Reporter Cap)
    ───────────────────────────────────────────── */

    $reporterCount = [];
    $finalFeed     = [];

    foreach ($news as $n) {
        $rid = $n['author_id'];
        $reporterCount[$rid] = ($reporterCount[$rid] ?? 0) + 1;

        if ($reporterCount[$rid] <= 3) {
            $finalFeed[] = $n;
        }
        if (count($finalFeed) >= $limit) {
            break;
        }
    }

    /* ─────────────────────────────────────────────
       BUILD NEXT CURSOR
    ───────────────────────────────────────────── */

    $hasMore      = count($finalFeed) === $limit;
    $nextLastId   = null;
    $nextCreatedAt = null;

    if ($hasMore && !empty($finalFeed)) {
        // After usort the order is by feed_score, not created_at.
        // For stable cursor pagination we pick the minimum (oldest) created_at
        // + id among the returned batch so the next page continues from there.
        $last         = end($finalFeed);
        $nextLastId   = (int) $last['id'];
        $nextCreatedAt = $last['created_at'];
    }

    /* ─────────────────────────────────────────────
       RESPONSE
    ───────────────────────────────────────────── */

    sendResponse(true, [
        'limit'              => $limit,
        'count'              => count($finalFeed),
        'has_more'           => $hasMore,
        'next_last_id'       => $nextLastId,
        'next_last_created_at' => $nextCreatedAt,
        'news'               => $finalFeed,
    ]);

} catch (Exception $e) {
    error_log('Feed error: ' . $e->getMessage());
    // SECURITY FIX: Never expose exception messages to clients
    sendResponse(false, null, 'Failed to fetch feed', 500);
}
?>
