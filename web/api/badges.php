<?php
/**
 * web/api/badges.php
 * Reporter Badge API
 *
 * GET /web/api/badges.php
 *   Requires: Authorization: Bearer <firebase_id_token>
 *   Returns all badges with locked/unlocked status.
 *
 * GET /web/api/badges.php?action=profile_top&uid=<firebase_uid>
 *   Public — returns top 3 unlocked badges for profile page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/cors.php';
require_once __DIR__ . '/../../helpers/security_headers.php';
require_once __DIR__ . '/../../web/includes/config.php';
require_once __DIR__ . '/../../helpers/badge_service.php';
require_once __DIR__ . '/../../helpers/firebase_rtdb.php';

corsHeaders();
setSecurityHeaders('api');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? 'all';

// Public: top 3 badges for a profile
if ($action === 'profile_top') {
    $firebaseUid = $_GET['uid'] ?? '';
    if (!$firebaseUid) {
        echo json_encode(['success' => false, 'error' => 'uid required']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE firebase_uid=:uid");
    $stmt->execute([':uid' => $firebaseUid]);
    $userId = (int)$stmt->fetchColumn();
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    $badges = BadgeService::getReporterBadges($pdo, $userId);
    echo json_encode(['success' => true, 'badges' => array_slice($badges, 0, 3)]);
    exit;
}

// Auth required for full badge list
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

$badges = BadgeService::getAllBadgesWithStatus($pdo, $userId);
echo json_encode(['success' => true, 'badges' => $badges]);
