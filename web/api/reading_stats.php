<?php
/**
 * Reading Statistics API
 * NewsXpressLive
 * 
 * Tracks when users complete reading an article (80%+ scroll)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$articleId = (int)($input['article_id'] ?? 0);

if ($articleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid article ID']);
    exit;
}

try {
    // Increment reads_completed counter
    $stmt = $pdo->prepare(
        'UPDATE news SET reads_completed = COALESCE(reads_completed, 0) + 1 WHERE id = :id'
    );
    $stmt->execute([':id' => $articleId]);
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    // Column might not exist
    if (strpos($e->getMessage(), 'reads_completed') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $pdo->exec('ALTER TABLE news ADD COLUMN reads_completed INT DEFAULT 0');
            
            // Retry
            $stmt = $pdo->prepare(
                'UPDATE news SET reads_completed = COALESCE(reads_completed, 0) + 1 WHERE id = :id'
            );
            $stmt->execute([':id' => $articleId]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e2) {
            error_log('Reading stats error: ' . $e2->getMessage());
            echo json_encode(['success' => false]);
        }
    } else {
        error_log('Reading stats error: ' . $e->getMessage());
        echo json_encode(['success' => false]);
    }
}
