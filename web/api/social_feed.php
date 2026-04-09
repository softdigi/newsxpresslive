<?php
/**
 * web/api/social_feed.php
 * Social Layer — Feed from Followed Users
 *
 * GET /web/api/social_feed.php
 * Headers:  Authorization: Bearer <firebase_id_token>
 * Query:    page=1  limit=15  exclude=1,2,3
 *
 * Returns news articles submitted by reporters the authenticated user follows.
 * Falls back to trending articles when the social feed is empty or on the
 * first page if the user does not yet follow anyone.
 *
 * Response:
 * {
 *   "success": true,
 *   "page": 1,
 *   "limit": 15,
 *   "has_more": true,
 *   "social_count": 10,   // articles from followed reporters
 *   "fallback": false,    // true when trending fill-in was used
 *   "news": [ ... ]       // same fields as more_news.php
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../auth/firebase.php';

// ── Auth ───────────────────────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}
$payload = verifyFirebaseToken($idToken);
if (!$payload || empty($payload['sub'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$firebaseUid = $payload['sub'];

// ── Params ─────────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page']  ?? 1));
$limit   = min(30, max(1, (int)($_GET['limit'] ?? 15)));
$offset  = ($page - 1) * $limit;

$excludeIds = [];
if (!empty($_GET['exclude'])) {
    $excludeIds = array_filter(
        array_map('intval', explode(',', $_GET['exclude'])),
        fn($id) => $id > 0
    );
}

// ── Build feed ─────────────────────────────────────────────────────────────
try {
    // 1. Get the firebase_uids of everyone this user follows
    $followStmt = $pdo->prepare(
        'SELECT following_id FROM user_follows WHERE follower_id = :uid'
    );
    $followStmt->execute([':uid' => $firebaseUid]);
    $followingUids = $followStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $fallback     = false;
    $socialCount  = 0;
    $news         = [];

    if (!empty($followingUids)) {
        // 2. Look up backend user IDs for those firebase_uids
        $phPlaceholders = implode(',', array_fill(0, count($followingUids), '?'));
        $uidStmt = $pdo->prepare(
            "SELECT id FROM users WHERE firebase_uid IN ($phPlaceholders)"
        );
        $uidStmt->execute($followingUids);
        $backendIds = $uidStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!empty($backendIds)) {
            // 3. Fetch approved articles submitted by those reporters
            $idPh  = implode(',', array_fill(0, count($backendIds), '?'));
            $exPh  = !empty($excludeIds)
                ? ' AND n.id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')'
                : '';

            $params = array_merge($backendIds, $excludeIds);

            $newsStmt = $pdo->prepare(
                "SELECT n.id, n.title, n.slug, n.excerpt, n.featured_image,
                        n.created_at, n.views_count, n.likes_count,
                        n.shares_count, n.viral_score,
                        c.name AS category_name, c.slug AS category_slug,
                        u.firebase_uid AS reporter_uid,
                        COALESCE(up.display_name, u.name) AS reporter_name,
                        up.avatar_url AS reporter_avatar,
                        up.is_verified AS reporter_verified
                   FROM news n
                   JOIN categories c ON c.id = n.category_id
                   JOIN users u      ON u.id = n.user_id
                   LEFT JOIN user_profiles up ON up.firebase_uid = u.firebase_uid
                  WHERE n.status = 'approved'
                    AND n.user_id IN ($idPh)
                    $exPh
                  ORDER BY n.created_at DESC
                  LIMIT ? OFFSET ?"
            );
            $params[] = $limit;
            $params[] = $offset;
            $newsStmt->execute($params);
            $news = $newsStmt->fetchAll();
            $socialCount = count($news);
        }
    }

    // 4. Fallback: fill with trending if we got nothing
    if (empty($news) && $page === 1) {
        $fallback  = true;
        $exPh      = !empty($excludeIds)
            ? ' AND n.id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')'
            : '';
        $params    = array_merge($excludeIds, [$limit, $offset]);

        $trendStmt = $pdo->prepare(
            "SELECT n.id, n.title, n.slug, n.excerpt, n.featured_image,
                    n.created_at, n.views_count, n.likes_count,
                    n.shares_count, n.viral_score,
                    c.name AS category_name, c.slug AS category_slug,
                    u.firebase_uid AS reporter_uid,
                    COALESCE(up.display_name, u.name) AS reporter_name,
                    up.avatar_url AS reporter_avatar,
                    up.is_verified AS reporter_verified
               FROM news n
               JOIN categories c ON c.id = n.category_id
               JOIN users u      ON u.id = n.user_id
               LEFT JOIN user_profiles up ON up.firebase_uid = u.firebase_uid
              WHERE n.status = 'approved'
                $exPh
              ORDER BY n.viral_score DESC, n.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $trendStmt->execute($params);
        $news = $trendStmt->fetchAll();
    }

    // 5. Format output
    $formatted = array_map(function (array $row): array {
        return [
            'id'               => (int)$row['id'],
            'title'            => $row['title'],
            'slug'             => $row['slug'],
            'excerpt'          => $row['excerpt'],
            'featured_image'   => $row['featured_image'],
            'created_at'       => $row['created_at'],
            'views_count'      => (int)$row['views_count'],
            'likes_count'      => (int)$row['likes_count'],
            'shares_count'     => (int)$row['shares_count'],
            'viral_score'      => (float)$row['viral_score'],
            'category_name'    => $row['category_name'],
            'category_slug'    => $row['category_slug'],
            'reporter_uid'     => $row['reporter_uid'],
            'reporter_name'    => $row['reporter_name'],
            'reporter_avatar'  => $row['reporter_avatar'],
            'reporter_verified'=> (bool)$row['reporter_verified'],
        ];
    }, $news);

    echo json_encode([
        'success'       => true,
        'page'          => $page,
        'limit'         => $limit,
        'has_more'      => count($news) === $limit,
        'social_count'  => $socialCount,
        'fallback'      => $fallback,
        'news'          => $formatted,
    ]);

} catch (PDOException $e) {
    error_log('social_feed.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
