<?php
/**
 * admin_panel/api/comment_action.php
 *
 * AJAX endpoint for the Spam Comments admin page.
 * Accepts a POST request with JSON body:
 *   { "comment_id": <int>, "action": "approve"|"delete" }
 *
 * Returns JSON: { "success": bool, "message": string }
 *
 * Protected: requires an active admin session.
 */
declare(strict_types=1);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin']['role']) ||
    !in_array($_SESSION['admin']['role'], ['super_admin', 'admin', 'editor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

$commentId = isset($input['comment_id']) ? (int) $input['comment_id'] : 0;
$action    = trim($input['action'] ?? '');

if ($commentId <= 0 || !in_array($action, ['approve', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// ── Execute ───────────────────────────────────────────────────────────────────
try {
    if ($action === 'approve') {
        $stmt = $pdo->prepare(
            "UPDATE comments SET status = 'approved' WHERE id = ? AND status = 'spam'"
        );
        $stmt->execute([$commentId]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Comment not found or already actioned']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Comment approved']);

    } else {
        // action === 'delete'
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? AND status = 'spam'");
        $stmt->execute([$commentId]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Comment not found or already actioned']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Comment deleted']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
