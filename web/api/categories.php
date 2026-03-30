<?php
/**
 * web/api/categories.php
 * GET → Returns all published categories as JSON for the Flutter app.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->query(
        'SELECT id, name, slug FROM categories ORDER BY name ASC'
    );
    echo json_encode($stmt->fetchAll());
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([]);
}
