<?php
/**
 * web/api/complaints/comment.php
 * Enhanced Public Voice — Comments
 *
 * GET  ?complaint_id=123[&page=1&per_page=20]
 *   → paginated comments list (no auth required)
 *
 * POST (JSON body)
 * Headers: Authorization: Bearer <firebase_id_token>
 * Body: { "complaint_id": 123, "comment": "..." }
 *   → add a new comment
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

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — list comments ────────────────────────────────────────────────────
if ($method === 'GET') {
    $complaintId = isset($_GET['complaint_id']) && ctype_digit((string)$_GET['complaint_id'])
        ? (int)$_GET['complaint_id'] : 0;

    if ($complaintId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'complaint_id required']);
        exit;
    }

    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    try {
        $stmt = $pdo->prepare(
            "SELECT cc.id, cc.comment, cc.is_official, cc.created_at,
                    up.display_name AS user_name,
                    up.avatar_url   AS user_avatar,
                    up.is_verified  AS user_verified
               FROM complaint_comments cc
               LEFT JOIN user_profiles up ON up.firebase_uid = cc.user_id
              WHERE cc.complaint_id = :cid
              ORDER BY cc.created_at ASC
              LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':cid', $complaintId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage,     PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,      PDO::PARAM_INT);
        $stmt->execute();

        $comments = array_map(function (array $c): array {
            return [
                'id'            => (int)$c['id'],
                'comment'       => $c['comment'],
                'is_official'   => (bool)$c['is_official'],
                'created_at'    => $c['created_at'],
                'user_name'     => $c['user_name'],
                'user_avatar'   => $c['user_avatar'],
                'user_verified' => (bool)($c['user_verified'] ?? false),
            ];
        }, $stmt->fetchAll());

        echo json_encode([
            'success'     => true,
            'complaint_id'=> $complaintId,
            'page'        => $page,
            'per_page'    => $perPage,
            'has_more'    => count($comments) === $perPage,
            'comments'    => $comments,
        ]);
    } catch (PDOException $e) {
        error_log('complaints/comment.php GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// ── POST — add comment ─────────────────────────────────────────────────────
if ($method === 'POST') {
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
    $comment     = mb_substr(trim($input['comment'] ?? ''), 0, 2000);

    if ($complaintId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'complaint_id required']);
        exit;
    }
    if ($comment === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'comment required (max 2000 chars)']);
        exit;
    }

    try {
        // Insert comment
        $pdo->prepare(
            'INSERT INTO complaint_comments (complaint_id, user_id, comment)
             VALUES (:cid, :uid, :comment)'
        )->execute([':cid' => $complaintId, ':uid' => $userId, ':comment' => $comment]);

        // Increment comments_count
        $pdo->prepare(
            'UPDATE complaints SET comments_count = comments_count + 1 WHERE id = :id'
        )->execute([':id' => $complaintId]);

        $commentId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success'    => true,
            'comment_id' => $commentId,
            'message'    => 'Comment added.',
        ]);
    } catch (PDOException $e) {
        error_log('complaints/comment.php POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
