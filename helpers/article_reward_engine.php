<?php
/**
 * helpers/article_reward_engine.php
 *
 * ArticleRewardEngine — jab reporter ka article approve ho tab INR reward grant karo.
 *
 * Sirf INR wallet users ko milega.
 * Monthly cap + per-user cap dono check hota hai.
 *
 * Usage:
 *   require_once __DIR__ . '/article_reward_engine.php';
 *   require_once __DIR__ . '/reward_config.php';
 *
 *   ArticleRewardEngine::init($pdo, $redis);
 *
 *   // Admin panel se jab article approve ho:
 *   $result = ArticleRewardEngine::grantReward($userId);
 *   // ['granted' => true, 'amount' => 5.0, 'reason' => 'Article reward granted']
 */

declare(strict_types=1);

class ArticleRewardEngine
{
    private static ?PDO   $pdo   = null;
    private static ?Redis $redis = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Dependency injection
    // ─────────────────────────────────────────────────────────────────────────
    public static function init(PDO $pdo, ?Redis $redis = null): void
    {
        self::$pdo   = $pdo;
        self::$redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // grantReward() — jab article approve ho tab call karo
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * @return array{granted: bool, amount: float, reason: string}
     */
    public static function grantReward(string $userId): array
    {
        if (self::$pdo === null) {
            throw new RuntimeException('ArticleRewardEngine not initialised — call init() first');
        }

        // ── a) article_rewards_active config check karo ───────────────────────
        $active = (bool)RewardConfig::get('article_rewards_active', true);
        if (!$active) {
            return ['granted' => false, 'amount' => 0.0, 'reason' => 'Article rewards disabled'];
        }

        // ── b) Sirf INR wallet users ko milega ───────────────────────────────
        $walletType = self::getUserWalletType($userId);
        if ($walletType !== 'inr') {
            return [
                'granted' => false,
                'amount'  => 0.0,
                'reason'  => 'Article rewards only for INR wallet users',
            ];
        }

        // ── c) user_reward_progress fetch karo ───────────────────────────────
        $progress = self::fetchProgress($userId);
        if ($progress === null) {
            $progress = self::initProgress($userId);
        }

        $currentMonth = date('Y-m');

        // ── d) reward_month check karo — nayi month toh reset karo ───────────
        $rewardMonth = $progress['reward_month'] ?? null;
        if ($rewardMonth !== $currentMonth) {
            self::$pdo->prepare(
                'UPDATE user_reward_progress
                 SET article_rewards_this_month = 0,
                     reward_month               = ?,
                     updated_at                 = NOW()
                 WHERE user_id = ?'
            )->execute([$currentMonth, $userId]);

            $progress['article_rewards_this_month'] = 0.0;
            $progress['total_rewards_this_month']   = 0.0;
            $progress['reward_month']               = $currentMonth;
        }

        $rewardAmount         = (float)RewardConfig::get('reward_article_inr', 5.0);
        $articleMonthlyCap    = (float)RewardConfig::get('reward_article_monthly_cap', 50.0);
        $perUserMonthlyCap    = (float)RewardConfig::get('per_user_monthly_cap', 50.0);
        $articlesThisMonth    = (float)($progress['article_rewards_this_month'] ?? 0);
        $totalThisMonth       = (float)($progress['total_rewards_this_month'] ?? 0);

        // ── e) Monthly article cap check ──────────────────────────────────────
        if (($articlesThisMonth + $rewardAmount) > $articleMonthlyCap) {
            return [
                'granted' => false,
                'amount'  => 0.0,
                'reason'  => "Article monthly cap reached (₹{$articleMonthlyCap})",
            ];
        }

        // ── f) Per-user monthly cap check ─────────────────────────────────────
        if (($totalThisMonth + $rewardAmount) > $perUserMonthlyCap) {
            return [
                'granted' => false,
                'amount'  => 0.0,
                'reason'  => "Per-user monthly cap reached (₹{$perUserMonthlyCap})",
            ];
        }

        // ── g) Budget check ───────────────────────────────────────────────────
        if (!RewardConfig::checkBudget($rewardAmount)) {
            return [
                'granted' => false,
                'amount'  => 0.0,
                'reason'  => 'Daily/monthly budget exceeded',
            ];
        }

        // ── i) inr_wallets balance update karo ───────────────────────────────
        self::$pdo->prepare(
            'INSERT INTO inr_wallets (user_id, balance, total_earned, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                balance      = balance + VALUES(balance),
                total_earned = total_earned + VALUES(total_earned),
                updated_at   = NOW()'
        )->execute([$userId, $rewardAmount, $rewardAmount]);

        // ── j) reward_transactions log karo ──────────────────────────────────
        self::$pdo->prepare(
            'INSERT INTO reward_transactions
                (user_id, wallet_type, transaction_type, amount,
                 reward_config_snapshot, status, created_at)
             VALUES (?, \'inr\', \'article_reward\', ?, ?, \'completed\', NOW())'
        )->execute([
            $userId,
            $rewardAmount,
            json_encode([
                'reward_article_inr'         => $rewardAmount,
                'reward_article_monthly_cap' => $articleMonthlyCap,
                'per_user_monthly_cap'       => $perUserMonthlyCap,
                'article_rewards_this_month' => $articlesThisMonth,
                'total_rewards_this_month'   => $totalThisMonth,
            ]),
        ]);

        // ── k) user_reward_progress update karo ──────────────────────────────
        self::$pdo->prepare(
            'UPDATE user_reward_progress
             SET article_rewards_this_month = article_rewards_this_month + ?,
                 total_rewards_this_month   = total_rewards_this_month + ?,
                 reward_month               = ?,
                 updated_at                 = NOW()
             WHERE user_id = ?'
        )->execute([$rewardAmount, $rewardAmount, $currentMonth, $userId]);

        // ── l) Budget track karo ──────────────────────────────────────────────
        RewardConfig::trackBudgetSpend($rewardAmount);

        return [
            'granted' => true,
            'amount'  => $rewardAmount,
            'reason'  => 'Article reward granted',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function getUserWalletType(string $userId): string
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

    /** @return array<string,mixed>|null */
    private static function fetchProgress(string $userId): ?array
    {
        $stmt = self::$pdo->prepare(
            'SELECT * FROM user_reward_progress WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    private static function initProgress(string $userId): array
    {
        $walletType = self::getUserWalletType($userId);
        self::$pdo->prepare(
            'INSERT IGNORE INTO user_reward_progress
                (user_id, wallet_type, reward_month, created_at)
             VALUES (?, ?, ?, NOW())'
        )->execute([$userId, $walletType, date('Y-m')]);

        return self::fetchProgress($userId) ?? [
            'user_id'                  => $userId,
            'wallet_type'              => $walletType,
            'article_rewards_this_month' => 0.0,
            'total_rewards_this_month' => 0.0,
            'reward_month'             => date('Y-m'),
        ];
    }
}
