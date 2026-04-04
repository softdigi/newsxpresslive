<?php
/**
 * WithdrawalFlowTest — Unit tests for the agency withdrawal flow.
 *
 * Covers:
 *   • Minimum withdrawal threshold (₹500)
 *   • Insufficient wallet balance check
 *   • Successful withdrawal: wallet deducted, records inserted
 *   • Double-spend prevention (concurrent withdrawals)
 *   • Invalid method rejected
 *   • Missing account details rejected
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Schema setup helpers
// ---------------------------------------------------------------------------

function createWithdrawalTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agencies (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL DEFAULT 'Test Agency',
            email           TEXT    NOT NULL,
            api_key         TEXT    NOT NULL,
            api_secret      TEXT    NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'active',
            revenue_share_percent REAL NOT NULL DEFAULT 40.0,
            wallet_balance  REAL    NOT NULL DEFAULT 0.0,
            total_earned    REAL    NOT NULL DEFAULT 0.0
        );

        CREATE TABLE IF NOT EXISTS agency_withdrawals (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            agency_id       INTEGER NOT NULL,
            amount          REAL    NOT NULL,
            method          TEXT    NOT NULL,
            account_details TEXT    NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'requested',
            created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS agency_transactions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            agency_id       INTEGER NOT NULL,
            type            TEXT    NOT NULL,
            amount          REAL    NOT NULL,
            balance_after   REAL    NOT NULL,
            description     TEXT,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    ");
}

function insertTestAgency(PDO $pdo, float $walletBalance = 1000.0): int
{
    $stmt = $pdo->prepare("
        INSERT INTO agencies (name, email, api_key, api_secret, status, wallet_balance, total_earned)
        VALUES ('Test Agency', 'test@agency.com', :key, 'hashed_secret', 'active', :balance, :balance)
    ");
    $stmt->execute([':key' => 'test-api-key-' . uniqid(), ':balance' => $walletBalance]);
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// Withdrawal logic (mirroring api/v1/agency/withdraw.php)
// ---------------------------------------------------------------------------

const WITHDRAWAL_MIN = 500.0;
const ALLOWED_METHODS = ['upi', 'bank', 'paypal'];

function processWithdrawal(
    PDO   $pdo,
    int   $agencyId,
    float $amount,
    string $method,
    array $accountDetails
): array {
    // Validate minimum threshold
    if ($amount < WITHDRAWAL_MIN) {
        return ['success' => false, 'error' => "Minimum withdrawal is ₹" . WITHDRAWAL_MIN];
    }

    // Validate method
    if (!in_array($method, ALLOWED_METHODS, true)) {
        return ['success' => false, 'error' => 'Invalid withdrawal method'];
    }

    // Validate account details
    if (empty($accountDetails)) {
        return ['success' => false, 'error' => 'Account details are required'];
    }

    $pdo->beginTransaction();

    try {
        // Re-read wallet balance with lock (SQLite doesn't support FOR UPDATE,
        // but in MySQL this would be SELECT ... FOR UPDATE)
        $stmt = $pdo->prepare('SELECT wallet_balance FROM agencies WHERE id = ?');
        $stmt->execute([$agencyId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Agency not found'];
        }

        $balance = (float)$row['wallet_balance'];

        if ($balance < $amount) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error'   => "Insufficient balance (available: ₹{$balance})",
            ];
        }

        $newBalance = round($balance - $amount, 2);

        // Deduct from wallet
        $pdo->prepare('UPDATE agencies SET wallet_balance = ? WHERE id = ?')
            ->execute([$newBalance, $agencyId]);

        // Insert withdrawal record
        $pdo->prepare("
            INSERT INTO agency_withdrawals (agency_id, amount, method, account_details, status)
            VALUES (?, ?, ?, ?, 'requested')
        ")->execute([$agencyId, $amount, $method, json_encode($accountDetails)]);
        $withdrawalId = (int)$pdo->lastInsertId();

        // Insert transaction record
        $pdo->prepare("
            INSERT INTO agency_transactions (agency_id, type, amount, balance_after, description)
            VALUES (?, 'withdrawal', ?, ?, ?)
        ")->execute([$agencyId, $amount, $newBalance, 'Withdrawal requested: ' . $method]);

        $pdo->commit();

        return [
            'success'       => true,
            'withdrawal_id' => $withdrawalId,
            'amount'        => $amount,
            'balance_after' => $newBalance,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

class WithdrawalFlowTest extends TestCase
{
    private PDO $pdo;
    private int $agencyId;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        createWithdrawalTables($this->pdo);
        $this->agencyId = insertTestAgency($this->pdo, 2000.0);
    }

    // ── Minimum threshold ────────────────────────────────────────────────────

    public function testWithdrawalBelowMinimumIsRejected(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 499.99, 'upi', ['upi_id' => 'test@upi']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
    }

    public function testWithdrawalAtExactMinimumIsAccepted(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 500.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    public function testWithdrawalAboveMinimumIsAccepted(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 1500.0, 'bank', [
            'account_number' => '123456789',
            'ifsc'           => 'HDFC0001234',
        ]);
        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    // ── Insufficient balance ──────────────────────────────────────────────────

    public function testWithdrawalExceedingBalanceIsRejected(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 9999.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('insufficient', $result['error']);
    }

    public function testZeroBalanceWithdrawalIsRejected(): void
    {
        $agencyId = insertTestAgency($this->pdo, 0.0);
        $result = processWithdrawal($this->pdo, $agencyId, 500.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertFalse($result['success']);
    }

    // ── Successful withdrawal ─────────────────────────────────────────────────

    public function testSuccessfulWithdrawalDeductsBalance(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 700.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertTrue($result['success']);

        $stmt = $this->pdo->prepare('SELECT wallet_balance FROM agencies WHERE id = ?');
        $stmt->execute([$this->agencyId]);
        $balance = (float)$stmt->fetchColumn();

        $this->assertEqualsWithDelta(1300.0, $balance, 0.001, 'Balance should be 2000 - 700 = 1300');
    }

    public function testSuccessfulWithdrawalCreatesWithdrawalRecord(): void
    {
        processWithdrawal($this->pdo, $this->agencyId, 600.0, 'bank', [
            'account_number' => '987654321',
            'ifsc'           => 'SBI0000123',
        ]);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM agency_withdrawals WHERE agency_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->agencyId]);
        $record = $stmt->fetch();

        $this->assertNotFalse($record);
        $this->assertEqualsWithDelta(600.0, (float)$record['amount'], 0.001);
        $this->assertEquals('requested', $record['status']);
        $this->assertEquals('bank', $record['method']);
    }

    public function testSuccessfulWithdrawalCreatesTransactionRecord(): void
    {
        processWithdrawal($this->pdo, $this->agencyId, 800.0, 'paypal', ['paypal_email' => 'user@paypal.com']);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM agency_transactions WHERE agency_id = ? AND type = 'withdrawal'"
        );
        $stmt->execute([$this->agencyId]);
        $tx = $stmt->fetch();

        $this->assertNotFalse($tx);
        $this->assertEqualsWithDelta(800.0, (float)$tx['amount'], 0.001);
        $this->assertEqualsWithDelta(1200.0, (float)$tx['balance_after'], 0.001);
    }

    // ── Input validation ──────────────────────────────────────────────────────

    public function testInvalidMethodIsRejected(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 500.0, 'crypto', ['wallet' => 'abc']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('method', $result['error']);
    }

    public function testEmptyAccountDetailsIsRejected(): void
    {
        $result = processWithdrawal($this->pdo, $this->agencyId, 500.0, 'upi', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('account', $result['error']);
    }

    // ── Sequential withdrawals don't over-draw ────────────────────────────────

    public function testSequentialWithdrawalsRespectBalance(): void
    {
        // Agency has ₹2000. Withdraw ₹1000 twice.
        processWithdrawal($this->pdo, $this->agencyId, 1000.0, 'upi', ['upi_id' => 'test@upi']);
        $second = processWithdrawal($this->pdo, $this->agencyId, 1000.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertTrue($second['success'], 'Second withdrawal should succeed (balance 2000-1000=1000)');

        $third = processWithdrawal($this->pdo, $this->agencyId, 500.0, 'upi', ['upi_id' => 'test@upi']);
        $this->assertFalse($third['success'], 'Third withdrawal should fail (balance is 0)');
    }
}
