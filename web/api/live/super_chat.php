<?php
/**
 * web/api/live/super_chat.php
 *
 * POST /api/live/super_chat.php
 *      Send a super chat (paid highlight) during a live stream.
 *
 * Revenue split: Platform 30 %, Reporter 70 %.
 *
 * In production, `payment_id` should first be verified with the
 * Razorpay Orders API before crediting earnings.  This endpoint
 * handles the accounting; actual payment capture happens via
 * Razorpay before this call.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type:  application/json
 *
 * Body (JSON):
 * {
 *   stream_id  : int     (required)
 *   amount     : float   (required, min 10 INR)
 *   message    : string  (optional, max 200 chars)
 *   payment_id : string  (required — Razorpay payment ID)
 *   currency   : string  (optional, default "INR")
 * }
 *
 * Response 201:
 * {
 *   success       : true,
 *   super_chat_id : int,
 *   amount        : string  (decimal),
 *   platform_cut  : string,
 *   reporter_cut  : string,
 *   message       : string|null
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
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

$senderUid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($senderUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in token']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$streamId  = isset($input['stream_id'])  ? (int)$input['stream_id']           : 0;
$amount    = isset($input['amount'])     ? round((float)$input['amount'], 2)   : 0.0;
$message   = mb_substr(strip_tags(trim($input['message'] ?? '')), 0, 200);
$paymentId = mb_substr(trim($input['payment_id'] ?? ''), 0, 200);
$currency  = strtoupper(trim($input['currency'] ?? 'INR'));

if ($streamId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'stream_id is required']);
    exit;
}

if ($amount < 10.0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Minimum super chat amount is ₹10']);
    exit;
}

if (empty($paymentId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'payment_id is required']);
    exit;
}

// Validate payment_id format (Razorpay: "pay_XXXXXXXXXXXXXXXX")
if (!preg_match('/^[A-Za-z0-9_]{8,64}$/', $paymentId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payment_id format']);
    exit;
}

if (!in_array($currency, ['INR', 'USD', 'EUR'], true)) {
    $currency = 'INR';
}

// ── Revenue split ─────────────────────────────────────────────────────────────
const PLATFORM_CUT_PERCENT = 0.30;
const REPORTER_CUT_PERCENT = 0.70;

$platformCut = round($amount * PLATFORM_CUT_PERCENT, 2);
$reporterCut = round($amount * REPORTER_CUT_PERCENT, 2);

// Correct rounding discrepancy
if (($platformCut + $reporterCut) !== $amount) {
    $reporterCut = round($amount - $platformCut, 2);
}

try {
    // ── Verify stream is live ─────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, reporter_uid, status FROM live_streams WHERE id = :id'
    );
    $stmt->execute([':id' => $streamId]);
    $stream = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stream) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Stream not found']);
        exit;
    }

    if ($stream['status'] !== 'live') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Stream is not currently live']);
        exit;
    }

    // ── Prevent duplicate payment_id ──────────────────────────────────────────
    $dupStmt = $pdo->prepare(
        'SELECT id FROM live_super_chats WHERE payment_id = :pid LIMIT 1'
    );
    $dupStmt->execute([':pid' => $paymentId]);
    if ($dupStmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Duplicate payment_id']);
        exit;
    }

    // ── Insert super chat ─────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        "INSERT INTO live_super_chats
            (stream_id, sender_uid, amount, message, currency,
             payment_id, platform_cut, reporter_cut, status)
         VALUES
            (:sid, :uid, :amt, :msg, :cur,
             :pid, :plat, :rep, 'completed')"
    );
    $insertStmt->execute([
        ':sid'  => $streamId,
        ':uid'  => $senderUid,
        ':amt'  => number_format($amount, 2, '.', ''),
        ':msg'  => $message ?: null,
        ':cur'  => $currency,
        ':pid'  => $paymentId,
        ':plat' => number_format($platformCut, 2, '.', ''),
        ':rep'  => number_format($reporterCut, 2, '.', ''),
    ]);
    $superChatId = (int)$pdo->lastInsertId();

    // ── Update live_streams totals ────────────────────────────────────────────
    $pdo->prepare(
        "UPDATE live_streams
         SET total_super_chats       = total_super_chats + 1,
             total_super_chat_amount = total_super_chat_amount + :amt,
             platform_earnings       = platform_earnings + :plat,
             reporter_earnings       = reporter_earnings + :rep
         WHERE id = :sid"
    )->execute([
        ':amt'  => number_format($amount, 2, '.', ''),
        ':plat' => number_format($platformCut, 2, '.', ''),
        ':rep'  => number_format($reporterCut, 2, '.', ''),
        ':sid'  => $streamId,
    ]);

    // ── Insert super chat as a pinned comment ─────────────────────────────────
    $pdo->prepare(
        "INSERT INTO live_stream_comments
            (stream_id, user_uid, message, is_super_chat, is_pinned)
         VALUES (:sid, :uid, :msg, 1, 1)"
    )->execute([
        ':sid' => $streamId,
        ':uid' => $senderUid,
        ':msg' => ($message ?: '❤️') . ' [₹' . number_format($amount, 0) . ']',
    ]);

    $pdo->commit();

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('live/super_chat.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

http_response_code(201);
echo json_encode([
    'success'       => true,
    'super_chat_id' => $superChatId,
    'amount'        => number_format($amount, 2, '.', ''),
    'platform_cut'  => number_format($platformCut, 2, '.', ''),
    'reporter_cut'  => number_format($reporterCut, 2, '.', ''),
    'message'       => $message ?: null,
    'stream_id'     => $streamId,
]);
