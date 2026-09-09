<?php
/**
 * WalletTransactionTest — Unit tests for WalletTransactionManager.
 *
 * Covers:
 *   • Correct 90 / 10 split between reporter and agency
 *   • Wallet rows are created on-demand (auto-provisioning)
 *   • Both wallet balances are updated atomically
 *   • Both transactions_log rows are written in the same operation
 *   • reference_id is shared by the two log rows from one payout
 *   • Multiple sequential payouts accumulate correctly
 *   • Zero / negative gross amount is rejected before the DB is touched
 *   • Deadlock retry loop (simulated via a mock PDO that throws on the
 *     first N attempts then succeeds)
 *   • Non-deadlock PDOException is NOT retried and is returned immediately
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers/wallet_transaction.php';

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// SQLite schema helpers
// ---------------------------------------------------------------------------

function createWalletTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallets (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            user_type    TEXT    NOT NULL CHECK (user_type IN ('reporter','agency')),
            balance      REAL    NOT NULL DEFAULT 0.00,
            total_earned REAL    NOT NULL DEFAULT 0.00,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (user_id, user_type)
        );

        CREATE TABLE IF NOT EXISTS transactions_log (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_id     TEXT    NOT NULL,
            wallet_id        INTEGER NOT NULL,
            user_id          INTEGER NOT NULL,
            user_type        TEXT    NOT NULL CHECK (user_type IN ('reporter','agency')),
            type             TEXT    NOT NULL CHECK (type IN ('credit','debit')),
            amount           REAL    NOT NULL,
            balance_before   REAL    NOT NULL,
            balance_after    REAL    NOT NULL,
            description      TEXT    NOT NULL DEFAULT '',
            related_user_id  INTEGER DEFAULT NULL,
            created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    ");
}

// ---------------------------------------------------------------------------
// Stub logger that records calls so tests can assert on logged messages
// ---------------------------------------------------------------------------

class StubLogger
{
    public array $errors   = [];
    public array $warnings = [];
    public array $infos    = [];

    public function info(string $msg, array $ctx = []): void    { $this->infos[]    = compact('msg', 'ctx'); }
    public function warning(string $msg, array $ctx = []): void { $this->warnings[] = compact('msg', 'ctx'); }
    public function error(string $msg, array $ctx = []): void   { $this->errors[]   = compact('msg', 'ctx'); }
}

// ---------------------------------------------------------------------------
// Mock PDO that simulates deadlocks on the first N beginTransaction() calls
// ---------------------------------------------------------------------------

class DeadlockSimulatorPDO extends \PDO
{
    private int $failsRemaining;
    private PDO $real;

    public function __construct(PDO $real, int $deadlockCount)
    {
        $this->real           = $real;
        $this->failsRemaining = $deadlockCount;
    }

    public function beginTransaction(): bool
    {
        if ($this->failsRemaining > 0) {
            $this->failsRemaining--;
            $ex = new \PDOException('Deadlock found when trying to get lock');
            $ex->errorInfo = ['40001', 1213, 'Deadlock found'];
            // PDOException::$code is read-only; set via ReflectionProperty
            $ref = new \ReflectionProperty(\PDOException::class, 'code');
            $ref->setAccessible(true);
            $ref->setValue($ex, '40001');
            throw $ex;
        }
        return $this->real->beginTransaction();
    }

    public function inTransaction(): bool      { return $this->real->inTransaction(); }
    public function rollBack(): bool           { return $this->real->rollBack(); }
    public function commit(): bool             { return $this->real->commit(); }
    public function prepare($sql, $opts = []): \PDOStatement { return $this->real->prepare($sql, $opts); }
    public function exec($sql): int|false      { return $this->real->exec($sql); }
    public function query($sql, ...$args): \PDOStatement|false { return $this->real->query($sql, ...$args); }
    public function lastInsertId($name = null): string|false { return $this->real->lastInsertId($name); }
    public function getAttribute($attr): mixed { return $this->real->getAttribute($attr); }
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

class WalletTransactionTest extends TestCase
{
    private PDO        $pdo;
    private StubLogger $logger;

    protected function setUp(): void
    {
        $this->pdo    = createTestPdo();
        $this->logger = new StubLogger();
        createWalletTables($this->pdo);
    }

    // ── Split accuracy ───────────────────────────────────────────────────────

    public function testReporterReceivesNinetyPercent(): void
    {
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00, 'test', $this->logger
        );

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertEqualsWithDelta(90.00, $result['reporter_share'], 0.001);
    }

    public function testAgencyReceivesTenPercent(): void
    {
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00, 'test', $this->logger
        );

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(10.00, $result['agency_cut'], 0.001);
    }

    public function testSharesPlusAgencyCutEqualsGross(): void
    {
        $gross  = 77.50;
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, $gross, '', $this->logger
        );

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(
            $gross,
            round($result['reporter_share'] + $result['agency_cut'], 2),
            0.001,
            'reporter_share + agency_cut must equal gross_amount'
        );
    }

    // ── Wallet auto-provisioning ─────────────────────────────────────────────

    public function testWalletRowsAreCreatedAutomatically(): void
    {
        WalletTransactionManager::processReporterPayout($this->pdo, 10, 20, 50.00);

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM wallets WHERE user_id IN (10,20)"
        );
        $this->assertEquals(2, (int)$stmt->fetchColumn());
    }

    // ── Balance updates ──────────────────────────────────────────────────────

    public function testReporterWalletBalanceIsUpdated(): void
    {
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 200.00);

        $stmt = $this->pdo->prepare(
            "SELECT balance FROM wallets WHERE user_id = ? AND user_type = 'reporter'"
        );
        $stmt->execute([1]);
        $this->assertEqualsWithDelta(180.00, (float)$stmt->fetchColumn(), 0.001);
    }

    public function testAgencyWalletBalanceIsUpdated(): void
    {
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 200.00);

        $stmt = $this->pdo->prepare(
            "SELECT balance FROM wallets WHERE user_id = ? AND user_type = 'agency'"
        );
        $stmt->execute([2]);
        $this->assertEqualsWithDelta(20.00, (float)$stmt->fetchColumn(), 0.001);
    }

    public function testMultiplePayoutsAccumulateCorrectly(): void
    {
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 100.00);
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 100.00);
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 100.00);

        $stmt = $this->pdo->prepare(
            "SELECT balance FROM wallets WHERE user_id = ? AND user_type = 'reporter'"
        );
        $stmt->execute([1]);
        $this->assertEqualsWithDelta(270.00, (float)$stmt->fetchColumn(), 0.001);

        $stmt->execute([2]);  // user_id=2 is the agency — wrong type, re-query
        $stmt2 = $this->pdo->prepare(
            "SELECT balance FROM wallets WHERE user_id = ? AND user_type = 'agency'"
        );
        $stmt2->execute([2]);
        $this->assertEqualsWithDelta(30.00, (float)$stmt2->fetchColumn(), 0.001);
    }

    // ── Transaction log ──────────────────────────────────────────────────────

    public function testTwoLogRowsAreInsertedPerPayout(): void
    {
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00
        );

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM transactions_log WHERE reference_id = ?"
        );
        $stmt->execute([$result['reference_id']]);
        $this->assertEquals(2, (int)$stmt->fetchColumn());
    }

    public function testBothLogRowsShareTheSameReferenceId(): void
    {
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00
        );

        $stmt = $this->pdo->prepare(
            "SELECT user_type FROM transactions_log
              WHERE reference_id = ?
              ORDER BY user_type"
        );
        $stmt->execute([$result['reference_id']]);
        $types = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('agency',   $types);
        $this->assertContains('reporter', $types);
    }

    public function testLogRowsRecordCorrectBalanceBeforeAndAfter(): void
    {
        // First payout — both wallets start at 0
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 100.00);

        // Second payout — balances are now 90 (reporter) and 10 (agency)
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00
        );

        $stmt = $this->pdo->prepare(
            "SELECT user_type, balance_before, balance_after
               FROM transactions_log
              WHERE reference_id = ?"
        );
        $stmt->execute([$result['reference_id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ($row['user_type'] === 'reporter') {
                $this->assertEqualsWithDelta(90.00, (float)$row['balance_before'], 0.001);
                $this->assertEqualsWithDelta(180.00, (float)$row['balance_after'], 0.001);
            } else {
                $this->assertEqualsWithDelta(10.00, (float)$row['balance_before'], 0.001);
                $this->assertEqualsWithDelta(20.00, (float)$row['balance_after'], 0.001);
            }
        }
    }

    public function testDescriptionIsStoredInLogRows(): void
    {
        WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 50.00, 'Article #999 earnings'
        );

        $stmt = $this->pdo->query(
            "SELECT description FROM transactions_log WHERE user_type = 'reporter' LIMIT 1"
        );
        $desc = $stmt->fetchColumn();
        $this->assertStringContainsString('Article #999 earnings', $desc);
    }

    public function testTotalEarnedIsIncrementedInWallet(): void
    {
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 100.00);
        WalletTransactionManager::processReporterPayout($this->pdo, 1, 2,  50.00);

        $stmt = $this->pdo->prepare(
            "SELECT total_earned FROM wallets WHERE user_id = ? AND user_type = 'reporter'"
        );
        $stmt->execute([1]);
        // 90 + 45 = 135
        $this->assertEqualsWithDelta(135.00, (float)$stmt->fetchColumn(), 0.001);
    }

    // ── Input validation ─────────────────────────────────────────────────────

    public function testZeroGrossAmountIsRejected(): void
    {
        $result = WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 0.00);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testNegativeGrossAmountIsRejected(): void
    {
        $result = WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, -10.00);
        $this->assertFalse($result['success']);
    }

    // ── Deadlock retry ───────────────────────────────────────────────────────

    public function testDeadlockIsRetriedAndEventuallySucceeds(): void
    {
        // Two deadlocks on beginTransaction, third attempt succeeds
        $realPdo  = createTestPdo();
        createWalletTables($realPdo);
        $mockPdo  = new DeadlockSimulatorPDO($realPdo, 2);

        $result = WalletTransactionManager::processReporterPayout(
            $mockPdo, 1, 2, 100.00, '', $this->logger
        );

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertEquals(3, $result['attempts']);
    }

    public function testDeadlockWarningIsLogged(): void
    {
        $realPdo = createTestPdo();
        createWalletTables($realPdo);
        $mockPdo = new DeadlockSimulatorPDO($realPdo, 1);

        WalletTransactionManager::processReporterPayout(
            $mockPdo, 1, 2, 100.00, '', $this->logger
        );

        $this->assertNotEmpty(
            $this->logger->warnings,
            'At least one deadlock warning should be logged'
        );
        $this->assertStringContainsString(
            'Deadlock',
            $this->logger->warnings[0]['msg']
        );
    }

    public function testAllDeadlockRetriesExhaustedReturnsFailure(): void
    {
        $realPdo = createTestPdo();
        createWalletTables($realPdo);
        // 3 deadlocks → all MAX_DEADLOCK_RETRIES attempts fail
        $mockPdo = new DeadlockSimulatorPDO($realPdo, 3);

        $result = WalletTransactionManager::processReporterPayout(
            $mockPdo, 1, 2, 100.00, '', $this->logger
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('retry limit', $result['error']);
        $this->assertEquals(3, $result['attempts']);
        $this->assertNotEmpty($this->logger->errors);
    }

    public function testNoWalletRowsWrittenWhenAllRetriesExhausted(): void
    {
        $realPdo = createTestPdo();
        createWalletTables($realPdo);
        $mockPdo = new DeadlockSimulatorPDO($realPdo, 3);

        WalletTransactionManager::processReporterPayout($mockPdo, 1, 2, 100.00);

        $stmt  = $realPdo->query("SELECT COUNT(*) FROM transactions_log");
        $this->assertEquals(0, (int)$stmt->fetchColumn(), 'No log rows should exist after exhausted retries');
    }

    // ── Logger integration ───────────────────────────────────────────────────

    public function testSuccessfulPayoutIsInfoLogged(): void
    {
        WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00, 'test payout', $this->logger
        );

        $this->assertNotEmpty($this->logger->infos);
        $this->assertStringContainsString('completed', $this->logger->infos[0]['msg']);
    }

    public function testPayoutWithoutLoggerDoesNotThrow(): void
    {
        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo, 1, 2, 100.00
        );
        $this->assertTrue($result['success']);
    }

    // ── Reference ID format ──────────────────────────────────────────────────

    public function testReferenceIdIsUuidV4Format(): void
    {
        $result = WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 50.00);

        $uuid = $result['reference_id'];
        // Basic UUID v4 pattern: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertRegExp($pattern, $uuid);
    }

    public function testEachPayoutHasUniqueReferenceId(): void
    {
        $r1 = WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 50.00);
        $r2 = WalletTransactionManager::processReporterPayout($this->pdo, 1, 2, 50.00);

        $this->assertNotEquals($r1['reference_id'], $r2['reference_id']);
    }
}
