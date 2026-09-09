<?php
/**
 * web/api/blue_tick/assign.php
 *
 * Agency assigns or revokes a blue tick for one of their reporters.
 *
 * POST  { "reporter_uid": "...", "action": "assign" | "revoke" }
 *   → assign:  create blue_tick_assignments row, grant tick on users
 *   → revoke:  mark assignment as revoked, remove tick from users
 *
 * GET   ?agency_uid=<uid>
 *   → list all active assignments for the agency (requires auth)
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>   (must be agency with blue tick)
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['GET', 'POST', 'OPTIONS']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

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
$tokenPayload = verifyFirebaseToken($idToken);
if (!$tokenPayload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}
$agencyUid = $tokenPayload['sub'] ?? $tokenPayload['uid'] ?? '';
if (empty($agencyUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Load agency user row ───────────────────────────────────────────────────
$agencyStmt = $pdo->prepare(
    "SELECT account_type, is_blue_tick, blue_tick_plan_id
     FROM users WHERE firebase_uid=? LIMIT 1"
);
$agencyStmt->execute([$agencyUid]);
$agencyUser = $agencyStmt->fetch(PDO::FETCH_ASSOC);

if (!$agencyUser || $agencyUser['account_type'] !== 'agency' || !(int)$agencyUser['is_blue_tick']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only blue-tick agencies can assign ticks']);
    exit;
}

// ── Load agency plan limits ────────────────────────────────────────────────
$planStmt = $pdo->prepare(
    "SELECT can_assign_ticks, assign_limit, assign_price
     FROM blue_tick_plans WHERE id=? AND is_active=1 LIMIT 1"
);
$planStmt->execute([$agencyUser['blue_tick_plan_id'] ?? 0]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

if (!$plan || !(int)$plan['can_assign_ticks']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your plan does not support tick assignment']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ══════════════════════════════════════════════════════════════════════════
// GET — list assignments
// ══════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $listStmt = $pdo->prepare(
        "SELECT bta.id, bta.reporter_id, bta.assignment_type,
                bta.amount_charged, bta.assigned_at, bta.status,
                up.display_name, up.avatar_url
         FROM   blue_tick_assignments bta
         LEFT   JOIN user_profiles up ON up.firebase_uid = bta.reporter_id
         WHERE  bta.agency_id = ?
         ORDER  BY bta.assigned_at DESC
         LIMIT  100"
    );
    $listStmt->execute([$agencyUid]);
    $assignments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Count active
    $activeCount = count(array_filter($assignments, fn($a) => $a['status'] === 'active'));

    echo json_encode([
        'success'      => true,
        'assignments'  => $assignments,
        'active_count' => $activeCount,
        'free_limit'   => (int)$plan['assign_limit'],
        'assign_price' => (float)$plan['assign_price'],
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST — assign or revoke
// ══════════════════════════════════════════════════════════════════════════
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET or POST required']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$reporterUid = trim($body['reporter_uid'] ?? '');
$action      = trim($body['action']       ?? '');

if ($reporterUid === '' || !in_array($action, ['assign', 'revoke'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'reporter_uid and action (assign|revoke) are required']);
    exit;
}

if ($reporterUid === $agencyUid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Cannot assign to yourself']);
    exit;
}

// ── REVOKE ─────────────────────────────────────────────────────────────────
if ($action === 'revoke') {
    $revStmt = $pdo->prepare(
        "UPDATE blue_tick_assignments
         SET status='revoked', revoked_at=NOW()
         WHERE agency_id=? AND reporter_id=? AND status='active'"
    );
    $revStmt->execute([$agencyUid, $reporterUid]);

    if ($revStmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Active assignment not found']);
        exit;
    }

    // Remove blue tick from reporter (only if it was agency-assigned)
    $pdo->prepare(
        "UPDATE users
         SET is_blue_tick=0, blue_tick_type=NULL, blue_tick_plan_id=NULL, blue_tick_granted_at=NULL
         WHERE firebase_uid=? AND blue_tick_type='agency_assigned'"
    )->execute([$reporterUid]);

    echo json_encode(['success' => true, 'message' => 'Blue tick revoked']);
    exit;
}

// ── ASSIGN ─────────────────────────────────────────────────────────────────
// Check for duplicate active assignment
$dupStmt = $pdo->prepare(
    "SELECT id FROM blue_tick_assignments
     WHERE agency_id=? AND reporter_id=? AND status='active' LIMIT 1"
);
$dupStmt->execute([$agencyUid, $reporterUid]);
if ($dupStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Reporter already has an active tick from this agency']);
    exit;
}

// Count existing active assignments to determine free vs paid
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blue_tick_assignments WHERE agency_id=? AND status='active'"
);
$countStmt->execute([$agencyUid]);
$activeCount = (int)$countStmt->fetchColumn();

$freeLimit   = (int)$plan['assign_limit'];
$assignPrice = (float)$plan['assign_price'];

if ($activeCount >= $freeLimit && $assignPrice > 0) {
    // Need payment — return order creation response
    // (simplified: return amount, client initiates Razorpay flow)
    echo json_encode([
        'success'        => false,
        'payment_required'=> true,
        'amount'         => $assignPrice,
        'currency'       => 'INR',
        'message'        => "Free slots exhausted. Additional assignments cost ₹{$assignPrice} each.",
    ]);
    exit;
}

$assignType    = ($activeCount < $freeLimit) ? 'free' : 'paid';
$amountCharged = ($assignType === 'paid') ? $assignPrice : 0.0;

// Insert assignment
$pdo->prepare(
    "INSERT INTO blue_tick_assignments
       (agency_id, reporter_id, assignment_type, amount_charged, status)
     VALUES (?, ?, ?, ?, 'active')
     ON DUPLICATE KEY UPDATE status='active', revoked_at=NULL, assigned_at=NOW()"
)->execute([$agencyUid, $reporterUid, $assignType, $amountCharged]);

// Grant reporter blue tick (agency_assigned type)
$pdo->prepare(
    "UPDATE users
     SET is_blue_tick=1, blue_tick_type='agency_assigned',
         blue_tick_granted_at=NOW(), agency_id=?
     WHERE firebase_uid=?"
)->execute([$agencyUid, $reporterUid]);

// Upsert agency_reporters relationship
$pdo->prepare(
    "INSERT INTO agency_reporters (agency_id, reporter_id, join_type, status)
     VALUES (?, ?, 'agency_added', 'active')
     ON DUPLICATE KEY UPDATE status='active', removed_at=NULL"
)->execute([$agencyUid, $reporterUid]);

echo json_encode([
    'success'         => true,
    'message'         => 'Blue tick assigned to reporter',
    'assignment_type' => $assignType,
    'amount_charged'  => $amountCharged,
]);
