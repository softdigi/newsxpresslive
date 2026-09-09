<?php
// ============================================================
// api/v1/agency/withdraw.php
// POST /api/v1/agency/withdraw — Request a wallet withdrawal
//
// Validates minimum ₹500 threshold, sufficient wallet_balance,
// inserts into agency_withdrawals and agency_transactions,
// and deducts from wallet_balance atomically.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// ── Validate inputs ───────────────────────────────────────────────────────────
$amount         = isset($input['amount']) ? round((float)$input['amount'], 2) : 0.0;
$method         = trim($input['method'] ?? '');
$accountDetails = $input['account_details'] ?? null;

$allowedMethods = ['upi', 'bank', 'paypal'];
if (!in_array($method, $allowedMethods, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'method must be one of: upi, bank, paypal']);
    exit;
}

if (!is_array($accountDetails) || empty($accountDetails)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'account_details object is required']);
    exit;
}

// Validate account_details keys per method
$detailErrors = _validateAccountDetails($method, $accountDetails);
if ($detailErrors !== null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $detailErrors]);
    exit;
}

// Minimum withdrawal threshold: ₹500
$minWithdrawal = 500.00;
if ($amount < $minWithdrawal) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => "Minimum withdrawal amount is ₹{$minWithdrawal}",
    ]);
    exit;
}

// ── Re-read wallet balance with a row lock to prevent race conditions ──────────
$pdo->beginTransaction();

try {
    $balanceStmt = $pdo->prepare(
        'SELECT wallet_balance FROM agencies WHERE id = ? FOR UPDATE'
    );
    $balanceStmt->execute([$agency['id']]);
    $currentBalance = (float)$balanceStmt->fetchColumn();

    if ($currentBalance < $amount) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success'         => false,
            'error'           => 'Insufficient wallet balance',
            'wallet_balance'  => $currentBalance,
            'requested'       => $amount,
        ]);
        exit;
    }

    $balanceAfter = round($currentBalance - $amount, 2);

    // ── Insert withdrawal request ──────────────────────────────────────────────
    $wStmt = $pdo->prepare(
        'INSERT INTO agency_withdrawals
            (agency_id, amount, method, account_details, status, requested_at)
         VALUES (?, ?, ?, ?, \'requested\', NOW())'
    );
    $wStmt->execute([
        $agency['id'],
        $amount,
        $method,
        json_encode($accountDetails),
    ]);
    $withdrawalId = (int)$pdo->lastInsertId();

    // ── Deduct from wallet ─────────────────────────────────────────────────────
    $pdo->prepare(
        'UPDATE agencies SET wallet_balance = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$balanceAfter, $agency['id']]);

    // ── Ledger entry ──────────────────────────────────────────────────────────
    $pdo->prepare(
        'INSERT INTO agency_transactions
            (agency_id, type, amount, balance_before, balance_after,
             reference_id, note, status, created_at)
         VALUES (?, \'withdrawal\', ?, ?, ?, ?, ?, \'pending\', NOW())'
    )->execute([
        $agency['id'],
        -$amount,
        $currentBalance,
        $balanceAfter,
        $withdrawalId,
        "Withdrawal request #{$withdrawalId} via {$method}",
    ]);
    $txnId = (int)$pdo->lastInsertId();

    // ── Link transaction ID back to withdrawal ─────────────────────────────────
    $pdo->prepare(
        'UPDATE agency_withdrawals SET transaction_id = ? WHERE id = ?'
    )->execute([$txnId, $withdrawalId]);

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('agency withdraw error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Withdrawal request failed']);
    exit;
}

echo json_encode([
    'success'       => true,
    'withdrawal_id' => $withdrawalId,
    'amount'        => $amount,
    'method'        => $method,
    'status'        => 'requested',
    'wallet_balance'=> $balanceAfter,
    'message'       => 'Withdrawal request submitted. Processing within 3–5 business days.',
]);

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Validate account_details based on withdrawal method.
 * Returns an error string or null if valid.
 */
function _validateAccountDetails(string $method, array $details): ?string
{
    return match ($method) {
        'upi' => (empty($details['upi_id']) || !str_contains($details['upi_id'], '@'))
            ? 'account_details.upi_id is required and must be a valid UPI ID (e.g. name@upi)'
            : null,

        'bank' => (empty($details['account_no']) || empty($details['ifsc']) || empty($details['name']))
            ? 'account_details must include: account_no, ifsc, name'
            : null,

        'paypal' => (empty($details['paypal_email']) || !filter_var($details['paypal_email'], FILTER_VALIDATE_EMAIL))
            ? 'account_details.paypal_email must be a valid email address'
            : null,

        default => 'Unknown payment method',
    };
}
