<?php
/**
 * web/api/reporter/withdraw.php
 *
 * Reporter wallet withdrawal request API.
 *
 * POST  — Submit a new withdrawal request
 * GET   — List reporter's own withdrawal history
 *
 * POST Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type:  application/json
 *
 * POST Body (JSON):
 * {
 *   "amount":          100.00,        // required; min ₹50
 *   "method":          "upi",         // "upi" | "bank" | "paytm"
 *   // UPI
 *   "upi_id":          "reporter@upi",
 *   // Bank transfer
 *   "bank_account":    "123456789012",
 *   "bank_ifsc":       "SBIN0000123",
 *   "bank_name":       "State Bank of India",
 *   "account_holder":  "Rahul Kumar"
 * }
 *
 * Minimum withdrawal: ₹50
 * A reporter cannot have more than 1 "requested" or "processing" withdrawal
 * outstanding at the same time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders();
setSecurityHeaders('api');
header('Content-Type: application/json');

// ── Authentication ──────────────────────────────────────────
$id_token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $id_token = substr($authHeader, 7);
}
if (!$id_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required', 'code' => 'AUTH_REQUIRED']);
    exit;
}
$user = requireAppUser($pdo, $id_token);

// Only reporters/agencies can withdraw
if (!in_array($user['role'] ?? '', ['reporter', 'agency'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Reporter account required']);
    exit;
}

$userId      = (int)$user['id'];
$userType    = $user['role'] === 'agency' ? 'agency' : 'reporter';

// ── GET: list own withdrawals ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT id, amount, method, upi_id, bank_name, account_holder,
                status, admin_note, transaction_ref, requested_at, processed_at
         FROM reporter_withdrawals
         WHERE user_id = ?
         ORDER BY requested_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, $perPage, $offset]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current wallet balance
    $balStmt = $pdo->prepare(
        "SELECT balance FROM wallets WHERE user_id = ? AND user_type = ? LIMIT 1"
    );
    $balStmt->execute([$userId, $userType]);
    $balance = (float)($balStmt->fetchColumn() ?? 0);

    echo json_encode([
        'success'     => true,
        'balance'     => $balance,
        'withdrawals' => $withdrawals,
        'page'        => $page,
    ]);
    exit;
}

// ── POST: create withdrawal request ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$amount = (float)($body['amount'] ?? 0);
$method = $body['method'] ?? 'upi';

// Validate amount
if ($amount < 50) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Minimum withdrawal amount is ₹50']);
    exit;
}

// Validate method
if (!in_array($method, ['upi', 'bank', 'paytm'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

// Validate method-specific fields
$upiId         = null;
$bankAccount   = null;
$bankIfsc      = null;
$bankName      = null;
$accountHolder = null;

if ($method === 'upi' || $method === 'paytm') {
    $upiId = trim($body['upi_id'] ?? '');
    if (!$upiId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'UPI ID is required']);
        exit;
    }
    // Basic UPI format check: handle@provider
    if (!preg_match('/^[a-zA-Z0-9.\-_+]+@[a-zA-Z0-9]+$/', $upiId)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid UPI ID format']);
        exit;
    }
} elseif ($method === 'bank') {
    $bankAccount   = preg_replace('/\D/', '', $body['bank_account'] ?? '');
    $bankIfsc      = strtoupper(trim($body['bank_ifsc'] ?? ''));
    $bankName      = trim($body['bank_name'] ?? '');
    $accountHolder = trim($body['account_holder'] ?? '');

    if (strlen($bankAccount) < 9 || strlen($bankAccount) > 18) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid bank account number']);
        exit;
    }
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid IFSC code']);
        exit;
    }
    if (!$bankName || !$accountHolder) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Bank name and account holder name are required']);
        exit;
    }
}

// ── Check wallet balance ─────────────────────────────────────
$pdo->beginTransaction();
try {
    $balStmt = $pdo->prepare(
        "SELECT id, balance FROM wallets WHERE user_id = ? AND user_type = ? FOR UPDATE"
    );
    $balStmt->execute([$userId, $userType]);
    $wallet = $balStmt->fetch(PDO::FETCH_ASSOC);
    $balance = $wallet ? (float)$wallet['balance'] : 0.0;

    if ($balance < $amount) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => 'Insufficient wallet balance',
            'balance' => $balance,
        ]);
        exit;
    }

    // Check no pending request already exists
    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reporter_withdrawals
         WHERE user_id = ? AND status IN ('requested','processing')"
    );
    $pendingStmt->execute([$userId]);
    if ((int)$pendingStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => 'You already have a pending withdrawal request. Please wait for it to be processed.',
        ]);
        exit;
    }

    // Insert withdrawal record
    $ins = $pdo->prepare(
        "INSERT INTO reporter_withdrawals
           (user_id, reporter_uid, amount, method, upi_id, bank_account,
            bank_ifsc, bank_name, account_holder, status)
         VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, 'requested')"
    );
    $ins->execute([
        $userId,
        $amount,
        $method,
        $upiId,
        $bankAccount,
        $bankIfsc,
        $bankName,
        $accountHolder,
    ]);
    $withdrawalId = (int)$pdo->lastInsertId();

    // Hold the amount (debit from wallet)
    $pdo->prepare(
        "UPDATE wallets SET balance = balance - ?, updated_at = NOW()
         WHERE user_id = ? AND user_type = ?"
    )->execute([$amount, $userId, $userType]);

    // Append debit row to transactions_log
    $walletId = $wallet['id'] ?? null;
    if ($walletId) {
        $pdo->prepare(
            "INSERT INTO transactions_log
               (reference_id, wallet_id, user_id, user_type, type, amount,
                balance_before, balance_after, description, created_at)
             VALUES (UUID(), ?, ?, ?, 'debit', ?,
                     ?, ?, 'Withdrawal request hold', NOW())"
        )->execute([
            $walletId,
            $userId,
            $userType,
            $amount,
            $balance,
            $balance - $amount,
        ]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
    exit;
}

echo json_encode([
    'success'       => true,
    'message'       => 'Withdrawal request submitted successfully. Will be processed within 3–5 business days.',
    'withdrawal_id' => $withdrawalId,
    'amount'        => $amount,
    'method'        => $method,
    'status'        => 'requested',
]);
