<?php
/**
 * web/api/complaint_vote.php
 *
 * POST (JSON body)  { complaint_id: int, firebase_uid?: string }
 *
 * Toggles a "support" vote on a complaint (1 vote per IP per day).
 *
 * Response: { success: bool, voted: bool, votes_count: int }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$complaintId = isset($input['complaint_id']) ? (int)$input['complaint_id'] : 0;
$firebaseUid = isset($input['firebase_uid'])
    ? mb_substr(trim($input['firebase_uid']), 0, 128)
    : null;

if ($complaintId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'complaint_id required']);
    exit;
}

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));

try {
    // Check if vote already exists
    $check = $pdo->prepare(
        'SELECT id FROM complaint_votes
         WHERE complaint_id = :cid AND ip_hash = :ip LIMIT 1'
    );
    $check->execute([':cid' => $complaintId, ':ip' => $ipHash]);
    $existing = $check->fetch();

    if ($existing) {
        // Remove vote
        $pdo->prepare(
            'DELETE FROM complaint_votes WHERE complaint_id = :cid AND ip_hash = :ip'
        )->execute([':cid' => $complaintId, ':ip' => $ipHash]);
        $pdo->prepare(
            'UPDATE complaints SET votes_count = GREATEST(0, votes_count - 1) WHERE id = :id'
        )->execute([':id' => $complaintId]);
        $voted = false;
    } else {
        // Add vote
        $pdo->prepare(
            'INSERT IGNORE INTO complaint_votes (complaint_id, ip_hash, firebase_uid)
             VALUES (:cid, :ip, :uid)'
        )->execute([':cid' => $complaintId, ':ip' => $ipHash, ':uid' => $firebaseUid]);
        $pdo->prepare(
            'UPDATE complaints SET votes_count = votes_count + 1 WHERE id = :id'
        )->execute([':id' => $complaintId]);
        $voted = true;
    }

    // Return fresh count
    $cntStmt = $pdo->prepare('SELECT votes_count FROM complaints WHERE id = :id LIMIT 1');
    $cntStmt->execute([':id' => $complaintId]);
    $cnt = (int)($cntStmt->fetchColumn() ?: 0);

    echo json_encode([
        'success'     => true,
        'voted'       => $voted,
        'votes_count' => $cnt,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
