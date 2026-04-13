<?php
/**
 * admin/strike.php
 *
 * Admin API: POST /admin/strike — issue a strike to a reporter
 *
 * POST body: {
 *   "reporter_id": 42,
 *   "article_id":  100,          // optional
 *   "reason":      "fake_news",
 *   "details":     "Fabricated quote from CM office"
 * }
 *
 * Response: { success, strike_id, strike_number, consequence }
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../helpers/security_headers.php';
require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/moderation_service.php';

corsHeaders();
setSecurityHeaders('admin');

$adminToken    = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
$expectedToken = getenv('ADMIN_API_TOKEN') ?: '';
if (empty($expectedToken) || !hash_equals($expectedToken, $adminToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$reporterId = (int)($body['reporter_id'] ?? 0);
$articleId  = isset($body['article_id']) ? (int)$body['article_id'] : null;
$reason     = $body['reason']  ?? '';
$details    = $body['details'] ?? '';

$validReasons = ['fake_news','misinformation','plagiarism','hate_speech','obscene_content','copyright_violation','spam','other'];
if ($reporterId <= 0 || !in_array($reason, $validReasons, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'reporter_id and valid reason are required']);
    exit;
}

// Resolve the issuing admin's ID.
// The endpoint is protected by ADMIN_API_TOKEN (shared secret), so there
// is no per-admin session here.  Callers MAY pass their own admin_users.id
// as "admin_id" in the request body to record who issued the strike.
// If omitted or invalid (≤0), fall back to 0 (system/unknown).
$adminId = max(0, (int)($body['admin_id'] ?? 0));

$mod    = ModerationService::getInstance($pdo);
$result = $mod->issueStrike($reporterId, $articleId, $reason, $details, $adminId);

echo json_encode($result);
