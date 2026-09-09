<?php
/**
 * web/api/leaderboard.php
 * NewsXpressLive — Reporter Credibility Leaderboard API
 *
 * GET /web/api/leaderboard.php
 *   ?type=weekly|all_time   (default: weekly)
 *   ?category=all|<slug>    (default: all)
 *   ?page=1&per_page=10
 *
 * GET /web/api/leaderboard.php?action=my_rank
 *   Requires: Authorization: Bearer <firebase_id_token>
 *   Returns reporter's own rank + score details
 *
 * Responses:
 *   { success, type, category, page, per_page, total, reporters: [...] }
 *   { success, type, rank, score, credibility_score, weekly_score, ... }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/cors.php';
require_once __DIR__ . '/../../helpers/security_headers.php';
require_once __DIR__ . '/../../web/includes/config.php';
require_once __DIR__ . '/../../helpers/credibility_score.php';

corsHeaders();
setSecurityHeaders('api');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action   = $_GET['action']   ?? 'leaderboard';
$type     = in_array($_GET['type'] ?? '', ['weekly', 'all_time'], true)
            ? $_GET['type'] : 'weekly';
$category = preg_replace('/[^a-z0-9_-]/i', '', $_GET['category'] ?? 'all') ?: 'all';
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(50, max(1, (int)($_GET['per_page'] ?? 10)));
$offset   = ($page - 1) * $perPage;

// ── My Rank (auth required) ──────────────────────────────────────────────────
if ($action === 'my_rank') {
    require_once __DIR__ . '/../../helpers/firebase_rtdb.php';
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    $uid   = verifyFirebaseToken($token);
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorised']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE firebase_uid = :uid");
    $stmt->execute([':uid' => $uid]);
    $userId = (int)$stmt->fetchColumn();
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    $rank = CredibilityScoreService::getReporterRank($pdo, $userId, $type);
    echo json_encode(array_merge(['success' => true, 'type' => $type], $rank));
    exit;
}

// ── Public Leaderboard ───────────────────────────────────────────────────────
$reporters = CredibilityScoreService::getLeaderboard($pdo, $type, $category, $perPage, $offset);

// Total count for pagination
$countStmt = $pdo->query(
    "SELECT COUNT(*) FROM reporter_scores rs
     JOIN users u ON u.id = rs.user_id
     WHERE u.role = 'reporter' AND u.status = 'active'"
);
$total = (int)$countStmt->fetchColumn();

echo json_encode([
    'success'   => true,
    'type'      => $type,
    'category'  => $category,
    'page'      => $page,
    'per_page'  => $perPage,
    'total'     => $total,
    'reporters' => $reporters,
]);
