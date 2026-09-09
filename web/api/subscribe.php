<?php
/**
 * Newsletter Subscription API
 * NewsXpressLive
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

// Handle both JSON and form data
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? ($_POST['email'] ?? ''));

// Validate email
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Rate limiting: check if same IP subscribed in last hour
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');

try {
    // Check rate limit
    $rateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM subscribers 
         WHERE ip_hash = :ip AND subscribed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $rateStmt->execute([':ip' => $ipHash]);
    $recentCount = (int)$rateStmt->fetchColumn();
    
    if ($recentCount > 0) {
        echo json_encode(['success' => false, 'message' => 'You have already subscribed recently. Please try again later.']);
        exit;
    }
} catch (PDOException $e) {
    // Table might not exist yet, continue
}

try {
    // Check if email already exists
    $checkStmt = $pdo->prepare('SELECT id, is_active FROM subscribers WHERE email = :email LIMIT 1');
    $checkStmt->execute([':email' => $email]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        if ($existing['is_active']) {
            echo json_encode(['success' => false, 'message' => 'This email is already subscribed!']);
        } else {
            // Reactivate subscription
            $updateStmt = $pdo->prepare('UPDATE subscribers SET is_active = 1, subscribed_at = NOW() WHERE id = :id');
            $updateStmt->execute([':id' => $existing['id']]);
            echo json_encode(['success' => true, 'message' => 'Welcome back! Your subscription has been reactivated.']);
        }
        exit;
    }
    
    // Insert new subscriber
    $insertStmt = $pdo->prepare(
        'INSERT INTO subscribers (email, subscribed_at, ip_hash, is_active) 
         VALUES (:email, NOW(), :ip, 1)'
    );
    $insertStmt->execute([
        ':email' => $email,
        ':ip'    => $ipHash
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Thank you for subscribing! You\'ll receive our latest news updates.']);
    
} catch (PDOException $e) {
    // Table might not exist - try to create it
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS subscribers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    ip_hash VARCHAR(64),
                    is_active TINYINT(1) DEFAULT 1,
                    INDEX idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Retry insert
            $insertStmt = $pdo->prepare(
                'INSERT INTO subscribers (email, subscribed_at, ip_hash, is_active) 
                 VALUES (:email, NOW(), :ip, 1)'
            );
            $insertStmt->execute([
                ':email' => $email,
                ':ip'    => $ipHash
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Thank you for subscribing!']);
        } catch (PDOException $e2) {
            error_log('Newsletter subscription error: ' . $e2->getMessage());
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
        }
    } else {
        error_log('Newsletter subscription error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
    }
}
