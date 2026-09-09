<?php
/**
 * web/api/personalized_feed.php
 * Feature 1 — User Interest + Preference Based Feed
 *
 * GET /web/api/personalized_feed.php
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 * Query params:
 *   page    int  ≥1          (default 1)
 *   limit   int  1–30        (default 15)
 *   exclude string  comma-separated news IDs to skip (already-seen)
 *
 * Algorithm
 * ─────────
 * 1. Verify Firebase JWT → extract firebase_uid.
 * 2. Load user_interests rows for that user.
 * 3. Build weighted SQL:
 *      base_score = SUM(interest.weight) for matching category or tag
 *      recency    = EXP(-TIMESTAMPDIFF(HOUR, created_at, NOW()) / 72)
 *      final_rank = base_score * recency + viral_score_factor
 * 4. Mix in a "discovery" slice (20% of feed) of non-interest articles
 *    to prevent filter-bubble stagnation.
 * 5. Exclude already-read articles (from user_read_history).
 * 6. Return paginated JSON matching the NewsArticle Flutter model.
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "page": 1,
 *     "limit": 15,
 *     "has_more": true,
 *     "news": [ ... ]          // same fields as more_news.php
 *   }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../auth/firebase.php';

/* ── Auth ────────────────────────────────────────────────────────────── */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}

// Verify token and extract firebase_uid
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}

$payload = verifyFirebaseToken($idToken);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

$firebaseUid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($firebaseUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token payload']);
    exit;
}

/* ── Params ──────────────────────────────────────────────────────────── */
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = max(1, min(30, (int)($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;

// Client may pass comma-separated IDs of articles already displayed to
// avoid duplicates within the same session (optional).
$excludeRaw = $_GET['exclude'] ?? '';
$excludeIds = [];
if ($excludeRaw !== '') {
    foreach (explode(',', $excludeRaw) as $eid) {
        $eid = (int)trim($eid);
        if ($eid > 0) {
            $excludeIds[] = $eid;
        }
    }
}

/* ── Load user interests ─────────────────────────────────────────────── */
try {
    $intStmt = $pdo->prepare(
        'SELECT category_id, tag, weight
         FROM   user_interests
         WHERE  user_id = :uid
         ORDER  BY weight DESC
         LIMIT  50'
    );
    $intStmt->execute([':uid' => $firebaseUid]);
    $interests = $intStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('personalized_feed interests load: ' . $e->getMessage());
    $interests = [];
}

/* ── Compute "personalized" portion (80% of feed) ───────────────────── */
$personalizedLimit  = (int)ceil($limit * 0.80);
$discoveryLimit     = $limit - $personalizedLimit;

/* ── Build interest-based query ─────────────────────────────────────── */
$categoryIds = [];
$tagStrings  = [];
$weightMap   = [];   // category_id => weight

foreach ($interests as $row) {
    if ($row['category_id'] !== null) {
        $cid = (int)$row['category_id'];
        $categoryIds[]     = $cid;
        $weightMap[$cid]   = (float)$row['weight'];
    }
    if ($row['tag'] !== null && $row['tag'] !== '') {
        $tagStrings[] = $row['tag'];
    }
}

// Build CASE expression for category weight scoring
$categoryWeightExpr = '0';
if (!empty($categoryIds)) {
    $cases = [];
    foreach ($categoryIds as $cid) {
        $w       = number_format((float)($weightMap[$cid] ?? 1.0), 2, '.', '');
        $cases[] = "WHEN n.category_id = {$cid} THEN {$w}";
    }
    $categoryWeightExpr = 'CASE ' . implode(' ', $cases) . ' ELSE 0 END';
}

// Exclude already-read articles + client-provided exclude list
$alreadyRead = [];
try {
    $readStmt = $pdo->prepare(
        'SELECT news_id FROM user_read_history WHERE user_id = :uid'
    );
    $readStmt->execute([':uid' => $firebaseUid]);
    $alreadyRead = $readStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Table may not exist on first deploy — non-fatal
    error_log('personalized_feed read_history: ' . $e->getMessage());
}

$allExclude = array_unique(array_merge(
    array_map('intval', $alreadyRead),
    $excludeIds
));

$excludeSql = '';
$excludeParams = [];
if (!empty($allExclude)) {
    $placeholders = implode(',', array_fill(0, count($allExclude), '?'));
    $excludeSql   = "AND n.id NOT IN ({$placeholders})";
    $excludeParams = $allExclude;
}

// Category filter clause
$catFilterSql = '';
$catFilterParams = [];
if (!empty($categoryIds)) {
    $placeholders    = implode(',', array_fill(0, count($categoryIds), '?'));
    $catFilterSql    = "AND n.category_id IN ({$placeholders})";
    $catFilterParams = $categoryIds;
}

/* ─── Personalized articles ──────────────────────────────────────────── */
$personalizedRows = [];

if (!empty($categoryIds) || !empty($tagStrings)) {
    try {
        // Recency factor: exponential decay with 72-hour half-life
        // weight_score * EXP(-age_hours / 72) + viral_bonus
        $personalizedSql = "
            SELECT n.id, n.title, n.slug, n.featured_image,
                   n.content, n.created_at, n.is_breaking,
                   COALESCE(n.views, 0)       AS views,
                   COALESCE(n.viral_score, 0) AS viral_score,
                   COALESCE(n.is_trending, 0) AS is_trending,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   (
                       ({$categoryWeightExpr})
                       * EXP(-TIMESTAMPDIFF(HOUR, n.created_at, NOW()) / 72.0)
                       + COALESCE(n.viral_score, 0) * 0.05
                   ) AS rank_score
            FROM   news n
            LEFT   JOIN categories c ON c.id = n.category_id
            WHERE  n.status = 'approved'
            {$excludeSql}
            {$catFilterSql}
            ORDER  BY rank_score DESC, n.created_at DESC
            LIMIT  ? OFFSET ?
        ";

        $personalizedParams = array_merge(
            $excludeParams,
            $catFilterParams,
            [$personalizedLimit, $offset]
        );

        $stmt = $pdo->prepare($personalizedSql);
        $stmt->execute($personalizedParams);
        $personalizedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('personalized_feed query: ' . $e->getMessage());
    }
}

/* ─── Discovery articles (20% — not from user's categories) ──────────── */
$discoveryRows = [];
if ($discoveryLimit > 0) {
    try {
        $discoveredIds = array_column($personalizedRows, 'id');
        $allDiscoveryExclude = array_unique(array_merge($allExclude, array_map('intval', $discoveredIds)));
        $disExcludeSql    = '';
        $disExcludeParams = [];
        if (!empty($allDiscoveryExclude)) {
            $pl              = implode(',', array_fill(0, count($allDiscoveryExclude), '?'));
            $disExcludeSql   = "AND n.id NOT IN ({$pl})";
            $disExcludeParams = $allDiscoveryExclude;
        }

        $disCatExcludeSql    = '';
        $disCatExcludeParams = [];
        if (!empty($categoryIds)) {
            $pl                  = implode(',', array_fill(0, count($categoryIds), '?'));
            $disCatExcludeSql    = "AND n.category_id NOT IN ({$pl})";
            $disCatExcludeParams = $categoryIds;
        }

        $discoverySql = "
            SELECT n.id, n.title, n.slug, n.featured_image,
                   n.content, n.created_at, n.is_breaking,
                   COALESCE(n.views, 0)       AS views,
                   COALESCE(n.viral_score, 0) AS viral_score,
                   COALESCE(n.is_trending, 0) AS is_trending,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   0 AS rank_score
            FROM   news n
            LEFT   JOIN categories c ON c.id = n.category_id
            WHERE  n.status = 'approved'
            {$disExcludeSql}
            {$disCatExcludeSql}
            ORDER  BY n.viral_score DESC, n.created_at DESC
            LIMIT  ?
        ";

        $discoveryParams = array_merge(
            $disExcludeParams,
            $disCatExcludeParams,
            [$discoveryLimit]
        );

        $dStmt = $pdo->prepare($discoverySql);
        $dStmt->execute($discoveryParams);
        $discoveryRows = $dStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('personalized_feed discovery: ' . $e->getMessage());
    }
}

/* ─── Fallback: no interests set — return viral feed ─────────────────── */
if (empty($personalizedRows) && empty($discoveryRows)) {
    try {
        $fbExcludeSql    = '';
        $fbExcludeParams = [];
        if (!empty($allExclude)) {
            $pl              = implode(',', array_fill(0, count($allExclude), '?'));
            $fbExcludeSql    = "AND n.id NOT IN ({$pl})";
            $fbExcludeParams = $allExclude;
        }

        $fallbackSql = "
            SELECT n.id, n.title, n.slug, n.featured_image,
                   n.content, n.created_at, n.is_breaking,
                   COALESCE(n.views, 0)       AS views,
                   COALESCE(n.viral_score, 0) AS viral_score,
                   COALESCE(n.is_trending, 0) AS is_trending,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   0 AS rank_score
            FROM   news n
            LEFT   JOIN categories c ON c.id = n.category_id
            WHERE  n.status = 'approved'
            {$fbExcludeSql}
            ORDER  BY n.viral_score DESC, n.created_at DESC
            LIMIT  ? OFFSET ?
        ";

        $fbStmt = $pdo->prepare($fallbackSql);
        $fbStmt->execute(array_merge($fbExcludeParams, [$limit, $offset]));
        $personalizedRows = $fbStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('personalized_feed fallback: ' . $e->getMessage());
    }
}

/* ── Merge and format output ─────────────────────────────────────────── */
$allRows = array_merge($personalizedRows, $discoveryRows);

foreach ($allRows as &$row) {
    $row['id']           = (int)$row['id'];
    $row['views']        = (int)$row['views'];
    $row['viral_score']  = (float)$row['viral_score'];
    $row['rank_score']   = (float)$row['rank_score'];
    $row['is_trending']  = (bool)$row['is_trending'];
    $row['is_breaking']  = (bool)$row['is_breaking'];
    $row['featured_image'] = !empty($row['featured_image'])
        ? UPLOADS_URL . rawurlencode($row['featured_image'])
        : null;
}
unset($row);

$hasMore = (count($allRows) >= $limit);

echo json_encode([
    'success'  => true,
    'page'     => $page,
    'limit'    => $limit,
    'has_more' => $hasMore,
    'news'     => array_values($allRows),
]);
