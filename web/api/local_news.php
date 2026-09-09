<?php
/**
 * Local News API
 * NewsXpressLive
 *
 * Returns news articles near the user's GPS position.
 *
 * Query params:
 *   lat        float   Latitude   (-90 … 90)
 *   lng        float   Longitude  (-180 … 180)
 *   radius_km  float   Search radius in km (default 100, max 500)
 *   limit      int     Max articles to return (default 10, max 30)
 *
 * Matching strategy (first one that yields results wins):
 *   1. Haversine on districts table → news.district_id IN (matched)
 *   2. Haversine on states   table → news.state_id    IN (matched)
 *   3. Fallback: most-recent approved news (no location filter)
 *
 * Response JSON: array of article objects, each with:
 *   id, title, slug, featured_image, content, created_at,
 *   category_name, match_type, location_name
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

/* ── Input validation ──────────────────────────────────────────────────── */
$lat      = (float)($_GET['lat']       ?? 0);
$lng      = (float)($_GET['lng']       ?? 0);
$radiusKm = min(500.0, max(1.0, (float)($_GET['radius_km'] ?? 100)));
$limit    = min(30,    max(1,   (int)  ($_GET['limit']     ?? 10)));

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode([]);
    exit;
}

/* ── Haversine SQL fragment ────────────────────────────────────────────── *
 * Returns distance in km between (lat, lng) and a table row's (lat, lng). *
 * Uses the spherical law of cosines (accurate within ~0.3 % for d > 1 km).*
 * The table must expose columns named `lat` and `lng`.                    */
$haversineExpr = '(6371 * acos(
    cos(radians(:h_lat)) * cos(radians(lat)) *
    cos(radians(lng) - radians(:h_lng)) +
    sin(radians(:h_lat)) * sin(radians(lat))
))';

/* ── Helper: fetch news rows by a set of location IDs ─────────────────── */
function fetchNewsByLocation(
    PDO    $pdo,
    string $column,    // 'district_id' | 'state_id'
    array  $ids,
    int    $limit
): array {
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                c.name AS category_name
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = 'approved'
           AND n.{$column} IN ({$placeholders})
         ORDER BY n.created_at DESC
         LIMIT " . (int)$limit
    );
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/* ── Helper: format result rows ───────────────────────────────────────── */
function formatRows(array $rows, string $matchType, string $locationName): array
{
    foreach ($rows as &$item) {
        $item['created_at']    = formatDate($item['created_at']);
        $item['title']         = htmlspecialchars($item['title']         ?? '', ENT_QUOTES, 'UTF-8');
        $item['category_name'] = htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $item['match_type']    = $matchType;
        $item['location_name'] = $locationName;
    }
    return $rows;
}

/* ── Main logic ────────────────────────────────────────────────────────── */
try {
    /* Strategy 1 — district-level Haversine ─────────────────────────── */
    try {
        $distStmt = $pdo->prepare(
            "SELECT id, name, {$haversineExpr} AS distance_km
             FROM districts
             WHERE lat IS NOT NULL AND lng IS NOT NULL
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC
             LIMIT 10"
        );
        $distStmt->execute([
            ':h_lat'  => $lat,
            ':h_lng'  => $lng,
            ':radius' => $radiusKm,
        ]);
        $nearbyDistricts = $distStmt->fetchAll();

        if (!empty($nearbyDistricts)) {
            $ids          = array_column($nearbyDistricts, 'id');
            $locationName = $nearbyDistricts[0]['name'];
            $news         = fetchNewsByLocation($pdo, 'district_id', $ids, $limit);

            if (!empty($news)) {
                echo json_encode(formatRows($news, 'district', $locationName));
                exit;
            }
        }
    } catch (PDOException $e) {
        // districts table or lat/lng columns may not exist — continue to next strategy
        error_log('local_news district strategy: ' . $e->getMessage());
    }

    /* Strategy 2 — state-level Haversine ────────────────────────────── */
    // Use a wider radius so a state centroid (often hundreds of km away) still matches.
    $stateRadius = max($radiusKm, 300.0);
    try {
        $stateStmt = $pdo->prepare(
            "SELECT id, name, {$haversineExpr} AS distance_km
             FROM states
             WHERE lat IS NOT NULL AND lng IS NOT NULL
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC
             LIMIT 5"
        );
        $stateStmt->execute([
            ':h_lat'  => $lat,
            ':h_lng'  => $lng,
            ':radius' => $stateRadius,
        ]);
        $nearbyStates = $stateStmt->fetchAll();

        if (!empty($nearbyStates)) {
            $ids          = array_column($nearbyStates, 'id');
            $locationName = $nearbyStates[0]['name'];
            $news         = fetchNewsByLocation($pdo, 'state_id', $ids, $limit);

            if (!empty($news)) {
                echo json_encode(formatRows($news, 'state', $locationName));
                exit;
            }
        }
    } catch (PDOException $e) {
        // states table or lat/lng columns may not exist — continue to fallback
        error_log('local_news state strategy: ' . $e->getMessage());
    }

    /* Strategy 3 — Fallback: most-recent approved news ─────────────── */
    $fallbackStmt = $pdo->prepare(
        'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                c.name AS category_name
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = :status
         ORDER BY n.created_at DESC
         LIMIT :lim'
    );
    $fallbackStmt->bindValue(':status', 'approved');
    $fallbackStmt->bindValue(':lim',    $limit, PDO::PARAM_INT);
    $fallbackStmt->execute();
    $news = $fallbackStmt->fetchAll();

    echo json_encode(formatRows($news, 'fallback', ''));

} catch (PDOException $e) {
    error_log('Local news error: ' . $e->getMessage());
    echo json_encode([]);
}
