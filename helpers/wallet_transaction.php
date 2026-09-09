<?php
/**
 * helpers/wallet_transaction.php
 *
 * WalletTransactionManager — atomic payout processing with deadlock retry.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  Each payout credits the reporter's share AND the agency's 10% cut in  │
 * │  a single InnoDB transaction.  If PHP crashes at any point, MySQL       │
 * │  rolls back the whole unit — both wallets stay consistent.              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Concurrency safety:
 *   • Both wallet rows are locked with SELECT … FOR UPDATE before any write.
 *   • Locks are always acquired in ascending wallet-id order to prevent the
 *     circular-wait condition that causes deadlocks.
 *   • If MySQL still raises a deadlock (SQLSTATE 40001), the entire attempt
 *     is retried up to MAX_DEADLOCK_RETRIES times with short random back-off.
 *
 * Usage:
 *   require_once __DIR__ . '/wallet_transaction.php';
 *   require_once __DIR__ . '/logger.php';
 *
 *   $result = WalletTransactionManager::processReporterPayout(
 *       $pdo,
 *       reporterUserId: 42,
 *       agencyUserId:   7,
 *       grossAmount:    100.00,
 *       description:    'Article #501 earnings',
 *       logger:         AppLogger::getInstance()
 *   );
 *
 *   if (!$result['success']) {
 *       // $result['error'] contains the reason
 *   }
 *
 * Schema required:
 *   db/migration_v18_wallet_transactions.sql
 */

declare(strict_types=1);

class WalletTransactionManager
{
    /** Maximum payout retry attempts when a deadlock is detected. */
    private const MAX_DEADLOCK_RETRIES = 3;

    /** Agency share of every gross payout (10 %). */
    private const AGENCY_CUT_RATE = 0.10;

    /**
     * SQLSTATE for InnoDB deadlock / lock-wait timeout.
     * MySQL error 1213 maps to SQLSTATE 40001.
     */
    private const SQLSTATE_DEADLOCK = '40001';

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Credit a reporter and the owning agency atomically.
     *
     * Given a gross payout amount:
     *   reporter receives  grossAmount × 0.90
     *   agency   receives  grossAmount × 0.10
     *
     * Both wallet updates and their matching transactions_log rows are written
     * inside a single BEGIN … COMMIT block.  A deadlock causes the whole block
     * to be retried up to MAX_DEADLOCK_RETRIES times; any other exception
     * triggers an immediate rollback and returns a failure result.
     *
     * @param PDO            $pdo             Active database connection
     * @param int            $reporterUserId  users.id of the reporter
     * @param int            $agencyUserId    users.id of the agency
     * @param float          $grossAmount     Total payout before agency cut
     * @param string         $description     Human-readable reason (stored in log)
     * @param AppLogger|null $logger          Optional structured logger
     *
     * @return array{
     *   success:               bool,
     *   reference_id?:         string,
     *   reporter_share?:       float,
     *   agency_cut?:           float,
     *   reporter_balance_after?: float,
     *   agency_balance_after?:   float,
     *   error?:                string,
     *   attempts?:             int
     * }
     */
    public static function processReporterPayout(
        PDO      $pdo,
        int      $reporterUserId,
        int      $agencyUserId,
        float    $grossAmount,
        string   $description = '',
        ?object  $logger      = null
    ): array {
        if ($grossAmount <= 0.0) {
            return ['success' => false, 'error' => 'Gross amount must be positive'];
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_DEADLOCK_RETRIES; $attempt++) {
            try {
                $result = self::executePayoutTransaction(
                    $pdo,
                    $reporterUserId,
                    $agencyUserId,
                    $grossAmount,
                    $description,
                    $logger
                );
                $result['attempts'] = $attempt;
                return $result;

            } catch (\PDOException $e) {
                // Ensure we are not left inside an open transaction
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\Throwable $rollbackEx) {
                        if ($logger) {
                            $logger->error('Rollback failed', [
                                'exception' => get_class($rollbackEx),
                                'message'   => $rollbackEx->getMessage(),
                            ]);
                        }
                    }
                }

                if ((string)$e->getCode() !== self::SQLSTATE_DEADLOCK) {
                    // Non-retryable error — log and return immediately
                    if ($logger) {
                        $logger->error('Wallet payout failed (non-deadlock)', [
                            'reporter_user_id' => $reporterUserId,
                            'agency_user_id'   => $agencyUserId,
                            'gross_amount'     => $grossAmount,
                            'sqlstate'         => $e->getCode(),
                            'message'          => $e->getMessage(),
                        ]);
                    }
                    return ['success' => false, 'error' => $e->getMessage(), 'attempts' => $attempt];
                }

                $lastException = $e;

                if ($logger) {
                    $logger->warning('Deadlock detected, will retry', [
                        'reporter_user_id' => $reporterUserId,
                        'agency_user_id'   => $agencyUserId,
                        'gross_amount'     => $grossAmount,
                        'attempt'          => $attempt,
                        'max_attempts'     => self::MAX_DEADLOCK_RETRIES,
                    ]);
                }

                // Back-off: 50 ms × attempt + small random jitter (max 20 ms)
                if ($attempt < self::MAX_DEADLOCK_RETRIES) {
                    usleep((50_000 * $attempt) + random_int(0, 20_000));
                }
            }
        }

        // All retries exhausted
        if ($logger) {
            $logger->error('Wallet payout aborted: deadlock retry limit reached', [
                'reporter_user_id' => $reporterUserId,
                'agency_user_id'   => $agencyUserId,
                'gross_amount'     => $grossAmount,
                'max_attempts'     => self::MAX_DEADLOCK_RETRIES,
                'last_error'       => $lastException?->getMessage(),
            ]);
        }

        return [
            'success'  => false,
            'error'    => 'Deadlock retry limit exceeded: ' . ($lastException?->getMessage() ?? ''),
            'attempts' => self::MAX_DEADLOCK_RETRIES,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Execute one attempt of the atomic payout transaction.
     *
     * Lock ordering: wallets are always locked by ascending wallet.id so that
     * two concurrent transactions always acquire locks in the same global order,
     * eliminating the circular-wait that causes InnoDB deadlocks.
     *
     * @throws \PDOException  On any database error (including deadlock).
     */
    private static function executePayoutTransaction(
        PDO     $pdo,
        int     $reporterUserId,
        int     $agencyUserId,
        float   $grossAmount,
        string  $description,
        ?object $logger
    ): array {
        $reporterShare = round($grossAmount * (1.0 - self::AGENCY_CUT_RATE), 2);
        $agencyCut     = round($grossAmount * self::AGENCY_CUT_RATE, 2);
        $referenceId   = self::generateReferenceId();

        $pdo->beginTransaction();

        // Resolve wallet IDs first (no lock yet) so we know the ordering
        $reporterWalletId = self::resolveWalletId($pdo, $reporterUserId, 'reporter');
        $agencyWalletId   = self::resolveWalletId($pdo, $agencyUserId, 'agency');

        // Acquire SELECT FOR UPDATE in ascending wallet-id order
        if ($reporterWalletId <= $agencyWalletId) {
            $reporterWallet = self::lockWallet($pdo, $reporterWalletId);
            $agencyWallet   = self::lockWallet($pdo, $agencyWalletId);
        } else {
            $agencyWallet   = self::lockWallet($pdo, $agencyWalletId);
            $reporterWallet = self::lockWallet($pdo, $reporterWalletId);
        }

        // Compute new balances
        $reporterBalanceBefore = $reporterWallet['balance'];
        $agencyBalanceBefore   = $agencyWallet['balance'];
        $reporterBalanceAfter  = round($reporterBalanceBefore + $reporterShare, 2);
        $agencyBalanceAfter    = round($agencyBalanceBefore   + $agencyCut, 2);

        // Update reporter wallet
        self::applyBalanceUpdate($pdo, $reporterWalletId, $reporterBalanceAfter, $reporterShare);

        // Update agency wallet
        self::applyBalanceUpdate($pdo, $agencyWalletId, $agencyBalanceAfter, $agencyCut);

        // Log reporter credit
        self::insertTransactionLog(
            $pdo,
            $referenceId,
            $reporterWalletId,
            $reporterUserId,
            'reporter',
            'credit',
            $reporterShare,
            $reporterBalanceBefore,
            $reporterBalanceAfter,
            'Reporter payout' . ($description !== '' ? ': ' . $description : ''),
            $agencyUserId
        );

        // Log agency credit
        self::insertTransactionLog(
            $pdo,
            $referenceId,
            $agencyWalletId,
            $agencyUserId,
            'agency',
            'credit',
            $agencyCut,
            $agencyBalanceBefore,
            $agencyBalanceAfter,
            'Agency cut (10%)' . ($description !== '' ? ': ' . $description : ''),
            $reporterUserId
        );

        $pdo->commit();

        if ($logger) {
            $logger->info('Reporter payout completed', [
                'reference_id'           => $referenceId,
                'reporter_user_id'       => $reporterUserId,
                'agency_user_id'         => $agencyUserId,
                'gross_amount'           => $grossAmount,
                'reporter_share'         => $reporterShare,
                'agency_cut'             => $agencyCut,
                'reporter_balance_after' => $reporterBalanceAfter,
                'agency_balance_after'   => $agencyBalanceAfter,
            ]);
        }

        return [
            'success'                => true,
            'reference_id'           => $referenceId,
            'reporter_share'         => $reporterShare,
            'agency_cut'             => $agencyCut,
            'reporter_balance_after' => $reporterBalanceAfter,
            'agency_balance_after'   => $agencyBalanceAfter,
        ];
    }

    /**
     * Return the wallet.id for the given user, creating the row if absent.
     *
     * INSERT IGNORE + SELECT guarantees exactly one row even under race
     * conditions without requiring a transaction.
     */
    private static function resolveWalletId(PDO $pdo, int $userId, string $userType): int
    {
        $isMySQL = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($isMySQL) {
            $pdo->prepare(
                'INSERT IGNORE INTO wallets (user_id, user_type, balance, total_earned)
                 VALUES (?, ?, 0.00, 0.00)'
            )->execute([$userId, $userType]);
        } else {
            // SQLite (tests): INSERT OR IGNORE
            $pdo->prepare(
                'INSERT OR IGNORE INTO wallets (user_id, user_type, balance, total_earned)
                 VALUES (?, ?, 0.00, 0.00)'
            )->execute([$userId, $userType]);
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM wallets WHERE user_id = ? AND user_type = ?'
        );
        $stmt->execute([$userId, $userType]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException(
                "Wallet row not found for user_id={$userId} user_type={$userType}"
            );
        }

        return (int)$id;
    }

    /**
     * Lock a wallet row for the duration of the current transaction.
     *
     * Uses SELECT … FOR UPDATE on MySQL to acquire an exclusive row lock.
     * SQLite (used in unit tests) does not support FOR UPDATE — the clause
     * is omitted so test code runs against the same logic path.
     *
     * @return array{id: int, balance: float, total_earned: float}
     * @throws \RuntimeException  If the wallet row does not exist.
     */
    private static function lockWallet(PDO $pdo, int $walletId): array
    {
        $isMySQL   = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $forUpdate = $isMySQL ? 'FOR UPDATE' : '';

        $stmt = $pdo->prepare(
            "SELECT id, balance, total_earned
               FROM wallets
              WHERE id = ?
              {$forUpdate}"
        );
        $stmt->execute([$walletId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Wallet id={$walletId} not found");
        }

        return [
            'id'           => (int)$row['id'],
            'balance'      => (float)$row['balance'],
            'total_earned' => (float)$row['total_earned'],
        ];
    }

    /**
     * Update wallet balance and running total_earned counter.
     *
     * Called only inside an open transaction where the row is already locked.
     */
    private static function applyBalanceUpdate(
        PDO   $pdo,
        int   $walletId,
        float $newBalance,
        float $creditAmount
    ): void {
        $pdo->prepare(
            'UPDATE wallets
                SET balance      = ?,
                    total_earned = total_earned + ?
              WHERE id = ?'
        )->execute([$newBalance, $creditAmount, $walletId]);
    }

    /**
     * Append one row to the immutable transactions_log table.
     */
    private static function insertTransactionLog(
        PDO    $pdo,
        string $referenceId,
        int    $walletId,
        int    $userId,
        string $userType,
        string $type,
        float  $amount,
        float  $balanceBefore,
        float  $balanceAfter,
        string $description,
        int    $relatedUserId
    ): void {
        $pdo->prepare(
            'INSERT INTO transactions_log
                (reference_id, wallet_id, user_id, user_type,
                 type, amount, balance_before, balance_after,
                 description, related_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $referenceId,
            $walletId,
            $userId,
            $userType,
            $type,
            $amount,
            $balanceBefore,
            $balanceAfter,
            $description,
            $relatedUserId,
        ]);
    }

    /**
     * Generate a random UUID v4 string (RFC 4122 format).
     */
    private static function generateReferenceId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant bits

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
