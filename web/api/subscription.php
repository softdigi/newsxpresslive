<?php
/**
 * Subscription Status API
 * NewsXpressLive
 *
 * GET  /api/subscription.php         – Returns current user subscription status
 * POST /api/subscription.php         – Activate / upgrade subscription
 *   Body: { "plan": "monthly"|"yearly", "gateway": "...", "ref": "..." }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

$user = getCurrentUser($pdo);

/* ── GET: status check ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$user) {
        echo json_encode([
            'logged_in'    => false,
            'subscribed'   => false,
            'plan'         => null,
            'expires_at'   => null,
        ]);
        exit;
    }
    echo json_encode([
        'logged_in'    => true,
        'subscribed'   => isSubscribed($user),
        'plan'         => $user['subscription_plan'],
        'expires_at'   => $user['subscription_expires'],
        'status'       => $user['subscription_status'],
    ]);
    exit;
}

/* ── POST: activate subscription ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $input   = json_decode(file_get_contents('php://input'), true);
    $plan    = $input['plan']    ?? '';
    $gateway = $input['gateway'] ?? 'manual';
    $ref     = $input['ref']     ?? '';

    if (!in_array($plan, ['monthly', 'yearly'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plan']);
        exit;
    }

    $ok = activateSubscription($pdo, (int)$user['id'], $plan, $gateway, $ref);
    if ($ok) {
        $updated = getCurrentUser($pdo);
        echo json_encode([
            'success'    => true,
            'subscribed' => true,
            'plan'       => $updated['subscription_plan'] ?? $plan,
            'expires_at' => $updated['subscription_expires'] ?? null,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Activation failed']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
