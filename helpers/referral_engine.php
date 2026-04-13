<?php
/**
 * helpers/referral_engine.php
 *
 * ReferralEngine — referral code generation, registration & reward distribution.
 *
 * Usage:
 *   require_once __DIR__ . '/referral_engine.php';
 *   require_once __DIR__ . '/reward_config.php';
 *   require_once __DIR__ . '/referral_fraud.php';
 *
 *   ReferralEngine::init($pdo, $redis);
 *
 *   $code   = ReferralEngine::generateCode($userId);   // 'NXL5AB3X'
 *   $result = ReferralEngine::registerReferral($code, $refereeUid, $ip, $fp, 'IN');
 *   ReferralEngine::grantReferralBonus($refereeUid);
 *   ReferralEngine::processLifetimeShare($refereeUid, 10.00);
 */

declare(strict_types=1);

class ReferralEngine
{
    private static ?PDO   $pdo   = null;
    private static ?Redis $redis = null;

    // Code prefix
    private const CODE_PREFIX = 'NXL';
    private const CODE_LEN    = 5;   // prefix ke baad chars

    // ─────────────────────────────────────────────────────────────────────────
    // Dependency injection
    // ─────────────────────────────────────────────────────────────────────────
    public static function init(PDO $pdo, ?Redis $redis = null): void
    {
        self::$pdo   = $pdo;
        self::$redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateCode() — unique 8-char referral code
    // ─────────────────────────────────────────────────────────────────────────
    public static function generateCode(string $userId): string
    {
        if (self::$pdo === null) {
            throw new RuntimeException('ReferralEngine not initialised — call init() first');
        }

        // Agar already exists toh return kar do
        $stmt = self::$pdo->prepare(
            'SELECT referral_code FROM referral_codes
             WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return $existing['referral_code'];
        }

        // Collision-free unique code generate karo
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = self::CODE_PREFIX . strtoupper(substr(
                base_convert(bin2hex(random_bytes(4)), 16, 36),
                0,
                self::CODE_LEN
            ));

            // Collision check
            $checkStmt = self::$pdo->prepare(
                'SELECT id FROM referral_codes WHERE referral_code = ? LIMIT 1'
            );
            $checkStmt->execute([$code]);
            if (!$checkStmt->fetch()) {
                // Insert karo
                self::$pdo->prepare(
                    'INSERT INTO referral_codes
                        (user_id, referral_code, is_active, created_at)
                     VALUES (?, ?, 1, NOW())'
                )->execute([$userId, $code]);
                return $code;
            }
        }

        throw new RuntimeException('Unique referral code generate nahi hua — try again');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // registerReferral() — referee ka sign-up link karo referral se
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * @return array{success: bool, fraud_action: string, referral_id: int|null, message: string}
     */
    public static function registerReferral(
        string $referralCode,
        string $refereeUid,
        string $ip,
        string $deviceFingerprint,
        string $country
    ): array {
        if (self::$pdo === null) {
            throw new RuntimeException('ReferralEngine not initialised — call init() first');
        }

        // ── a) Code se referrer_uid fetch karo ───────────────────────────────
        $codeStmt = self::$pdo->prepare(
            'SELECT id AS code_id, user_id AS referrer_uid
             FROM referral_codes
             WHERE referral_code = ? AND is_active = 1 LIMIT 1'
        );
        $codeStmt->execute([$referralCode]);
        $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeRow) {
            return [
                'success'      => false,
                'fraud_action' => 'block',
                'referral_id'  => null,
                'message'      => 'Invalid or inactive referral code',
            ];
        }

        $referrerUid = $codeRow['referrer_uid'];
        $codeId      = (int)$codeRow['code_id'];

        // ── b) Self-referral check ────────────────────────────────────────────
        if ($referrerUid === $refereeUid) {
            return [
                'success'      => false,
                'fraud_action' => 'block',
                'referral_id'  => null,
                'message'      => 'Self-referral not allowed',
            ];
        }

        // ── b2) Duplicate referral check — ek user ek baar hi register ho ────
        $dupStmt = self::$pdo->prepare(
            'SELECT id FROM referrals WHERE referee_uid = ? LIMIT 1'
        );
        $dupStmt->execute([$refereeUid]);
        if ($dupStmt->fetch()) {
            return [
                'success'      => false,
                'fraud_action' => 'block',
                'referral_id'  => null,
                'message'      => 'User already referred',
            ];
        }

        // ── c) Fraud check ────────────────────────────────────────────────────
        $fraudResult = ReferralFraudDetector::analyze(
            ip:                $ip,
            deviceFingerprint: $deviceFingerprint,
            referrerUid:       $referrerUid,
            refereeUid:        $refereeUid,
            country:           $country
        );

        $fraudAction = $fraudResult['action'];
        $fraudScore  = $fraudResult['score'];
        $fraudFlags  = $fraudResult['flags'];

        // ── d) Status decide karo ─────────────────────────────────────────────
        $status = match($fraudAction) {
            'block' => 'blocked',
            'flag'  => 'flagged',
            default => 'active',
        };

        // Insert referral record
        self::$pdo->prepare(
            'INSERT INTO referrals
                (referrer_uid, referee_uid, referral_code_id, status,
                 device_fingerprint, referee_ip_hash, referee_country,
                 fraud_score, fraud_flags, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $referrerUid,
            $refereeUid,
            $codeId,
            $status,
            $deviceFingerprint,
            hash('sha256', $ip),
            $country,
            $fraudScore,
            json_encode($fraudFlags),
        ]);
        $referralId = (int)self::$pdo->lastInsertId();

        // ── d2) Flag hone par fraud_flags table mein log karo ─────────────────
        if ($fraudAction === 'flag' || $fraudAction === 'block') {
            self::$pdo->prepare(
                'INSERT INTO referral_fraud_flags
                    (referral_id, referrer_uid, referee_uid, fraud_score,
                     fraud_flags, status, created_at)
                 VALUES (?, ?, ?, ?, ?, \'pending_review\', NOW())'
            )->execute([
                $referralId,
                $referrerUid,
                $refereeUid,
                $fraudScore,
                json_encode($fraudFlags),
            ]);
        }

        // ── e) total_referrals increment karo ────────────────────────────────
        self::$pdo->prepare(
            'UPDATE referral_codes
             SET total_referrals = total_referrals + 1, updated_at = NOW()
             WHERE id = ?'
        )->execute([$codeId]);

        return [
            'success'      => ($fraudAction === 'allow'),
            'fraud_action' => $fraudAction,
            'referral_id'  => $referralId,
            'message'      => $fraudAction === 'allow'
                              ? 'Referral registered successfully'
                              : "Referral {$status}",
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // grantReferralBonus() — jab referee 7-day milestone complete kare
    // ─────────────────────────────────────────────────────────────────────────
    public static function grantReferralBonus(string $refereeUid): void
    {
        if (self::$pdo === null) {
            return;
        }

        // ── a) Referee ka referral record fetch karo ──────────────────────────
        $stmt = self::$pdo->prepare(
            'SELECT r.*, rc.user_id AS referrer_id
             FROM referrals r
             JOIN referral_codes rc ON rc.id = r.referral_code_id
             WHERE r.referee_uid = ? AND r.status = \'active\' LIMIT 1'
        );
        $stmt->execute([$refereeUid]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referral) {
            return; // No active referral
        }

        $referrerId  = $referral['referrer_uid'];
        $referralId  = (int)$referral['id'];
        $codeId      = (int)$referral['referral_code_id'];

        // ── c) Referrer ka wallet_type check ──────────────────────────────────
        $walletType = self::getReferrerWalletType($referrerId);

        // ── d) Bonus amount config se lo ──────────────────────────────────────
        $bonusAmount = $walletType === 'inr'
            ? (float)RewardConfig::get('reward_referral_inr', 3.0)
            : (float)RewardConfig::get('reward_referral_coins', 10);

        // ── e) Budget check karo ──────────────────────────────────────────────
        if ($walletType === 'inr' && !RewardConfig::checkBudget($bonusAmount)) {
            error_log("ReferralEngine: Budget exceeded for referral bonus to {$referrerId}");
            return;
        }

        // ── f) Referrer ke wallet mein credit karo ────────────────────────────
        self::creditWallet($referrerId, $walletType, $bonusAmount);

        // ── g) reward_transactions log karo ──────────────────────────────────
        self::$pdo->prepare(
            'INSERT INTO reward_transactions
                (user_id, wallet_type, transaction_type, amount,
                 reference_id, reward_config_snapshot, status, created_at)
             VALUES (?, ?, \'referral_bonus\', ?, ?, ?, \'completed\', NOW())'
        )->execute([
            $referrerId,
            $walletType,
            $bonusAmount,
            $referralId,
            json_encode([
                'referral_id'         => $referralId,
                'referee_uid'         => $refereeUid,
                'reward_referral_inr' => RewardConfig::get('reward_referral_inr', 3),
                'reward_referral_coins'=> RewardConfig::get('reward_referral_coins', 10),
            ]),
        ]);

        // ── h) referral status → 'reward_paid' ───────────────────────────────
        self::$pdo->prepare(
            'UPDATE referrals SET status = \'reward_paid\', updated_at = NOW() WHERE id = ?'
        )->execute([$referralId]);

        // ── i) successful_referrals increment ────────────────────────────────
        self::$pdo->prepare(
            'UPDATE referral_codes
             SET successful_referrals = successful_referrals + 1, updated_at = NOW()
             WHERE id = ?'
        )->execute([$codeId]);

        // Budget track karo
        if ($walletType === 'inr') {
            RewardConfig::trackBudgetSpend($bonusAmount);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // processLifetimeShare() — daily cron se call hoga
    // ─────────────────────────────────────────────────────────────────────────
    public static function processLifetimeShare(string $refereeUid, float $refereeEarning): void
    {
        if (self::$pdo === null || $refereeEarning <= 0.0) {
            return;
        }

        // ── a) Referral record fetch karo (status: reward_paid) ───────────────
        $stmt = self::$pdo->prepare(
            'SELECT r.*, rc.user_id AS referrer_id
             FROM referrals r
             JOIN referral_codes rc ON rc.id = r.referral_code_id
             WHERE r.referee_uid = ? AND r.status = \'reward_paid\' LIMIT 1'
        );
        $stmt->execute([$refereeUid]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referral) {
            return;
        }

        $referrerId = $referral['referrer_uid'];
        $referralId = (int)$referral['id'];

        // ── b) created_at se months calculate karo ───────────────────────────
        $createdAt   = new DateTime($referral['created_at']);
        $now         = new DateTime();
        $monthsElapsed = (int)$createdAt->diff($now)->format('%r%m')
                        + ((int)$createdAt->diff($now)->y * 12);

        // ── c) lifetime_share_months config check ────────────────────────────
        $maxMonths = (int)RewardConfig::get('lifetime_share_months', 12);
        if ($monthsElapsed >= $maxMonths) {
            return; // Limit exceed ho gaya
        }

        // ── d) Share amount calculate karo ───────────────────────────────────
        $percent     = (float)RewardConfig::get('lifetime_share_percent', 2.0);
        $shareAmount = round($refereeEarning * ($percent / 100), 2);

        if ($shareAmount <= 0.0) {
            return;
        }

        // ── e) Budget check karo ──────────────────────────────────────────────
        if (!RewardConfig::checkBudget($shareAmount)) {
            error_log("ReferralEngine: Budget exceeded for lifetime share to {$referrerId}");
            return;
        }

        // ── f) Referrer ke INR wallet mein credit karo ────────────────────────
        self::$pdo->prepare(
            'INSERT INTO inr_wallets (user_id, balance, total_earned, lifetime_share_earned, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                balance               = balance + VALUES(balance),
                total_earned          = total_earned + VALUES(total_earned),
                lifetime_share_earned = lifetime_share_earned + VALUES(lifetime_share_earned),
                updated_at            = NOW()'
        )->execute([$referrerId, $shareAmount, $shareAmount, $shareAmount]);

        // ── h) Transaction log karo ───────────────────────────────────────────
        self::$pdo->prepare(
            'INSERT INTO reward_transactions
                (user_id, wallet_type, transaction_type, amount,
                 reference_id, reward_config_snapshot, status, created_at)
             VALUES (?, \'inr\', \'lifetime_share\', ?, ?, ?, \'completed\', NOW())'
        )->execute([
            $referrerId,
            $shareAmount,
            $referralId,
            json_encode([
                'referral_id'           => $referralId,
                'referee_uid'           => $refereeUid,
                'referee_earning'       => $refereeEarning,
                'lifetime_share_percent'=> $percent,
                'months_elapsed'        => $monthsElapsed,
                'max_months'            => $maxMonths,
            ]),
        ]);

        // Budget track karo
        RewardConfig::trackBudgetSpend($shareAmount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function getReferrerWalletType(string $userId): string
    {
        $stmt = self::$pdo->prepare(
            'SELECT wallet_type FROM user_reward_progress WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['wallet_type'])) {
            return $row['wallet_type'];
        }
        $stmt2 = self::$pdo->prepare(
            'SELECT id FROM inr_wallets WHERE user_id = ? LIMIT 1'
        );
        $stmt2->execute([$userId]);
        return $stmt2->fetch() ? 'inr' : 'coins';
    }

    private static function creditWallet(string $userId, string $walletType, float $amount): void
    {
        if ($walletType === 'inr') {
            self::$pdo->prepare(
                'INSERT INTO inr_wallets (user_id, balance, total_earned, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    balance      = balance + VALUES(balance),
                    total_earned = total_earned + VALUES(total_earned),
                    updated_at   = NOW()'
            )->execute([$userId, $amount, $amount]);
        } else {
            $coins = (int)$amount;
            self::$pdo->prepare(
                'INSERT INTO coins_wallets (user_id, balance, total_earned, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    balance      = balance + VALUES(balance),
                    total_earned = total_earned + VALUES(total_earned),
                    updated_at   = NOW()'
            )->execute([$userId, $coins, $coins]);
        }
    }
}
