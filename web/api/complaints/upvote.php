<?php
/**
 * web/api/complaints/upvote.php
 * Enhanced Public Voice — Toggle upvote (Firebase auth based)
 *
 * POST (JSON body)
 * Headers: Authorization: Bearer <firebase_id_token>
 * Body: { "complaint_id": 123 }
 *
 * Response: { success, user_upvoted, upvotes_count }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// Auth
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}
if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}
$payload = verifyFirebaseToken($idToken);
if (!$payload || empty($payload['sub'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$userId = $payload['sub'];

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$complaintId = isset($input['complaint_id']) ? (int)$input['complaint_id'] : 0;

if ($complaintId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'complaint_id required']);
    exit;
}

try {
    // Check existing upvote
    $checkStmt = $pdo->prepare(
        'SELECT id FROM complaint_upvotes
          WHERE user_id = :uid AND complaint_id = :cid LIMIT 1'
    );
    $checkStmt->execute([':uid' => $userId, ':cid' => $complaintId]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        // Remove upvote (trigger will decrement counter)
        $pdo->prepare(
            'DELETE FROM complaint_upvotes WHERE user_id = :uid AND complaint_id = :cid'
        )->execute([':uid' => $userId, ':cid' => $complaintId]);
        $userUpvoted = false;
    } else {
        // Add upvote (trigger will increment counter and set viral flag)
        $pdo->prepare(
            'INSERT IGNORE INTO complaint_upvotes (user_id, complaint_id) VALUES (:uid, :cid)'
        )->execute([':uid' => $userId, ':cid' => $complaintId]);
        $userUpvoted = true;
    }

    // Fetch fresh count
    $cntStmt = $pdo->prepare(
        'SELECT upvotes_count, is_viral FROM complaints WHERE id = :id LIMIT 1'
    );
    $cntStmt->execute([':id' => $complaintId]);
    $cRow = $cntStmt->fetch();

    echo json_encode([
        'success'       => true,
        'user_upvoted'  => $userUpvoted,
        'upvotes_count' => (int)($cRow['upvotes_count'] ?? 0),
        'is_viral'      => (bool)($cRow['is_viral'] ?? false),
    ]);

} catch (PDOException $e) {
    error_log('complaints/upvote.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
