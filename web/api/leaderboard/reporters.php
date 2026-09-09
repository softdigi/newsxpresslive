<?php
/**
 * web/api/leaderboard/reporters.php
 * Public API — reporter leaderboard with badges.
 *
 * GET /web/api/leaderboard/reporters.php
 *   ?period=weekly|monthly|all_time  (default: weekly)
 *   &state_id=<int>                  (optional)
 *   &limit=<int 1-100>               (default: 50)
 *
 * Response: { success, period, updated_at,
 *   leaderboard: [{ rank, reporter_uid, display_name, photo_url,
 *     is_blue_tick, total_score, articles_this_week, views_this_week,
 *     badges: [...], state }] }
 * Cache: 5 minutes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$period   = in_array($_GET['period'] ?? '', ['weekly', 'monthly', 'all_time'], true)
            ? $_GET['period'] : 'weekly';
$state_id = (int)($_GET['state_id'] ?? 0);
$limit    = max(1, min(100, (int)($_GET['limit'] ?? 50)));

$cache_key = "leaderboard:reporters:{$period}:state:{$state_id}:limit:{$limit}";
$cache     = ApiCache::getInstance();

$result = $cache->remember($cache_key, 300, function () use ($pdo, $period, $state_id, $limit): array {
    // Determine sort column
    $rank_col  = match ($period) {
        'monthly'  => 'rs.all_time_rank', // monthly_rank not in schema, use all_time as proxy
        'all_time' => 'rs.all_time_rank',
        default    => 'rs.weekly_rank',
    };
    $score_col = match ($period) {
        'all_time' => 'rs.credibility_score',
        default    => 'rs.weekly_score',
    };

    $where  = ["u.role = 'reporter'", "u.status = 'active'"];
    $params = [];

    if ($state_id > 0) {
        $where[]  = 'u.state_id = ?';
        $params[] = $state_id;
    }

    $where_sql = implode(' AND ', $where);

    $sql = "SELECT
              u.firebase_uid AS reporter_uid,
              COALESCE(u.display_name, u.name, u.firebase_uid) AS display_name,
              u.photo_url,
              COALESCE(rp.is_verified, 0) AS is_blue_tick,
              {$score_col} AS total_score,
              rs.articles_approved,
              rs.total_views,
              rs.weekly_score,
              rs.weekly_rank,
              rs.all_time_rank,
              rs.last_calculated_at,
              COALESCE(s.name, '') AS state
            FROM users u
            JOIN reporter_scores rs ON rs.user_id = u.id
            LEFT JOIN reporter_profiles rp ON rp.user_id = u.id
            LEFT JOIN states s ON s.id = u.state_id
            WHERE {$where_sql}
            ORDER BY {$score_col} DESC
            LIMIT ?";

    $params[] = $limit;
    $stmt     = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Fetch badges for each reporter ────────────────────────────────────
    $uids = array_column($reporters, 'reporter_uid');
    $badges_by_uid = [];

    if (!empty($uids)) {
        $ph   = implode(',', array_fill(0, count($uids), '?'));
        $bstmt = $pdo->prepare(
            "SELECT u.firebase_uid, b.slug, b.name, rb.awarded_at
             FROM reporter_badges rb
             JOIN badges b ON b.id = rb.badge_id
             JOIN users u ON u.id = rb.reporter_id
             WHERE u.firebase_uid IN ({$ph})
             ORDER BY rb.awarded_at DESC"
        );
        $bstmt->execute($uids);
        foreach ($bstmt->fetchAll(PDO::FETCH_ASSOC) as $badge) {
            $badges_by_uid[$badge['firebase_uid']][] = [
                'slug'       => $badge['slug'],
                'name'       => $badge['name'],
                'awarded_at' => $badge['awarded_at'],
            ];
        }
    }

    $leaderboard  = [];
    $updated_at   = null;
    foreach ($reporters as $rank => $r) {
        if ($updated_at === null) {
            $updated_at = $r['last_calculated_at'];
        }
        $leaderboard[] = [
            'rank'              => $rank + 1,
            'reporter_uid'      => $r['reporter_uid'],
            'display_name'      => $r['display_name'],
            'photo_url'         => $r['photo_url'],
            'is_blue_tick'      => (bool)$r['is_blue_tick'],
            'total_score'       => (float)$r['total_score'],
            'articles_this_week'=> (int)$r['articles_approved'],
            'views_this_week'   => (int)$r['total_views'],
            'badges'            => $badges_by_uid[$r['reporter_uid']] ?? [],
            'state'             => $r['state'],
        ];
    }

    return [
        'period'     => $period,
        'updated_at' => $updated_at ?? date('c'),
        'leaderboard'=> $leaderboard,
    ];
});

echo json_encode(array_merge(['success' => true], $result));
