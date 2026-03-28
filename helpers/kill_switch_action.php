<?php
// ============================================================
// FIXED: helpers/kill_switch_action.php
// CRITICAL BUGS:
//   1. $action not whitelisted for news type:
//      attacker (or compromised admin) can set kill_switch
//      to ANY value e.g. 'approved', 'none', 'active' etc.
//      Fixed: whitelist per type.
//
//   2. $action not whitelisted for user type:
//      Can set status='admin', status='reporter' — privilege
//      escalation via kill switch!
//      Fixed: only 'suspended','banned','active' allowed.
//
//   3. $action not whitelisted for platform type:
//      Can set platform_kill to anything.
//      Fixed: only 'on','off' allowed.
//
//   4. $id=0 allowed when type='platform' — but UPDATE uses
//      WHERE key='platform_kill' so id=0 is harmless there.
//      Still, added explicit check for news/user types.
//
//   5. Exception message exposed: sendResponse(false, null,
//      $e->getMessage()) — internal DB errors shown to client.
//
//   6. $reason not length-capped — 10MB reason string in
//      admin_actions table is possible.
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../geo/config.php';
require_once __DIR__ . '/../geo/response.php';

session_start();
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$admin_id) {
    sendResponse(false, null, 'Unauthorized', 401);
}

$input  = json_decode(file_get_contents('php://input'), true);
$type   = trim($input['type']   ?? '');
$id     = (int)($input['id']    ?? 0);
$action = trim($input['action'] ?? '');
$reason = mb_substr(trim($input['reason'] ?? ''), 0, 500, 'UTF-8'); // FIXED: cap length

// FIXED: whitelist type
$allowed_types = ['news', 'user', 'platform'];
if (!in_array($type, $allowed_types, true)) {
    sendResponse(false, null, 'Invalid type', 400);
}

// FIXED: whitelist action per type
$allowed_actions = [
    'news'     => ['killed', 'shadow_ban', 'none'],
    'user'     => ['suspended', 'banned', 'active'],
    'platform' => ['on', 'off'],
];

if (!in_array($action, $allowed_actions[$type], true)) {
    sendResponse(false, null, "Invalid action for type '{$type}'", 400);
}

if (!$reason) {
    sendResponse(false, null, 'reason is required', 400);
}

// FIXED: id required for news/user types
if (in_array($type, ['news', 'user'], true) && $id <= 0) {
    sendResponse(false, null, 'valid id required', 400);
}

$pdo->beginTransaction();

try {
    if ($type === 'news') {
        $pdo->prepare(
            "UPDATE news SET kill_switch = ? WHERE id = ?"
        )->execute([$action, $id]);
    }

    if ($type === 'user') {
        $pdo->prepare(
            "UPDATE users SET status = ? WHERE id = ?"
        )->execute([$action, $id]);
    }

    if ($type === 'platform') {
        $pdo->prepare(
            "UPDATE system_settings SET value = ? WHERE `key` = 'platform_kill'"
        )->execute([$action]);
    }

    // Audit log
    $pdo->prepare(
        "INSERT INTO admin_actions
            (admin_id, action_type, target_type, target_id, reason)
         VALUES (?, 'KILL_SWITCH', ?, ?, ?)"
    )->execute([$admin_id, $type, $id, $reason]);

    $pdo->commit();

    sendResponse(true, ['type' => $type, 'action' => $action]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('kill_switch_action error: ' . $e->getMessage());
    // FIXED: never expose $e->getMessage() to client
    sendResponse(false, null, 'Action failed', 500);
}
