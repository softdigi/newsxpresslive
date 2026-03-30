<?php
// ============================================================
// FIXED: api/v1/news/news_feed.php
// CONFIRMED FATAL (error_log):
//   require_once '../../config/database.php' → WRONG PATH
//   Actual config is at: geo/config.php relative to api root
//   Fixed to: require_once __DIR__ . '/../../../geo/config.php'
//
// OTHER ISSUES:
//   1. N+1 query: foreach loop hits DB once per news item
//      for view counts → 20 articles = 21 queries.
//      Fixed: views already on news table (or use JOIN once).
//   2. user_id from GET — not verified to exist or be active
//   3. $page never validated — negative values give wrong offset
//   4. Exception message exposed to client in production
//   5. Access-Control-Allow-Origin: * — acceptable for public
//      feed but noted
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// FIXED: correct path from api/v1/news/ up to geo/config.php
require_once __DIR__ . '/../../../geo/config.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id required']);
    exit;
}

// Verify user exists and is active
$userCheck = $pdo->prepare(
    "SELECT id, status FROM users WHERE id = ? LIMIT 1"
);
$userCheck->execute([$user_id]);
$user = $userCheck->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['status'] === 'blocked') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'user not found or suspended']);
    exit;
}

try {
    // FIXED: removed N+1 loop for view counts.
    // news.views column used directly (maintained by app logic).
    // If you use a separate news_views table, do a single
    // subquery JOIN here instead of a per-row loop.
    $stmt = $pdo->prepare("
        SELECT
            n.id,
            n.title,
            n.slug,
            n.description,
            n.image,
            n.image_sizes,
            n.status,
            n.is_breaking,
            n.is_featured,
            n.is_viral_boosted,
            n.views,
            n.created_at,
            COALESCE(vb.boost_score, 0)    AS boost_priority,
            COALESCE(vb.boost_level, '')   AS boost_level,
            COALESCE(vb.pin_to_top, 0)     AS pin_to_top,
            COALESCE(vb.viral_multiplier, 1) AS viral_multiplier,
            CASE WHEN vb.id IS NOT NULL THEN 1 ELSE 0 END AS is_boosted
        FROM news n
        LEFT JOIN viral_boosts vb
            ON n.id = vb.news_id
            AND vb.status = 'active'
            AND NOW() BETWEEN vb.start_time AND vb.end_time
        WHERE n.status = 'approved'
        ORDER BY
            vb.pin_to_top     DESC,
            boost_priority    DESC,
            n.is_breaking     DESC,
            n.viral_score     DESC,
            n.is_featured     DESC,
            n.created_at      DESC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $news = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast boolean/int fields — no DB loop needed
    foreach ($news as &$item) {
        $item['is_viral_boosted'] = (int)$item['is_viral_boosted'];
        $item['is_breaking']      = (int)$item['is_breaking'];
        $item['is_featured']      = (int)$item['is_featured'];
        $item['is_boosted']       = (int)$item['is_boosted'];
        $item['views']            = (int)$item['views'];

        // Decode image_sizes JSON; fall back to image column for legacy articles
        if (!empty($item['image_sizes'])) {
            $item['image_sizes'] = json_decode($item['image_sizes'], true) ?: null;
        }
        if (empty($item['image_sizes']) && !empty($item['image'])) {
            // Legacy article — expose the single image as all three sizes
            $item['image_sizes'] = [
                'thumbnail' => $item['image'],
                'medium'    => $item['image'],
                'original'  => $item['image'],
            ];
        }
        // Lazy-load hint: always true when image_sizes is present
        $item['lazy_load'] = !empty($item['image_sizes']);
    }
    unset($item);

    echo json_encode([
        'success'  => true,
        'page'     => $page,
        'limit'    => $limit,
        'count'    => count($news),
        'has_more' => count($news) === $limit,
        'news'     => $news,
    ]);

} catch (Exception $e) {
    error_log('news_feed error: ' . $e->getMessage());
    http_response_code(500);
    // FIXED: never expose exception message in production
    echo json_encode(['success' => false, 'error' => 'Failed to fetch news feed']);
}
