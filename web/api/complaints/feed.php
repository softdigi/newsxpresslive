<?php
/**
 * web/api/complaints/feed.php
 * Enhanced Public Voice — Complaint Feed
 *
 * GET /web/api/complaints/feed.php
 * Headers: Authorization: Bearer <token>  (required only for type=my_complaints)
 *
 * Params:
 *   type        local|viral|recent|my_complaints  (default: recent)
 *   lat         float    (required for type=local)
 *   lng         float    (required for type=local)
 *   radius_km   int      (default 10, max 50)
 *   state_id    int      (filter)
 *   district_id int      (filter)
 *   category_id int      (filter)
 *   status      string   (filter)
 *   cursor      int      (last seen id for keyset pagination)
 *   limit       int      (default 20, max 50)
 *
 * Response: { success, type, items:[...], has_more, next_cursor }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../../helpers/cors.php';
corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Helper: human-readable relative time ──────────────────────────────────
function relativeTime(string $datetime): string
{
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)   return $diff . ' second' . ($diff === 1 ? '' : 's') . ' ago';
    if ($diff < 3600) { $m = (int)($diff / 60);   return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400){ $h = (int)($diff / 3600);  return $h . ' hour'   . ($h === 1 ? '' : 's') . ' ago'; }
    $d = (int)($diff / 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

// ── Optional auth (required for my_complaints) ────────────────────────────
$currentUid = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $payload = verifyFirebaseToken(trim($m[1]));
    if ($payload && !empty($payload['sub'])) {
        $currentUid = $payload['sub'];
    }
}

// ── Params ─────────────────────────────────────────────────────────────────
$type       = in_array($_GET['type'] ?? '', ['local', 'viral', 'recent', 'my_complaints'], true)
    ? $_GET['type']
    : 'recent';
$limit      = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$cursor     = isset($_GET['cursor']) && ctype_digit((string)$_GET['cursor']) ? (int)$_GET['cursor'] : null;
$lat        = isset($_GET['lat'])    && is_numeric($_GET['lat'])    ? (float)$_GET['lat']    : null;
$lng        = isset($_GET['lng'])    && is_numeric($_GET['lng'])    ? (float)$_GET['lng']    : null;
$radiusKm   = min(50, max(1, (int)($_GET['radius_km'] ?? 10)));
$stateId    = isset($_GET['state_id'])    && ctype_digit((string)$_GET['state_id'])    ? (int)$_GET['state_id']    : null;
$districtId = isset($_GET['district_id']) && ctype_digit((string)$_GET['district_id']) ? (int)$_GET['district_id'] : null;
$categoryId = isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id']) ? (int)$_GET['category_id'] : null;
$statusFilter = trim($_GET['status'] ?? '');

// my_complaints requires auth
if ($type === 'my_complaints' && empty($currentUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required for my_complaints']);
    exit;
}

// ── Build base SELECT ──────────────────────────────────────────────────────
$baseSelect = "
    SELECT c.id,
           c.title,
           SUBSTRING(c.description, 1, 300) AS description,
           c.images,
           c.video_url,
           c.status,
           c.upvotes_count,
           c.comments_count,
           c.views_count,
           c.is_viral,
           c.is_anonymous,
           c.user_id,
           c.latitude,
           c.longitude,
           c.address,
           c.city,
           c.district_id,
           c.state_id,
           c.created_at,
           cc.name        AS category_name,
           cc.name_hi     AS category_name_hi,
           cc.icon        AS category_icon,
           cc.slug        AS category_slug,
           up.display_name AS user_name,
           up.avatar_url   AS user_avatar
    FROM complaints c
    LEFT JOIN complaint_categories cc ON cc.id = c.category_id
    LEFT JOIN user_profiles up        ON up.firebase_uid = c.user_id
";

$where  = [];
$params = [];

// Status filter — default show all except draft/rejected for public feeds
if ($statusFilter !== '') {
    $where[]            = 'c.status = :status';
    $params[':status']  = $statusFilter;
} elseif ($type !== 'my_complaints') {
    $where[] = "c.status NOT IN ('rejected')";
}

// Extra filters
if ($categoryId !== null) {
    $where[]              = 'c.category_id = :cat';
    $params[':cat']       = $categoryId;
}
if ($stateId !== null) {
    $where[]              = 'c.state_id = :sid';
    $params[':sid']       = $stateId;
}
if ($districtId !== null) {
    $where[]              = 'c.district_id = :did';
    $params[':did']       = $districtId;
}

// ── Type-specific query ────────────────────────────────────────────────────
try {
    if ($type === 'local') {
        if ($lat === null || $lng === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'lat and lng required for type=local']);
            exit;
        }

        // Haversine distance formula
        $distanceExpr = "(6371 * ACOS(
            COS(RADIANS(:lat)) * COS(RADIANS(c.latitude))
            * COS(RADIANS(c.longitude) - RADIANS(:lng))
            + SIN(RADIANS(:lat2)) * SIN(RADIANS(c.latitude))
        ))";

        $where[] = 'c.latitude IS NOT NULL';
        $where[] = 'c.longitude IS NOT NULL';
        $where[] = "$distanceExpr <= :radius";

        $params[':lat']    = $lat;
        $params[':lng']    = $lng;
        $params[':lat2']   = $lat;
        $params[':radius'] = $radiusKm;

        if ($cursor !== null) {
            $where[]          = 'c.id < :cursor';
            $params[':cursor'] = $cursor;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "$baseSelect $whereSql ORDER BY $distanceExpr ASC, c.created_at DESC LIMIT :lim";

    } elseif ($type === 'viral') {
        $where[] = '(c.is_viral = 1 OR c.upvotes_count >= 20)';
        if ($cursor !== null) {
            $where[]           = '(c.upvotes_count < :cur_votes OR (c.upvotes_count = :cur_votes2 AND c.id < :cursor))';
            // We'll use a simpler offset approach for viral since keyset on two cols is complex
            $where[] = 'c.id < :cursor';
            $params[':cursor'] = $cursor;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "$baseSelect $whereSql ORDER BY c.upvotes_count DESC, c.created_at DESC LIMIT :lim";

    } elseif ($type === 'my_complaints') {
        $where[]          = 'c.user_id = :uid';
        $params[':uid']   = $currentUid;
        if ($cursor !== null) {
            $where[]           = 'c.id < :cursor';
            $params[':cursor'] = $cursor;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "$baseSelect $whereSql ORDER BY c.created_at DESC LIMIT :lim";

    } else { // recent (default)
        if ($cursor !== null) {
            $where[]           = 'c.id < :cursor';
            $params[':cursor'] = $cursor;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "$baseSelect $whereSql ORDER BY c.created_at DESC LIMIT :lim";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore    = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    $nextCursor = $hasMore && !empty($rows) ? (int)end($rows)['id'] : null;

    // Check which complaints the current user has upvoted
    $upvotedIds = [];
    if ($currentUid && !empty($rows)) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $uvStmt = $pdo->prepare(
            "SELECT complaint_id FROM complaint_upvotes
              WHERE user_id = ? AND complaint_id IN ($ph)"
        );
        $uvStmt->execute(array_merge([$currentUid], $ids));
        $upvotedIds = array_column($uvStmt->fetchAll(), 'complaint_id');
    }

    // Format items
    $items = [];
    foreach ($rows as $row) {
        $distKm = null;
        if ($type === 'local' && $lat !== null && $lng !== null
            && $row['latitude'] !== null && $row['longitude'] !== null) {
            $rlat = deg2rad((float)$row['latitude']);
            $rlng = deg2rad((float)$row['longitude']);
            $olat = deg2rad($lat);
            $olng = deg2rad($lng);
            $dlat = $rlat - $olat;
            $dlng = $rlng - $olng;
            $a    = sin($dlat / 2) ** 2 + cos($olat) * cos($rlat) * sin($dlng / 2) ** 2;
            $distKm = round(6371 * 2 * asin(sqrt($a)), 1);
        }

        $images = json_decode($row['images'] ?? 'null', true);

        $items[] = [
            'id'             => (int)$row['id'],
            'title'          => $row['title'],
            'description'    => $row['description'],
            'images'         => is_array($images) ? $images : [],
            'category_name'  => $row['category_name'] ?? '',
            'category_name_hi'=> $row['category_name_hi'] ?? null,
            'category_icon'  => $row['category_icon'] ?? null,
            'category_slug'  => $row['category_slug'] ?? null,
            'status'         => $row['status'],
            'status_label'   => ucfirst(str_replace('_', ' ', $row['status'])),
            'upvotes_count'  => (int)$row['upvotes_count'],
            'comments_count' => (int)$row['comments_count'],
            'views_count'    => (int)$row['views_count'],
            'is_viral'       => (bool)$row['is_viral'],
            'is_anonymous'   => (bool)$row['is_anonymous'],
            'user_name'      => $row['is_anonymous'] ? null : ($row['user_name'] ?? null),
            'user_avatar'    => $row['is_anonymous'] ? null : ($row['user_avatar'] ?? null),
            'address'        => $row['address'] ?? $row['city'] ?? null,
            'distance_km'    => $distKm,
            'created_at'     => $row['created_at'],
            'created_ago'    => relativeTime($row['created_at']),
            'user_upvoted'   => in_array((int)$row['id'], $upvotedIds, true),
        ];
    }

    echo json_encode([
        'success'     => true,
        'type'        => $type,
        'items'       => $items,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);

} catch (PDOException $e) {
    error_log('complaints/feed.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
