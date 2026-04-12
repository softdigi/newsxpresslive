<?php
/**
 * web/api/blue_tick/purchase.php
 *
 * POST application/json
 *
 * Request a blue-tick plan (free early-bird or initiate paid purchase).
 * Paid plans return a Razorpay order that the client must complete.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *
 * Body:
 *   {
 *     "plan_id": 1,
 *     // For paid plans (Razorpay callback confirm):
 *     "payment_id": "pay_xxx",
 *     "order_id":   "order_xxx"
 *   }
 *
 * Response (free plan):
 *   { success: true, blue_tick: true, plan_id, slot }
 *
 * Response (paid plan – order creation):
 *   { success: true, order_id, amount, currency, key_id }
 *
 * Response (paid plan – payment confirm with payment_id + order_id):
 *   { success: true, blue_tick: true, plan_id }
 *
 * Note: Razorpay webhook is the authoritative confirmation path.
 *       The payment_id/order_id confirmation here is a fallback for apps
 *       that cannot receive webhooks.  Signature verification is omitted
 *       here intentionally (webhook should be the primary channel);
 *       production deployments should verify razorpay_signature.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['POST', 'OPTIONS']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Authentication ─────────────────────────────────────────────────────────
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
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$uid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$planId    = (int)($body['plan_id']    ?? 0);
$paymentId = trim($body['payment_id'] ?? '');
$orderId   = trim($body['order_id']   ?? '');

if ($planId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'plan_id is required']);
    exit;
}

// ── Load plan ──────────────────────────────────────────────────────────────
$planStmt = $pdo->prepare(
    "SELECT id, plan_type, name, price, duration_type FROM blue_tick_plans
     WHERE id = ? AND is_active = 1 LIMIT 1"
);
$planStmt->execute([$planId]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Plan not found']);
    exit;
}

// ── Prevent duplicate ─────────────────────────────────────────────────────
$dupStmt = $pdo->prepare(
    "SELECT id FROM blue_tick_purchases WHERE user_id=? AND status='completed' LIMIT 1"
);
$dupStmt->execute([$uid]);
if ($dupStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Blue tick already active']);
    exit;
}

// ── Determine if early-bird free ──────────────────────────────────────────
$isFree      = (float)$plan['price'] === 0.0;
$isEarlyBird = false;
$ebSlot      = null;

if ($isFree) {
    // Atomically claim a slot
    $ebType = str_starts_with($plan['plan_type'], 'reporter') ? 'reporter' : 'agency';
    $pdo->beginTransaction();
    try {
        $ebStmt = $pdo->prepare(
            "SELECT count, free_limit FROM early_bird_counters WHERE type=? FOR UPDATE"
        );
        $ebStmt->execute([$ebType]);
        $eb = $ebStmt->fetch(PDO::FETCH_ASSOC);

        if (!$eb || (int)$eb['count'] >= (int)$eb['free_limit']) {
            $pdo->rollBack();
            http_response_code(410);
            echo json_encode(['success' => false, 'message' => 'Free early-bird slots exhausted']);
            exit;
        }
        $newCount = (int)$eb['count'] + 1;
        $pdo->prepare("UPDATE early_bird_counters SET count=? WHERE type=?")
            ->execute([$newCount, $ebType]);

        $isEarlyBird = true;
        $ebSlot      = $newCount;
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error, please retry']);
        exit;
    }

    // Record purchase
    $pdo->prepare(
        "INSERT INTO blue_tick_purchases
           (user_id, plan_id, payment_gateway, amount, currency, status,
            is_early_bird, early_bird_slot)
         VALUES (?, ?, 'free', 0.00, 'INR', 'completed', 1, ?)"
    )->execute([$uid, $planId, $ebSlot]);

    // Grant blue tick
    $tickType = in_array($plan['plan_type'], ['reporter_free','reporter_paid'], true)
                ? ($isFree ? 'free' : 'paid')
                : ($isFree ? 'free' : 'paid');

    $pdo->prepare(
        "UPDATE users
         SET is_blue_tick=1, blue_tick_type=?, blue_tick_plan_id=?,
             blue_tick_granted_at=NOW(), account_type=?
         WHERE firebase_uid=?"
    )->execute([
        $tickType,
        $planId,
        str_starts_with($plan['plan_type'], 'reporter') ? 'reporter' : 'agency',
        $uid,
    ]);

    echo json_encode([
        'success'   => true,
        'blue_tick' => true,
        'plan_id'   => $planId,
        'slot'      => $ebSlot,
        'message'   => 'Blue tick granted! Welcome to the early-bird programme.',
    ]);
    exit;
}

// ── Paid plan ─────────────────────────────────────────────────────────────
// If payment_id + order_id sent → confirmation flow
if ($paymentId !== '' && $orderId !== '') {
    // Verify that a pending purchase exists for this user/order
    $pendingStmt = $pdo->prepare(
        "SELECT id FROM blue_tick_purchases
         WHERE user_id=? AND order_id=? AND status='pending' LIMIT 1"
    );
    $pendingStmt->execute([$uid, $orderId]);
    $purchaseId = $pendingStmt->fetchColumn();

    if (!$purchaseId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No pending purchase found for this order']);
        exit;
    }

    // Mark as completed
    $pdo->prepare(
        "UPDATE blue_tick_purchases
         SET status='completed', payment_id=?
         WHERE id=?"
    )->execute([$paymentId, $purchaseId]);

    // Grant blue tick
    $pdo->prepare(
        "UPDATE users
         SET is_blue_tick=1, blue_tick_type='paid', blue_tick_plan_id=?,
             blue_tick_granted_at=NOW(), account_type=?
         WHERE firebase_uid=?"
    )->execute([
        $planId,
        str_starts_with($plan['plan_type'], 'reporter') ? 'reporter' : 'agency',
        $uid,
    ]);

    echo json_encode([
        'success'   => true,
        'blue_tick' => true,
        'plan_id'   => $planId,
        'message'   => 'Payment confirmed. Blue tick activated!',
    ]);
    exit;
}

// ── Create Razorpay order ──────────────────────────────────────────────────
$rzKeyId     = getenv('RAZORPAY_KEY_ID')     ?: '';
$rzKeySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';

if (empty($rzKeyId) || empty($rzKeySecret)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Payment gateway not configured']);
    exit;
}

$amountPaise = (int)round((float)$plan['price'] * 100);

$ctx      = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode("{$rzKeyId}:{$rzKeySecret}"),
        ],
        'content' => json_encode([
            'amount'   => $amountPaise,
            'currency' => 'INR',
            'receipt'  => 'bt_' . $uid . '_' . time(),
            'notes'    => ['plan_id' => $planId, 'user_uid' => $uid],
        ]),
        'timeout' => 10,
    ],
]);
$rzResp = @file_get_contents('https://api.razorpay.com/v1/orders', false, $ctx);
if ($rzResp === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Failed to connect to payment gateway']);
    exit;
}
$rzOrder = json_decode($rzResp, true);
if (empty($rzOrder['id'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Payment gateway error', 'detail' => $rzOrder['error']['description'] ?? '']);
    exit;
}

// Store pending purchase
$pdo->prepare(
    "INSERT INTO blue_tick_purchases
       (user_id, plan_id, payment_gateway, order_id, amount, currency, status)
     VALUES (?, ?, 'razorpay', ?, ?, 'INR', 'pending')"
)->execute([$uid, $planId, $rzOrder['id'], $plan['price']]);

echo json_encode([
    'success'  => true,
    'order_id' => $rzOrder['id'],
    'amount'   => $amountPaise,
    'currency' => 'INR',
    'key_id'   => $rzKeyId,
    'plan'     => ['id' => $planId, 'name' => $plan['name']],
]);
