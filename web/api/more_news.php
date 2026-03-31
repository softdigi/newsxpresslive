<?php
/**
 * Load More News API
 * NewsXpressLive
 *
 * Returns paginated news items as JSON.
 * Supports optional ?category=slug filter for category feeds.
 *
 * Feed-boost mode (?sort=viral):
 *   Injects the top-viral articles (is_trending=1 or highest viral_score)
 *   into every page at a configurable rate (viral_feed_boost_pct setting,
 *   default 20 %). The remaining slots are filled chronologically.
 *   Example with per=10 and boost_pct=20: 2 viral slots + 8 latest slots.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = min(20, max(1, (int)($_GET['per'] ?? 9)));
$offset  = ($page - 1) * $per;
$catSlug = mb_substr(strip_tags(trim($_GET['category'] ?? '')), 0, 200, 'UTF-8');
$sort    = trim($_GET['sort'] ?? '');    // 'viral' enables feed-boost mode

// ── Build base WHERE clause ───────────────────────────────────────────
$where  = 'n.status = :status';
$params = [':status' => 'published'];

if ($catSlug !== '') {
    $where .= ' AND c.slug = :cat';
    $params[':cat'] = $catSlug;
}

// ── Viral feed-boost mode ─────────────────────────────────────────────
if ($sort === 'viral') {
    // Read boost percentage from settings (default 20 %)
    $boostPct = 20;
    try {
        $bRow = $pdo->prepare(
            "SELECT setting_value FROM settings WHERE setting_key = 'viral_feed_boost_pct' LIMIT 1"
        );
        $bRow->execute();
        $bVal = $bRow->fetchColumn();
        if ($bVal !== false) {
            $boostPct = max(0, min(100, (int)$bVal));
        }
    } catch (PDOException $e) { /* use default */ }

    $viralSlots    = max(1, (int)round($per * $boostPct / 100));
    $regularSlots  = max(0, $per - $viralSlots);
    $regularOffset = ($page - 1) * $regularSlots;

    try {
        // Top-viral articles (ordered by viral_score, any time window)
        $viralStmt = $pdo->prepare(
            "SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                    n.created_at, n.is_breaking,
                    COALESCE(n.views, 0)       AS views,
                    COALESCE(n.viral_score, 0) AS viral_score,
                    COALESCE(n.is_trending, 0) AS is_trending,
                    c.name AS category_name, c.slug AS category_slug
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE {$where}
               AND COALESCE(n.viral_score, 0) > 0
             ORDER BY n.viral_score DESC, n.created_at DESC
             LIMIT :lim"
        );
        foreach ($params as $k => $v) {
            $viralStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $viralStmt->bindValue(':lim', $viralSlots, PDO::PARAM_INT);
        $viralStmt->execute();
        $viralNews = $viralStmt->fetchAll();

        $viralIds = array_column($viralNews, 'id');

        // Regular articles – exclude already-included viral ones
        $regularNews = [];
        if ($regularSlots > 0) {
            $excludeSql = '';
            if (!empty($viralIds)) {
                $placeholders = implode(',', array_fill(0, count($viralIds), '?'));
                $excludeSql   = " AND n.id NOT IN ({$placeholders})";
            }
            $regStmt = $pdo->prepare(
                "SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                        n.created_at, n.is_breaking,
                        COALESCE(n.views, 0)       AS views,
                        COALESCE(n.viral_score, 0) AS viral_score,
                        COALESCE(n.is_trending, 0) AS is_trending,
                        c.name AS category_name, c.slug AS category_slug
                 FROM news n
                 LEFT JOIN categories c ON c.id = n.category_id
                 WHERE {$where}{$excludeSql}
                 ORDER BY n.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $bindIdx = 1;
            foreach ($params as $v) {
                $regStmt->bindValue($bindIdx++, $v, PDO::PARAM_STR);
            }
            foreach ($viralIds as $vid) {
                $regStmt->bindValue($bindIdx++, $vid, PDO::PARAM_INT);
            }
            $regStmt->bindValue($bindIdx++, $regularSlots,  PDO::PARAM_INT);
            $regStmt->bindValue($bindIdx,   $regularOffset, PDO::PARAM_INT);
            $regStmt->execute();
            $regularNews = $regStmt->fetchAll();
        }

        // Merge: viral articles first, then regular
        $news = array_merge($viralNews, $regularNews);

    } catch (PDOException $e) {
        error_log('More news (viral) error: ' . $e->getMessage());
        $news = [];
    }

} else {
    // ── Standard chronological mode ───────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            "SELECT n.id, n.title, n.slug, n.featured_image, n.content,
                    n.created_at, n.is_breaking,
                    COALESCE(n.views, 0)       AS views,
                    COALESCE(n.viral_score, 0) AS viral_score,
                    COALESCE(n.is_trending, 0) AS is_trending,
                    c.name AS category_name, c.slug AS category_slug
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE $where
             ORDER BY n.created_at DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $per,    PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $news = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('More news error: ' . $e->getMessage());
        $news = [];
    }
}

foreach ($news as &$item) {
    $item['id']          = (int)$item['id'];
    $item['is_breaking'] = (bool)$item['is_breaking'];
    $item['views']       = (int)$item['views'];
    $item['viral_score'] = (float)$item['viral_score'];
    $item['is_trending'] = (bool)$item['is_trending'];
    // Return absolute URL for featured image
    $item['featured_image'] = !empty($item['featured_image'])
        ? UPLOADS_URL . rawurlencode($item['featured_image'])
        : null;
}

echo json_encode(array_values($news));

