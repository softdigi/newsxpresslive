<?php
/**
 * web/api/categories.php
 * GET → Returns all published categories as JSON for the Flutter app.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders();

require_once __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->query(
        'SELECT id, name, slug, emoji, color_hex, is_mood_category, sort_order
         FROM categories
         ORDER BY sort_order ASC, name ASC'
    );
    echo json_encode($stmt->fetchAll());
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([]);
}
