<?php
/**
 * web/api/ar/nearby_markers.php
 * Public API — AR news markers near a given location.
 *
 * GET ?lat=26.8&lng=80.9&radius_m=500
 *
 * Response:
 * {
 *   "success": true,
 *   "markers": [{
 *     "id": 1, "article_id": 42, "lat": 26.8001, "lng": 80.9012,
 *     "type": "news", "title": "...", "thumbnail_url": "...",
 *     "expires_at": "2025-01-16T18:00:00Z"
 *   }]
 * }
 *
 * Cache: Redis 5 minutes.
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

$lat      = filter_var($_GET['lat'] ?? '', FILTER_VALIDATE_FLOAT);
$lng      = filter_var($_GET['lng'] ?? '', FILTER_VALIDATE_FLOAT);
$radius_m = max(100, min(5000, (int)($_GET['radius_m'] ?? 500)));

if ($lat === false || $lng === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'lat and lng are required']);
    exit;
}

$cache_key = sprintf('ar:markers:%.4f:%.4f:%d', $lat, $lng, $radius_m);
$cache     = ApiCache::getInstance();

$markers = $cache->remember($cache_key, 300, function () use ($pdo, $lat, $lng, $radius_m): array {
    // Haversine bounding box pre-filter (approx 1° ≈ 111km)
    $deg_offset = $radius_m / 111000;

    $stmt = $pdo->prepare(
        'SELECT id, article_id, latitude, longitude, marker_type, title, thumbnail_url, expires_at,
                (6371000 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(latitude)) *
                    COS(RADIANS(longitude) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
                )) AS distance_m
         FROM ar_news_markers
         WHERE
           latitude  BETWEEN :lat_min  AND :lat_max AND
           longitude BETWEEN :lng_min  AND :lng_max AND
           (expires_at IS NULL OR expires_at > NOW())
         HAVING distance_m <= :radius
         ORDER BY distance_m ASC
         LIMIT 30'
    );
    $stmt->execute([
        ':lat'     => $lat,
        ':lat2'    => $lat,
        ':lng'     => $lng,
        ':lat_min' => $lat - $deg_offset,
        ':lat_max' => $lat + $deg_offset,
        ':lng_min' => $lng - $deg_offset,
        ':lng_max' => $lng + $deg_offset,
        ':radius'  => $radius_m,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$result = array_map(function (array $m): array {
    return [
        'id'            => (int)$m['id'],
        'article_id'    => (int)$m['article_id'],
        'lat'           => (float)$m['latitude'],
        'lng'           => (float)$m['longitude'],
        'type'          => $m['marker_type'],
        'title'         => $m['title'],
        'thumbnail_url' => $m['thumbnail_url'],
        'expires_at'    => $m['expires_at'],
        'distance_m'    => isset($m['distance_m']) ? (int)round((float)$m['distance_m']) : null,
    ];
}, $markers);

echo json_encode(['success' => true, 'markers' => $result, 'radius_m' => $radius_m]);
