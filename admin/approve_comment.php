<?php
/**
 * admin/approve_comment.php
 *
 * API endpoint: approve a comment and push it live to Firebase RTDB
 * so connected Flutter/web clients receive it in real-time.
 *
 * POST body (JSON):
 *   admin_uid  string  — Firebase UID of the admin
 *   comment_id int     — ID of the comment to approve
 */

header('Content-Type: application/json');

require __DIR__ . '/../geo/config.php';
require_once __DIR__ . '/../auth/firebase.php';
require __DIR__ . '/../geo/response.php';
require_once __DIR__ . '/../helpers/firebase_rtdb.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], 'POST request required');
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['comment_id']) || empty($input['admin_uid'])) {
    jsonResponse(false, [], 'comment_id & admin_uid required');
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

$commentId = (int) $input['comment_id'];

// Load the comment before approving so we have its data for RTDB
$stmt = $pdo->prepare(
    'SELECT id, news_id, parent_id, author_name, content, created_at
     FROM comments WHERE id = ? LIMIT 1'
);
$stmt->execute([$commentId]);
$comment = $stmt->fetch();

if (!$comment) {
    jsonResponse(false, [], 'comment not found');
}

// Approve in the database
$upd = $pdo->prepare("UPDATE comments SET status = 'approved' WHERE id = ?");
$upd->execute([$commentId]);

// Push approved comment to RTDB — clients listening to this path will
// receive the new comment without polling.
// Path: /live/comments/{news_id}/{comment_id}
rtdbPut(
    '/live/comments/' . $comment['news_id'] . '/' . $commentId,
    [
        'id'          => (int) $comment['id'],
        'news_id'     => (int) $comment['news_id'],
        'parent_id'   => $comment['parent_id'] !== null ? (int) $comment['parent_id'] : null,
        'author_name' => $comment['author_name'],
        'content'     => $comment['content'],
        'created_at'  => $comment['created_at'],
    ]
);

jsonResponse(true, [], 'comment approved');
