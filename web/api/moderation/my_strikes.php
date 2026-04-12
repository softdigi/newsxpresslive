<?php
/**
 * web/api/moderation/my_strikes.php
 *
 * Reporter API: GET /web/api/moderation/my_strikes.php
 * Requires: Authorization: Bearer <firebase_id_token>
 *
 * Returns reporter's active strikes with appeal status.
 *
 * POST ?action=appeal
 * Body: { "strike_id": 5, "reason": "The article was based on official press release..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../helpers/moderation_service.php';
require_once __DIR__ . '/../../../helpers/firebase_rtdb.php';

corsHeaders();
setSecurityHeaders('api');

$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$uid   = verifyFirebaseToken($token);
if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE firebase_uid=:uid");
$stmt->execute([':uid' => $uid]);
$userId = (int)$stmt->fetchColumn();
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$mod    = ModerationService::getInstance($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $strikes = $mod->getActiveStrikes($userId);
    echo json_encode(['success' => true, 'strikes' => $strikes, 'count' => count($strikes)]);
    exit;
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'appeal') {
        $strikeId = (int)($body['strike_id'] ?? 0);
        $reason   = trim($body['reason']     ?? '');
        if ($strikeId <= 0 || strlen($reason) < 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'strike_id and reason (min 20 chars) required']);
            exit;
        }
        $result = $mod->submitAppeal($strikeId, $userId, $reason);
        echo json_encode($result);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid request']);
