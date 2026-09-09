<?php
/**
 * web/api/complaints/update_status.php
 * Enhanced Public Voice — Admin status update
 *
 * POST (JSON body)
 * Headers: Authorization: Bearer <firebase_id_token>  (must be admin role)
 * Body:
 *   complaint_id  int     (required)
 *   new_status    string  (required: pending|acknowledged|in_progress|resolved|rejected)
 *   update_note   string  (optional)
 *   notify_user   bool    (optional, default true)
 *   rejection_reason string (required when new_status=rejected)
 *
 * Response: { success, complaint_id, new_status, message }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../../helpers/cors.php';
corsHeaders();
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

// ── Auth — require admin ───────────────────────────────────────────────────
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
$adminUid = $payload['sub'];

// Verify the caller is an admin in the users table
try {
    $adminStmt = $pdo->prepare(
        "SELECT id FROM users WHERE firebase_uid = :uid AND role IN ('admin','super_admin') LIMIT 1"
    );
    $adminStmt->execute([':uid' => $adminUid]);
    if (!$adminStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// ── Input ──────────────────────────────────────────────────────────────────
$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$complaintId = isset($input['complaint_id']) ? (int)$input['complaint_id'] : 0;
$newStatus   = trim($input['new_status'] ?? '');
$updateNote  = mb_substr(trim($input['update_note'] ?? ''), 0, 2000) ?: null;
$notifyUser  = isset($input['notify_user']) ? (bool)$input['notify_user'] : true;
$rejReason   = mb_substr(trim($input['rejection_reason'] ?? ''), 0, 500) ?: null;

$allowedStatuses = ['pending', 'acknowledged', 'in_progress', 'resolved', 'rejected'];

if ($complaintId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'complaint_id required']);
    exit;
}
if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false,
        'message' => 'new_status must be one of: ' . implode(', ', $allowedStatuses)]);
    exit;
}
if ($newStatus === 'rejected' && empty($rejReason)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'rejection_reason required when rejecting']);
    exit;
}

try {
    // Fetch current status
    $curStmt = $pdo->prepare(
        'SELECT status, user_id, title FROM complaints WHERE id = :id LIMIT 1'
    );
    $curStmt->execute([':id' => $complaintId]);
    $current = $curStmt->fetch();

    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Complaint not found']);
        exit;
    }

    $oldStatus = $current['status'];
    if ($oldStatus === $newStatus) {
        echo json_encode(['success' => true, 'complaint_id' => $complaintId,
            'new_status' => $newStatus, 'message' => 'Status unchanged']);
        exit;
    }

    // Build UPDATE fields
    $setClauses = ['status = :new_status'];
    $updateParams = [':new_status' => $newStatus, ':id' => $complaintId];

    if ($newStatus === 'resolved') {
        $setClauses[] = 'resolved_at = NOW()';
    }
    if ($rejReason !== null) {
        $setClauses[]                        = 'rejection_reason = :rej';
        $updateParams[':rej']                = $rejReason;
    }
    if ($updateNote !== null) {
        $setClauses[]                        = 'admin_response = :note';
        $updateParams[':note']               = $updateNote;
    }

    $pdo->prepare(
        'UPDATE complaints SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
    )->execute($updateParams);

    // Log status change in timeline
    $pdo->prepare(
        'INSERT INTO complaint_updates
         (complaint_id, old_status, new_status, update_note, updated_by)
         VALUES (:cid, :old, :new, :note, :by)'
    )->execute([
        ':cid'  => $complaintId,
        ':old'  => $oldStatus,
        ':new'  => $newStatus,
        ':note' => $updateNote,
        ':by'   => $adminUid,
    ]);

    // Optionally add an official comment
    if ($updateNote) {
        $pdo->prepare(
            'INSERT INTO complaint_comments
             (complaint_id, user_id, comment, is_official)
             VALUES (:cid, :uid, :comment, 1)'
        )->execute([
            ':cid'     => $complaintId,
            ':uid'     => $adminUid,
            ':comment' => $updateNote,
        ]);
        $pdo->prepare(
            'UPDATE complaints SET comments_count = comments_count + 1 WHERE id = :id'
        )->execute([':id' => $complaintId]);
    }

    echo json_encode([
        'success'      => true,
        'complaint_id' => $complaintId,
        'old_status'   => $oldStatus,
        'new_status'   => $newStatus,
        'message'      => 'Status updated successfully.',
    ]);

} catch (PDOException $e) {
    error_log('complaints/update_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
