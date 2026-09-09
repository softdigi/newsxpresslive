<?php
/**
 * web/api/cricket/live.php
 * Public API — live + upcoming cricket matches.
 *
 * GET — no parameters required
 *
 * Response: array of match objects:
 * [{
 *   "match_id": "abc123", "series": "...",
 *   "team1": "IND", "team2": "AUS",
 *   "score1": "285/6 (45 ov)", "score2": "Yet to bat",
 *   "status": "live", "status_text": "India needs 45 to win",
 *   "is_live": true, "match_date": "2025-01-15T14:00:00Z"
 * }]
 *
 * Cache: Redis 2 minutes.
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

// Check feature flag
if (getenv('CRICKET_WIDGET_ENABLED') === 'false') {
    echo json_encode(['success' => true, 'matches' => []]);
    exit;
}

$cache     = ApiCache::getInstance();
$cache_key = 'cricket:live_matches';

$matches = $cache->remember($cache_key, 120, function () use ($pdo): array {
    $stmt = $pdo->query(
        "SELECT match_id, series_name, team1_short, team2_short,
                team1_score, team2_score, status, status_text,
                match_date, match_winner
         FROM cricket_matches
         WHERE status IN ('live','upcoming')
           AND (match_date IS NULL OR match_date >= DATE_SUB(NOW(), INTERVAL 4 HOUR))
         ORDER BY
           FIELD(status,'live','upcoming') ASC,
           match_date ASC
         LIMIT 10"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$result = array_map(function (array $m): array {
    return [
        'match_id'    => $m['match_id'],
        'series'      => $m['series_name'],
        'team1'       => $m['team1_short'],
        'team2'       => $m['team2_short'],
        'score1'      => $m['team1_score'],
        'score2'      => $m['team2_score'],
        'status'      => $m['status'],
        'status_text' => $m['status_text'],
        'is_live'     => $m['status'] === 'live',
        'match_date'  => $m['match_date'],
        'winner'      => $m['match_winner'],
    ];
}, $matches);

echo json_encode(['success' => true, 'matches' => $result]);
