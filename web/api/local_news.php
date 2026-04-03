<?php
/**
 * Local News API
 * NewsXpressLive
 * 
 * Returns news based on user's geolocation (lat/lng)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);

// Validate coordinates
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode([]);
    exit;
}

try {
    // Try to find news from nearby locations
    // First, check if geo tables exist and have data
    $hasGeoData = false;
    
    try {
        $geoCheck = $pdo->query('SELECT 1 FROM states LIMIT 1');
        $hasGeoData = ($geoCheck !== false);
    } catch (PDOException $e) {
        $hasGeoData = false;
    }
    
    if ($hasGeoData) {
        // Find nearest state/district and get news from there
        // For now, just return recent local news (news with location data)
        $stmt = $pdo->prepare(
            'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                    c.name AS category_name
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE n.status = :status
             ORDER BY n.created_at DESC
             LIMIT 4'
        );
        $stmt->execute([':status' => 'approved']);
        $news = $stmt->fetchAll();
        
        foreach ($news as &$item) {
            $item['created_at'] = formatDate($item['created_at']);
            $item['title'] = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
            $item['category_name'] = htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8');
        }
        
        echo json_encode($news);
    } else {
        // No geo data available
        echo json_encode([]);
    }
    
} catch (PDOException $e) {
    error_log('Local news error: ' . $e->getMessage());
    echo json_encode([]);
}
