<?php
/**
 * Web Push Subscription API
 * NewsXpressLive
 * 
 * Saves push notification subscriptions
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);

$endpoint  = $input['endpoint'] ?? '';
$p256dh    = $input['keys']['p256dh'] ?? '';
$auth      = $input['keys']['auth'] ?? '';

if (empty($endpoint)) {
    echo json_encode(['success' => false, 'message' => 'Invalid subscription data']);
    exit;
}

try {
    // Check if subscription already exists
    $checkStmt = $pdo->prepare('SELECT id FROM push_subscribers WHERE endpoint = :endpoint LIMIT 1');
    $checkStmt->execute([':endpoint' => $endpoint]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        // Update existing subscription
        $updateStmt = $pdo->prepare(
            'UPDATE push_subscribers SET p256dh_key = :p256dh, auth_key = :auth, is_active = 1 WHERE id = :id'
        );
        $updateStmt->execute([
            ':p256dh' => $p256dh,
            ':auth'   => $auth,
            ':id'     => $existing['id']
        ]);
    } else {
        // Insert new subscription
        $insertStmt = $pdo->prepare(
            'INSERT INTO push_subscribers (endpoint, p256dh_key, auth_key, subscribed_at, is_active)
             VALUES (:endpoint, :p256dh, :auth, NOW(), 1)'
        );
        $insertStmt->execute([
            ':endpoint' => $endpoint,
            ':p256dh'   => $p256dh,
            ':auth'     => $auth
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Subscribed successfully']);
    
} catch (PDOException $e) {
    // Table might not exist - try to create it
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS push_subscribers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    endpoint TEXT NOT NULL,
                    p256dh_key TEXT,
                    auth_key TEXT,
                    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    is_active TINYINT(1) DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Retry insert
            $insertStmt = $pdo->prepare(
                'INSERT INTO push_subscribers (endpoint, p256dh_key, auth_key, subscribed_at, is_active)
                 VALUES (:endpoint, :p256dh, :auth, NOW(), 1)'
            );
            $insertStmt->execute([
                ':endpoint' => $endpoint,
                ':p256dh'   => $p256dh,
                ':auth'     => $auth
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Subscribed successfully']);
        } catch (PDOException $e2) {
            error_log('Push subscribe error: ' . $e2->getMessage());
            echo json_encode(['success' => false, 'message' => 'Subscription failed']);
        }
    } else {
        error_log('Push subscribe error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Subscription failed']);
    }
}
