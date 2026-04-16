<?php
/**
 * web/api/groups/discover.php
 * GET — Discover community groups sorted by distance or member count.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../helpers/cors.php';
require_once __DIR__ . '/../../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../../web/includes/config.php';
require_once __DIR__ . '/../../../../auth/firebase.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$viewer_uid = null;
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth, 'Bearer ')) {
    $token = substr($auth, 7);
    $user  = verifyFirebaseToken($token);
    if ($user) {
        $viewer_uid = $user['uid'] ?? null;
    }
}

$lat         = isset($_GET['lat'])         ? (float)$_GET['lat']       : null;
$lng         = isset($_GET['lng'])         ? (float)$_GET['lng']       : null;
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$state_id    = isset($_GET['state_id'])    ? (int)$_GET['state_id']    : null;
$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : null;
$limit       = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$cursor      = (int)($_GET['cursor'] ?? 0);

$hasGeo = ($lat !== null && $lng !== null);
$distanceExpr = $hasGeo
    ? '(6371 * ACOS(GREATEST(-1, LEAST(1, COS(RADIANS(?)) * COS(RADIANS(g.latitude)) * COS(RADIANS(g.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(g.latitude))))))'
    : 'NULL';

$where  = "WHERE g.status = 'active'";
$params = [];

if ($hasGeo) {
    $params[] = $lat;
    $params[] = $lng;
    $params[] = $lat;
}
if ($category_id !== null) { $where .= ' AND g.category_id = ?'; $params[] = $category_id; }
if ($state_id    !== null) { $where .= ' AND g.state_id = ?';    $params[] = $state_id; }
if ($district_id !== null) { $where .= ' AND g.district_id = ?'; $params[] = $district_id; }
if ($cursor > 0)           { $where .= ' AND g.id < ?';          $params[] = $cursor; }

$orderBy  = $hasGeo ? 'distance_km ASC, g.members_count DESC' : 'g.members_count DESC, g.id DESC';
$params[] = $limit + 1;

$joinStatus = $viewer_uid
    ? "LEFT JOIN group_members gm ON gm.group_id = g.id AND gm.user_uid = " . $pdo->quote($viewer_uid)
    : '';
$statusField = $viewer_uid ? 'gm.status AS your_status' : "NULL AS your_status";

$sql = "SELECT g.id, g.name, g.name_hi, g.description, g.cover_image_url,
               g.group_type, g.members_count, g.posts_count, g.active_today,
               g.is_public, g.join_approval, g.is_premium, g.monthly_fee,
               {$statusField},
               {$distanceExpr} AS distance_km
        FROM community_groups g
        {$joinStatus}
        {$where}
        ORDER BY {$orderBy}
        LIMIT ?";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);

$nextCursor = $hasMore && count($rows) > 0 ? (int)end($rows)['id'] : null;

$groups = array_map(function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'name'            => $r['name'],
        'name_hi'         => $r['name_hi'],
        'description'     => $r['description'],
        'cover_image_url' => $r['cover_image_url'],
        'group_type'      => $r['group_type'],
        'members_count'   => (int)$r['members_count'],
        'posts_count'     => (int)$r['posts_count'],
        'active_today'    => (bool)$r['active_today'],
        'is_public'       => (bool)$r['is_public'],
        'join_approval'   => (bool)$r['join_approval'],
        'is_premium'      => (bool)$r['is_premium'],
        'monthly_fee'     => (float)$r['monthly_fee'],
        'your_status'     => $r['your_status'],
        'distance_km'     => $r['distance_km'] !== null ? round((float)$r['distance_km'], 1) : null,
    ];
}, $rows);

echo json_encode([
    'success'     => true,
    'groups'      => $groups,
    'has_more'    => $hasMore,
    'next_cursor' => $nextCursor,
]);
