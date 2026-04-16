<?php
/**
 * web/api/bot/drafts.php
 * Admin-only API — AI Reporter Bot draft review queue.
 *
 * GET  — list bot-generated drafts pending review
 * POST — approve / reject / edit a draft
 *
 * POST body (JSON):
 * {
 *   "article_id": 123,
 *   "action": "approve" | "reject",
 *   "title":   "...",      // optional edit
 *   "content": "..."       // optional edit
 * }
 *
 * Auth: X-Admin-Token header must match ADMIN_API_TOKEN env var.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

// ── Admin authentication ──────────────────────────────────────────────────────
$admin_token = getenv('ADMIN_API_TOKEN');
$provided    = trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');

if (!$admin_token || !hash_equals($admin_token, $provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — list bot drafts
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset   = ($page - 1) * $per_page;

    $total_stmt = $pdo->query("SELECT COUNT(*) FROM news WHERE status = 'bot_draft'");
    $total      = (int)$total_stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.description, n.created_at,
                n.bot_source_name, n.bot_quality_score,
                c.name AS category_name
         FROM news n
         LEFT JOIN categories c ON c.id = n.category_id
         WHERE n.status = 'bot_draft'
         ORDER BY n.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$per_page, $offset]);
    $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'page'    => $page,
        'drafts'  => $drafts,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — approve / reject
// ─────────────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$article_id = filter_var($body['article_id'] ?? 0, FILTER_VALIDATE_INT);
$action     = trim($body['action'] ?? '');

if (!$article_id || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'article_id and action (approve|reject) required']);
    exit;
}

// Verify the article is a bot draft
$check = $pdo->prepare("SELECT id FROM news WHERE id = ? AND status = 'bot_draft'");
$check->execute([$article_id]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Draft not found']);
    exit;
}

if ($action === 'approve') {
    $new_title   = isset($body['title'])   ? trim($body['title'])   : null;
    $new_content = isset($body['content']) ? trim($body['content']) : null;

    $sets   = ["status = 'approved'", "approved_at = NOW()"];
    $params = [];

    if ($new_title !== null && $new_title !== '') {
        $sets[]   = 'title = ?';
        $params[] = $new_title;
    }
    if ($new_content !== null && $new_content !== '') {
        $sets[]   = 'description = ?';
        $params[] = $new_content;
    }
    $params[] = $article_id;

    $pdo->prepare('UPDATE news SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($params);

    echo json_encode(['success' => true, 'message' => 'Draft approved and published']);
} else {
    $pdo->prepare("UPDATE news SET status = 'rejected' WHERE id = ?")
        ->execute([$article_id]);

    echo json_encode(['success' => true, 'message' => 'Draft rejected']);
}
