<?php
// ============================================================
// FIXED: admin/viral_boosts_active.php
// CRITICAL BUGS:
//   1. NO AUTH CHECK — this file has ZERO authentication.
//      Anyone on the internet can call it and get all active
//      viral boosts with full details (news titles, bonuses,
//      reporter data, internal multipliers).
//      Access-Control-Allow-Origin: * makes it worse — any
//      website can make cross-origin requests to this endpoint.
//   2. N+1 QUERY: foreach loop runs a separate DB query per
//      boost to get metrics. With 50 active boosts = 51 queries.
//      Fixed with a single GROUP BY query.
//   3. Access-Control-Allow-Origin: * on an admin endpoint
//      is a misconfiguration — admin APIs should never be
//      wildcard CORS. Removed.
// ============================================================
header('Content-Type: application/json');
// FIXED: removed wildcard CORS header — admin endpoint must not be publicly CORS-accessible

require_once __DIR__ . '/../geo/config.php';
require_once __DIR__ . '/../geo/response.php';

// FIXED: auth check added
session_start();
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    sendResponse(false, null, 'Unauthorized', 401);
}

try {
    // FIXED: Single query with LEFT JOIN to viral_boost_metrics
    // instead of N+1 loop (one query per boost)
    $stmt = $pdo->query("
        SELECT
            vb.id,
            vb.news_id,
            vb.boost_level,
            vb.status,
            vb.target_type,
            vb.target_locations,
            vb.duration_hours,
            vb.start_time,
            vb.end_time,
            vb.pin_to_top,
            vb.viral_multiplier,
            vb.boost_score,
            vb.reporter_bonus,
            vb.created_at,
            TIMESTAMPDIFF(HOUR, NOW(), vb.end_time) AS hours_remaining,
            n.title       AS news_title,
            n.is_breaking AS news_is_breaking,
            COALESCE(m.total_views,    0) AS metric_views,
            COALESCE(m.total_likes,    0) AS metric_likes,
            COALESCE(m.total_shares,   0) AS metric_shares,
            COALESCE(m.total_comments, 0) AS metric_comments
        FROM viral_boosts vb
        JOIN news n ON n.id = vb.news_id
        LEFT JOIN (
            SELECT
                boost_id,
                SUM(views)    AS total_views,
                SUM(likes)    AS total_likes,
                SUM(shares)   AS total_shares,
                SUM(comments) AS total_comments
            FROM viral_boost_metrics
            GROUP BY boost_id
        ) m ON m.boost_id = vb.id
        WHERE vb.status IN ('active', 'scheduled', 'paused')
        ORDER BY
            CASE vb.status
                WHEN 'active'    THEN 1
                WHEN 'scheduled' THEN 2
                ELSE 3
            END,
            vb.start_time DESC
        LIMIT 100
    ");

    $boosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode target_locations JSON and build metrics sub-object
    foreach ($boosts as &$boost) {
        $boost['target_locations'] = json_decode($boost['target_locations'] ?? '[]', true) ?? [];
        $boost['metrics'] = [
            'views'    => (int)$boost['metric_views'],
            'likes'    => (int)$boost['metric_likes'],
            'shares'   => (int)$boost['metric_shares'],
            'comments' => (int)$boost['metric_comments'],
        ];
        unset(
            $boost['metric_views'], $boost['metric_likes'],
            $boost['metric_shares'], $boost['metric_comments']
        );
    }
    unset($boost);

    sendResponse(true, [
        'count'  => count($boosts),
        'boosts' => $boosts,
    ]);

} catch (Exception $e) {
    error_log('viral_boosts_active error: ' . $e->getMessage());
    sendResponse(false, null, 'Failed to fetch boosts', 500);
}
