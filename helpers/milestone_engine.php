<?php
/**
 * helpers/milestone_engine.php
 *
 * MilestoneEngine — 7-day / 30-day / 90-day milestone check & grant.
 *
 * Usage:
 *   require_once __DIR__ . '/milestone_engine.php';
 *   require_once __DIR__ . '/reward_config.php';
 *
 *   MilestoneEngine::init($pdo, $redis);
 *
 *   // Cron se:
 *   $result = MilestoneEngine::checkAndGrant($userId);
 *   // ['granted' => ['7_day'], 'skipped' => ['30_day', '90_day']]
 *
 *   // Activity record karo:
 *   MilestoneEngine::recordActivity($userId, 'article_read', ['article_id' => 123]);
 *
 *   // Flutter API ke liye:
 *   $progress = MilestoneEngine::getProgress($userId);
 */

declare(strict_types=1);

class MilestoneEngine
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
    // checkAndGrant() — cron se call karo har active user ke liye
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * @return array{granted: list<string>, skipped: list<string>}
     */
    public static function checkAndGrant(string $userId): array
    {
        if (self::$pdo === null) {
            throw new RuntimeException('MilestoneEngine not initialised — call init() first');
        }

        $granted = [];
        $skipped = [];

        // ── a) user_reward_progress fetch karo ───────────────────────────────
        $progress = self::fetchProgress($userId);
        if ($progress === null) {
            // Nayi row create karo
            $progress = self::createProgress($userId);
        }

        // ── b) wallet_type check ──────────────────────────────────────────────
        $walletType = self::getUserWalletType($userId);

        // ── c) Teen milestones check karo ─────────────────────────────────────
        $milestones = [
            '7_day'  => [
                'done_col'    => 'milestone_7day_done',
                'date_col'    => 'milestone_7day_date',
                'config_inr'  => 'reward_7day_inr',
                'config_coins'=> 'reward_7day_coins',
                'checker'     => fn() => self::check7Day($progress),
            ],
            '30_day' => [
                'done_col'    => 'milestone_30day_done',
                'date_col'    => 'milestone_30day_date',
                'config_inr'  => 'reward_30day_inr',
                'config_coins'=> 'reward_30day_coins',
                'checker'     => fn() => self::check30Day($progress),
                'requires'    => '7_day',
            ],
            '90_day' => [
                'done_col'    => 'milestone_90day_done',
                'date_col'    => 'milestone_90day_date',
                'config_inr'  => 'reward_90day_inr',
                'config_coins'=> 'reward_90day_coins',
                'checker'     => fn() => self::check90Day($progress),
                'requires'    => '30_day',
            ],
        ];

        foreach ($milestones as $key => $cfg) {
            // ── d) Already done hai toh skip ──────────────────────────────────
            if (!empty($progress[$cfg['done_col']])) {
                $skipped[] = $key;
                continue;
            }

            // Prerequisite check (30-day ke liye 7-day zaroori, etc.)
            if (isset($cfg['requires'])) {
                $reqDoneCol = 'milestone_' . str_replace('-', '', $cfg['requires']) . '_done';
                // Normalize: '7_day' → 'milestone_7day_done'
                $reqDoneCol = 'milestone_' . str_replace('_day', 'day', $cfg['requires']) . '_done';
                if (empty($progress[$reqDoneCol])) {
                    $skipped[] = $key;
                    continue;
                }
            }

            // ── c) Activity requirements check ────────────────────────────────
            if (!($cfg['checker'])()) {
                $skipped[] = $key;
                continue;
            }

            // ── e) Budget check ───────────────────────────────────────────────
            $rewardAmount = self::getRewardAmount($walletType, $cfg['config_inr'], $cfg['config_coins']);
            if (!RewardConfig::checkBudget($walletType === 'inr' ? $rewardAmount : 0.0)) {
                error_log("MilestoneEngine: Budget exceeded for user {$userId} milestone {$key}");
                $skipped[] = $key;
                continue;
            }

            // ── g) Wallet credit karo ─────────────────────────────────────────
            self::creditWallet($userId, $walletType, $rewardAmount);

            // ── h) reward_transactions log karo ──────────────────────────────
            $txId = self::logTransaction($userId, $walletType, $rewardAmount, "milestone_{$key}", [
                'milestone'           => $key,
                'reward_amount'       => $rewardAmount,
                'wallet_type'         => $walletType,
                'config_inr'          => RewardConfig::get($cfg['config_inr'], 0),
                'config_coins'        => RewardConfig::get($cfg['config_coins'], 0),
                'req_7day_min_days'   => RewardConfig::get('req_7day_min_days', 7),
                'req_7day_min_articles' => RewardConfig::get('req_7day_min_articles', 21),
                'req_30day_min_active'  => RewardConfig::get('req_30day_min_active', 20),
                'req_90day_min_active'  => RewardConfig::get('req_90day_min_active', 60),
            ]);

            // ── i) user_reward_progress update karo ──────────────────────────
            self::markMilestoneDone($userId, $cfg['done_col'], $cfg['date_col']);

            // ── j) Budget track karo ──────────────────────────────────────────
            if ($walletType === 'inr') {
                RewardConfig::trackBudgetSpend($rewardAmount);
            }

            $granted[] = $key;
        }

        return ['granted' => $granted, 'skipped' => $skipped];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getProgress() — Flutter API ke liye
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * @return array<string, mixed>
     */
    public static function getProgress(string $userId): array
    {
        $progress = self::fetchProgress($userId);
        if ($progress === null) {
            $progress = self::createProgress($userId);
        }

        // Config values
        $req7Days     = (int)RewardConfig::get('req_7day_min_days', 7);
        $req7Articles = (int)RewardConfig::get('req_7day_min_articles', 21);
        $req7Shares   = (int)RewardConfig::get('req_7day_min_shares', 1);
        $req30Days    = (int)RewardConfig::get('req_30day_min_active', 20);
        $req30Articles= (int)RewardConfig::get('req_30day_min_articles', 50);
        $req90Days    = (int)RewardConfig::get('req_90day_min_active', 60);

        $activeDays    = (int)($progress['total_active_days'] ?? 0);
        $articlesRead  = (int)($progress['total_articles_read'] ?? 0);
        $totalShares   = (int)($progress['total_shares'] ?? 0);

        // 7-day status
        $done7 = !empty($progress['milestone_7day_done']);
        $pct7  = min(100, (int)round(
            ((min($activeDays, $req7Days) / max($req7Days, 1)) * 40
            + (min($articlesRead, $req7Articles) / max($req7Articles, 1)) * 40
            + (min($totalShares, $req7Shares) / max($req7Shares, 1)) * 20)
        ));

        // 30-day status
        $done30 = !empty($progress['milestone_30day_done']);
        $pct30  = min(100, (int)round(
            ((min($activeDays, $req30Days) / max($req30Days, 1)) * 60
            + (min($articlesRead, $req30Articles) / max($req30Articles, 1)) * 40)
        ));

        // 90-day status — locked until 30-day done
        $done90   = !empty($progress['milestone_90day_done']);
        $locked90 = !$done30;
        $pct90    = $locked90 ? 0 : min(100, (int)round(
            ($activeDays / max($req90Days, 1)) * 100
        ));

        return [
            '7_day' => [
                'status'        => $done7 ? 'completed' : 'in_progress',
                'active_days'   => $activeDays,
                'req_days'      => $req7Days,
                'articles_read' => $articlesRead,
                'req_articles'  => $req7Articles,
                'shares'        => $totalShares,
                'req_shares'    => $req7Shares,
                'percent'       => $done7 ? 100 : $pct7,
                'earned_on'     => $progress['milestone_7day_date'] ?? null,
            ],
            '30_day' => [
                'status'        => $done30 ? 'completed' : ($done7 ? 'in_progress' : 'locked'),
                'active_days'   => $activeDays,
                'req_days'      => $req30Days,
                'articles_read' => $articlesRead,
                'req_articles'  => $req30Articles,
                'percent'       => $done30 ? 100 : $pct30,
                'earned_on'     => $progress['milestone_30day_date'] ?? null,
            ],
            '90_day' => [
                'status'        => $done90 ? 'completed' : ($locked90 ? 'locked' : 'in_progress'),
                'active_days'   => $activeDays,
                'req_days'      => $req90Days,
                'percent'       => $done90 ? 100 : $pct90,
                'unlock_after'  => $locked90 ? '30_day milestone complete karo' : null,
                'earned_on'     => $progress['milestone_90day_date'] ?? null,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recordActivity() — article_read, share, session_open, article_published
    // ─────────────────────────────────────────────────────────────────────────
    public static function recordActivity(string $userId, string $action, array $data = []): void
    {
        if (self::$pdo === null) {
            return;
        }

        $today = date('Y-m-d');

        // user_daily_activity upsert karo
        switch ($action) {
            case 'article_read':
                self::$pdo->prepare(
                    'INSERT INTO user_daily_activity
                        (user_id, activity_date, articles_read, minutes_active)
                     VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE
                        articles_read  = articles_read + 1,
                        minutes_active = minutes_active + VALUES(minutes_active),
                        updated_at     = NOW()'
                )->execute([$userId, $today, (int)($data['minutes'] ?? 5)]);
                break;

            case 'share':
                self::$pdo->prepare(
                    'INSERT INTO user_daily_activity
                        (user_id, activity_date, shares_count)
                     VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        shares_count = shares_count + 1,
                        updated_at   = NOW()'
                )->execute([$userId, $today]);
                break;

            case 'session_open':
                self::$pdo->prepare(
                    'INSERT INTO user_daily_activity
                        (user_id, activity_date, minutes_active)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        minutes_active = minutes_active + VALUES(minutes_active),
                        updated_at     = NOW()'
                )->execute([$userId, $today, (int)($data['minutes'] ?? 1)]);
                break;

            case 'article_published':
                self::$pdo->prepare(
                    'INSERT INTO user_daily_activity
                        (user_id, activity_date, articles_published)
                     VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        articles_published = articles_published + 1,
                        updated_at         = NOW()'
                )->execute([$userId, $today]);
                break;
        }

        // user_reward_progress ka last_active_date + totals update karo
        self::recalculateTotals($userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

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
    private static function createProgress(string $userId): array
    {
        $walletType = self::getUserWalletType($userId);
        self::$pdo->prepare(
            'INSERT IGNORE INTO user_reward_progress
                (user_id, wallet_type, last_active_date, created_at)
             VALUES (?, ?, CURDATE(), NOW())'
        )->execute([$userId, $walletType]);

        return self::fetchProgress($userId) ?? [
            'user_id'             => $userId,
            'wallet_type'         => $walletType,
            'total_active_days'   => 0,
            'total_articles_read' => 0,
            'total_shares'        => 0,
            'milestone_7day_done' => 0,
            'milestone_30day_done'=> 0,
            'milestone_90day_done'=> 0,
        ];
    }

    private static function getUserWalletType(string $userId): string
    {
        // India users → INR, baaki sab → coins
        // Check inr_wallets mein exists ki nahi
        $stmt = self::$pdo->prepare(
            'SELECT wallet_type FROM user_reward_progress WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['wallet_type'])) {
            return $row['wallet_type'];
        }

        // Default: inr_wallets check karo
        $stmt2 = self::$pdo->prepare(
            'SELECT id FROM inr_wallets WHERE user_id = ? LIMIT 1'
        );
        $stmt2->execute([$userId]);
        return $stmt2->fetch() ? 'inr' : 'coins';
    }

    private static function check7Day(array $progress): bool
    {
        $minDays     = (int)RewardConfig::get('req_7day_min_days', 7);
        $minArticles = (int)RewardConfig::get('req_7day_min_articles', 21);
        $minShares   = (int)RewardConfig::get('req_7day_min_shares', 1);

        return (int)($progress['total_active_days'] ?? 0)   >= $minDays
            && (int)($progress['total_articles_read'] ?? 0) >= $minArticles
            && (int)($progress['total_shares'] ?? 0)        >= $minShares;
    }

    private static function check30Day(array $progress): bool
    {
        $minActive   = (int)RewardConfig::get('req_30day_min_active', 20);
        $minArticles = (int)RewardConfig::get('req_30day_min_articles', 50);

        return (int)($progress['total_active_days'] ?? 0)   >= $minActive
            && (int)($progress['total_articles_read'] ?? 0) >= $minArticles;
    }

    private static function check90Day(array $progress): bool
    {
        $minActive = (int)RewardConfig::get('req_90day_min_active', 60);
        return (int)($progress['total_active_days'] ?? 0) >= $minActive;
    }

    private static function getRewardAmount(string $walletType, string $inrKey, string $coinsKey): float
    {
        if ($walletType === 'inr') {
            return (float)RewardConfig::get($inrKey, 0);
        }
        return (float)RewardConfig::get($coinsKey, 0);
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

    private static function logTransaction(
        string $userId,
        string $walletType,
        float  $amount,
        string $type,
        array  $configSnapshot
    ): int {
        self::$pdo->prepare(
            'INSERT INTO reward_transactions
                (user_id, wallet_type, transaction_type, amount,
                 reward_config_snapshot, status, created_at)
             VALUES (?, ?, ?, ?, ?, \'completed\', NOW())'
        )->execute([
            $userId,
            $walletType,
            $type,
            $amount,
            json_encode($configSnapshot),
        ]);
        return (int)self::$pdo->lastInsertId();
    }

    private static function markMilestoneDone(string $userId, string $doneCol, string $dateCol): void
    {
        self::$pdo->prepare(
            "UPDATE user_reward_progress
             SET {$doneCol} = 1, {$dateCol} = NOW(), updated_at = NOW()
             WHERE user_id = ?"
        )->execute([$userId]);
    }

    private static function recalculateTotals(string $userId): void
    {
        // Active days = days mein koi bhi activity thi (last 90 days for efficiency)
        $activeDays = self::$pdo->prepare(
            'SELECT COUNT(DISTINCT activity_date) AS cnt
             FROM user_daily_activity
             WHERE user_id = ?
               AND (articles_read > 0 OR shares_count > 0 OR minutes_active > 0)'
        );
        $activeDays->execute([$userId]);
        $days = (int)($activeDays->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        // Total articles
        $artStmt = self::$pdo->prepare(
            'SELECT COALESCE(SUM(articles_read), 0) AS total
             FROM user_daily_activity WHERE user_id = ?'
        );
        $artStmt->execute([$userId]);
        $totalArticles = (int)($artStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Total shares
        $shareStmt = self::$pdo->prepare(
            'SELECT COALESCE(SUM(shares_count), 0) AS total
             FROM user_daily_activity WHERE user_id = ?'
        );
        $shareStmt->execute([$userId]);
        $totalShares = (int)($shareStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        self::$pdo->prepare(
            'INSERT INTO user_reward_progress
                (user_id, total_active_days, total_articles_read,
                 total_shares, last_active_date, updated_at)
             VALUES (?, ?, ?, ?, CURDATE(), NOW())
             ON DUPLICATE KEY UPDATE
                total_active_days   = VALUES(total_active_days),
                total_articles_read = VALUES(total_articles_read),
                total_shares        = VALUES(total_shares),
                last_active_date    = CURDATE(),
                updated_at          = NOW()'
        )->execute([$userId, $days, $totalArticles, $totalShares]);
    }
}
