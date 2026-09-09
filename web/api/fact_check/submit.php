<?php
/**
 * web/api/fact_check/submit.php
 * Admin/moderator API — submit a fact-check verdict for an article.
 *
 * POST /web/api/fact_check/submit.php
 * Authorization: Bearer <firebase_id_token>
 *
 * JSON body:
 *   article_id  — INT (required)
 *   verdict     — 'true'|'false'|'misleading'|'unverified'|'satire'
 *   explanation — string (required)
 *   sources     — array of URLs (optional)
 *
 * Response: { success, fact_check_id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders(['POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Auth + role check ─────────────────────────────────────────────────────────
$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$user  = requireAppUser($pdo, $token);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

if (!in_array($user['role'] ?? '', ['admin', 'super_admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin or moderator role required']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$article_id  = (int)($body['article_id'] ?? 0);
$verdict     = $body['verdict'] ?? '';
$explanation = trim($body['explanation'] ?? '');
$sources_raw = $body['sources'] ?? [];

$valid_verdicts = ['true', 'false', 'misleading', 'unverified', 'satire'];

if ($article_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'article_id required']);
    exit;
}
if (!in_array($verdict, $valid_verdicts, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid verdict value']);
    exit;
}
if ($explanation === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'explanation required']);
    exit;
}

// Sanitise sources list (must be strings)
$sources = array_values(array_filter(
    array_map('strval', is_array($sources_raw) ? $sources_raw : []),
    fn($s) => $s !== ''
));

// ── Verify article exists ─────────────────────────────────────────────────────
$art_stmt = $pdo->prepare('SELECT id, reporter_id FROM news WHERE id = ?');
$art_stmt->execute([$article_id]);
$article = $art_stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Article not found']);
    exit;
}

// ── Insert / update fact check ────────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO fact_checks (article_id, checked_by, verdict, explanation, sources)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       checked_by=VALUES(checked_by),
       verdict=VALUES(verdict),
       explanation=VALUES(explanation),
       sources=VALUES(sources),
       checked_at=NOW()'
)->execute([
    $article_id,
    $user['firebase_uid'],
    $verdict,
    $explanation,
    json_encode($sources),
]);

$fact_check_id = (int)$pdo->lastInsertId() ?: (int)$pdo->query(
    "SELECT id FROM fact_checks WHERE article_id = {$article_id}"
)->fetchColumn();

// ── Update news table ─────────────────────────────────────────────────────────
$new_status = null;
if ($verdict === 'false') {
    $new_status = 'fact_checked_false';
}

if ($new_status) {
    $pdo->prepare(
        'UPDATE news SET fact_check_verdict = ?, fact_check_id = ?, status = ? WHERE id = ?'
    )->execute([$verdict, $fact_check_id, $new_status, $article_id]);
} else {
    $pdo->prepare(
        'UPDATE news SET fact_check_verdict = ?, fact_check_id = ? WHERE id = ?'
    )->execute([$verdict, $fact_check_id, $article_id]);
}

// ── Increment fake-news strikes for reporter if verdict is 'false' ────────────
if ($verdict === 'false' && !empty($article['reporter_id'])) {
    $pdo->prepare(
        'UPDATE reporter_scores
         SET fake_news_strikes = fake_news_strikes + 1
         WHERE user_id = ?'
    )->execute([$article['reporter_id']]);
}

echo json_encode([
    'success'       => true,
    'fact_check_id' => $fact_check_id,
    'verdict'       => $verdict,
]);
