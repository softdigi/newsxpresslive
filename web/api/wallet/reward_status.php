<?php
/**
 * web/api/wallet/reward_status.php
 *
 * GET /web/api/wallet/reward_status.php
 * Authenticated user ka full reward wallet status.
 *
 * Auth: Bearer {Firebase ID Token}
 *
 * India user → INR wallet response
 * Global user → Coins wallet response
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../helpers/reward_config.php';
require_once __DIR__ . '/../../../helpers/milestone_engine.php';
require_once __DIR__ . '/../../../helpers/referral_engine.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
$id_token    = '';
$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $id_token = substr($authHeader, 7);
}
if (!$id_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user    = requireAppUser($pdo, $id_token);
$userId  = $user['uid'];

// ── Redis init ────────────────────────────────────────────────────────────────
$redis = null;
try {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: '127.0.0.1',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );
    if ($redisPwd = getenv('REDIS_PASSWORD')) {
        $redis->auth($redisPwd);
    }
} catch (Throwable) {
    $redis = null;
}

// ── Init helpers ──────────────────────────────────────────────────────────────
RewardConfig::init($pdo, $redis);
MilestoneEngine::init($pdo, $redis);
ReferralEngine::init($pdo, $redis);

// ── Fetch wallet type ─────────────────────────────────────────────────────────
$progressStmt = $pdo->prepare(
    'SELECT * FROM user_reward_progress WHERE user_id = ? LIMIT 1'
);
$progressStmt->execute([$userId]);
$progress = $progressStmt->fetch(PDO::FETCH_ASSOC);

$walletType = $progress['wallet_type'] ?? 'coins';

// Wallet type determine karo: inr_wallets mein hai toh INR
if (!$progress) {
    $inrChk = $pdo->prepare('SELECT id FROM inr_wallets WHERE user_id = ? LIMIT 1');
    $inrChk->execute([$userId]);
    $walletType = $inrChk->fetch() ? 'inr' : 'coins';
}

// ── Milestone progress ────────────────────────────────────────────────────────
$milestoneProgress = MilestoneEngine::getProgress($userId);

// ── Referral code / stats ─────────────────────────────────────────────────────
$refCodeStmt = $pdo->prepare(
    'SELECT referral_code, total_referrals, successful_referrals
     FROM referral_codes
     WHERE user_id = ? AND is_active = 1 LIMIT 1'
);
$refCodeStmt->execute([$userId]);
$refCodeRow = $refCodeStmt->fetch(PDO::FETCH_ASSOC);

$referralCode = $refCodeRow['referral_code'] ?? null;
if (!$referralCode) {
    // Auto-generate karo
    try {
        $referralCode = ReferralEngine::generateCode($userId);
        // Re-fetch stats
        $refCodeStmt->execute([$userId]);
        $refCodeRow = $refCodeStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $referralCode = null;
    }
}

$siteUrl   = rtrim(getenv('SITE_URL') ?: 'https://newsxpresslive.com', '/');
$shareUrl  = $referralCode ? "{$siteUrl}/join?ref={$referralCode}" : null;

// Total referral earnings from reward_transactions
$refEarnStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total
     FROM reward_transactions
     WHERE user_id = ?
       AND transaction_type IN (\'referral_bonus\', \'lifetime_share\')
       AND status = \'completed\''
);
$refEarnStmt->execute([$userId]);
$totalReferralEarned = (float)($refEarnStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// Pending bonus (active referrals that haven't completed 7-day yet)
$pendingStmt = $pdo->prepare(
    'SELECT COUNT(*) AS cnt
     FROM referrals
     WHERE referrer_uid = ? AND status = \'active\''
);
$pendingStmt->execute([$userId]);
$pendingBonus = (int)($pendingStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

// ── Config values ─────────────────────────────────────────────────────────────
$reward7InrAmount    = (float)RewardConfig::get('reward_7day_inr', 2.0);
$reward30InrAmount   = (float)RewardConfig::get('reward_30day_inr', 5.0);
$reward90InrAmount   = (float)RewardConfig::get('reward_90day_inr', 15.0);
$reward7CoinsAmount  = (int)RewardConfig::get('reward_7day_coins', 15);
$reward30CoinsAmount = (int)RewardConfig::get('reward_30day_coins', 35);
$reward90CoinsAmount = (int)RewardConfig::get('reward_90day_coins', 100);
$refBonusInr         = (float)RewardConfig::get('reward_referral_inr', 3.0);
$refBonusCoins       = (int)RewardConfig::get('reward_referral_coins', 10);
$lifetimePct         = (float)RewardConfig::get('lifetime_share_percent', 2.0);
$lifetimeMonths      = (int)RewardConfig::get('lifetime_share_months', 12);
$coinInrValue        = (float)RewardConfig::get('coin_inr_value', 0.05);
$minWithdrawal       = (float)RewardConfig::get('min_withdrawal_inr', 100.0);
$minWithdrawDays     = (int)RewardConfig::get('min_withdrawal_delay_days', 7);
$largeWithdraw       = (float)RewardConfig::get('large_withdrawal_threshold', 500.0);

// ── Milestone config detail ───────────────────────────────────────────────────
$req7Days     = (int)RewardConfig::get('req_7day_min_days', 7);
$req7Articles = (int)RewardConfig::get('req_7day_min_articles', 21);
$req7Shares   = (int)RewardConfig::get('req_7day_min_shares', 1);
$req30Days    = (int)RewardConfig::get('req_30day_min_active', 20);
$req30Articles= (int)RewardConfig::get('req_30day_min_articles', 50);
$req90Days    = (int)RewardConfig::get('req_90day_min_active', 60);

// ── Days remaining / estimated date ──────────────────────────────────────────
$activeDays  = (int)($progress['total_active_days'] ?? 0);
$m30Remaining = max(0, $req30Days - $activeDays);
$estimated30  = $m30Remaining > 0
    ? date('Y-m-d', strtotime("+{$m30Remaining} days"))
    : null;

// ── INR wallet ────────────────────────────────────────────────────────────────
$inrWallet = null;
if ($walletType === 'inr') {
    $inrStmt = $pdo->prepare('SELECT * FROM inr_wallets WHERE user_id = ? LIMIT 1');
    $inrStmt->execute([$userId]);
    $inrWallet = $inrStmt->fetch(PDO::FETCH_ASSOC);
}

// ── Coins wallet ──────────────────────────────────────────────────────────────
$coinsWallet = null;
if ($walletType === 'coins') {
    $coinsStmt = $pdo->prepare('SELECT * FROM coins_wallets WHERE user_id = ? LIMIT 1');
    $coinsStmt->execute([$userId]);
    $coinsWallet = $coinsStmt->fetch(PDO::FETCH_ASSOC);
}

// ── Article rewards ───────────────────────────────────────────────────────────
$articleRewardEnabled   = (bool)RewardConfig::get('article_rewards_active', true);
$rewardArticleInr       = (float)RewardConfig::get('reward_article_inr', 5.0);
$articleMonthlyCap      = (float)RewardConfig::get('reward_article_monthly_cap', 50.0);
$perUserMonthlyCap      = (float)RewardConfig::get('per_user_monthly_cap', 50.0);

$currentMonth           = date('Y-m');
$rewardMonth            = $progress['reward_month'] ?? null;
$articlesThisMonth      = ($rewardMonth === $currentMonth)
                          ? (float)($progress['article_rewards_this_month'] ?? 0)
                          : 0.0;
$totalThisMonth         = ($rewardMonth === $currentMonth)
                          ? (float)($progress['total_rewards_this_month'] ?? 0)
                          : 0.0;

$maxArticlesThisMonth   = $rewardArticleInr > 0
                          ? (int)floor($articleMonthlyCap / $rewardArticleInr)
                          : 0;
$articlesCountThisMonth = $rewardArticleInr > 0
                          ? (int)floor($articlesThisMonth / $rewardArticleInr)
                          : 0;
$remainingArticleCap    = max(0.0, $articleMonthlyCap - $articlesThisMonth);

// ── KYC / withdrawal eligibility ──────────────────────────────────────────────
$balance     = $walletType === 'inr'
               ? (float)($inrWallet['balance'] ?? 0)
               : 0.0;
$firstTxStmt = $pdo->prepare(
    'SELECT MIN(created_at) AS first_tx FROM reward_transactions
     WHERE user_id = ? AND status = \'completed\''
);
$firstTxStmt->execute([$userId]);
$firstTxRow      = $firstTxStmt->fetch(PDO::FETCH_ASSOC);
$firstEligDate   = null;
$daysUntilEligible = 0;
if ($firstTxRow && $firstTxRow['first_tx']) {
    $firstEligDate     = date('Y-m-d', strtotime($firstTxRow['first_tx'] . " +{$minWithdrawDays} days"));
    $daysUntilEligible = max(0, (int)ceil((strtotime($firstEligDate) - time()) / 86400));
}
$withdrawEligible = $balance >= $minWithdrawal && $daysUntilEligible <= 0;

// ── Aadhar KYC check (for large withdrawals) ──────────────────────────────────
$kycStmt = $pdo->prepare(
    'SELECT kyc_status FROM reporter_kyc WHERE reporter_uid = ? LIMIT 1'
);
$kycStmt->execute([$userId]);
$kycRow     = $kycStmt->fetch(PDO::FETCH_ASSOC);
$kycVerified = ($kycRow && $kycRow['kyc_status'] === 'approved');

// ── This month summary ────────────────────────────────────────────────────────
$summaryStmt = $pdo->prepare(
    'SELECT transaction_type, COALESCE(SUM(amount), 0) AS total
     FROM reward_transactions
     WHERE user_id = ?
       AND MONTH(created_at) = MONTH(NOW())
       AND YEAR(created_at)  = YEAR(NOW())
       AND status = \'completed\'
     GROUP BY transaction_type'
);
$summaryStmt->execute([$userId]);
$breakdown = ['milestones' => 0.0, 'articles' => 0.0, 'referrals' => 0.0];
foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = $row['transaction_type'];
    $amt  = (float)$row['total'];
    if (str_starts_with($type, 'milestone_')) {
        $breakdown['milestones'] += $amt;
    } elseif ($type === 'article_reward') {
        $breakdown['articles'] += $amt;
    } elseif (in_array($type, ['referral_bonus', 'lifetime_share'], true)) {
        $breakdown['referrals'] += $amt;
    }
}
$totalEarnedThisMonth = array_sum($breakdown);

// ── Build response ────────────────────────────────────────────────────────────
if ($walletType === 'inr') {
    $response = [
        'success'      => true,
        'wallet_type'  => 'inr',
        'balance'      => round((float)($inrWallet['balance'] ?? 0), 2),
        'total_earned' => round((float)($inrWallet['total_earned'] ?? 0), 2),

        'milestones' => [
            '7_day' => [
                'reward'        => "₹{$reward7InrAmount}",
                'status'        => $milestoneProgress['7_day']['status'],
                'earned_on'     => $milestoneProgress['7_day']['earned_on'],
                'earned_amount' => $milestoneProgress['7_day']['status'] === 'completed'
                                   ? $reward7InrAmount : null,
                'progress'      => [
                    'active_days'      => $milestoneProgress['7_day']['active_days'],
                    'required_days'    => $req7Days,
                    'articles_read'    => $milestoneProgress['7_day']['articles_read'],
                    'required_articles'=> $req7Articles,
                    'shares'           => $milestoneProgress['7_day']['shares'],
                    'required_shares'  => $req7Shares,
                    'percent'          => $milestoneProgress['7_day']['percent'],
                ],
            ],
            '30_day' => [
                'reward'          => "₹{$reward30InrAmount}",
                'status'          => $milestoneProgress['30_day']['status'],
                'earned_on'       => $milestoneProgress['30_day']['earned_on'],
                'earned_amount'   => $milestoneProgress['30_day']['status'] === 'completed'
                                     ? $reward30InrAmount : null,
                'progress'        => [
                    'active_days'       => $milestoneProgress['30_day']['active_days'],
                    'required_days'     => $req30Days,
                    'articles_read'     => $milestoneProgress['30_day']['articles_read'],
                    'required_articles' => $req30Articles,
                    'percent'           => $milestoneProgress['30_day']['percent'],
                ],
                'days_remaining'  => $m30Remaining,
                'estimated_date'  => $estimated30,
            ],
            '90_day' => [
                'reward'       => "₹{$reward90InrAmount}",
                'status'       => $milestoneProgress['90_day']['status'],
                'earned_on'    => $milestoneProgress['90_day']['earned_on'],
                'earned_amount'=> $milestoneProgress['90_day']['status'] === 'completed'
                                  ? $reward90InrAmount : null,
                'unlock_after' => $milestoneProgress['90_day']['unlock_after'],
                'progress'     => [
                    'active_days'   => $milestoneProgress['90_day']['active_days'],
                    'required_days' => $req90Days,
                    'percent'       => $milestoneProgress['90_day']['percent'],
                ],
            ],
        ],

        'article_rewards' => [
            'enabled'              => $articleRewardEnabled,
            'per_article'          => $rewardArticleInr,
            'this_month_earned'    => $articlesThisMonth,
            'monthly_cap'          => $articleMonthlyCap,
            'remaining_cap'        => $remainingArticleCap,
            'articles_this_month'  => $articlesCountThisMonth,
            'max_this_month'       => $maxArticlesThisMonth,
        ],

        'referral' => [
            'code'                  => $referralCode,
            'share_url'             => $shareUrl,
            'your_bonus_per_referral'=> "₹{$refBonusInr}",
            'when'                  => 'Jab referee 7-day milestone complete kare',
            'lifetime_bonus'        => "{$lifetimePct}% of referee earnings for {$lifetimeMonths} months",
            'total_referrals'       => (int)($refCodeRow['total_referrals'] ?? 0),
            'active_referrals'      => (int)($refCodeRow['successful_referrals'] ?? 0),
            'pending_bonus'         => $pendingBonus,
            'total_referral_earned' => round($totalReferralEarned, 2),
        ],

        'withdrawal' => [
            'eligible'               => $withdrawEligible,
            'min_amount'             => $minWithdrawal,
            'first_eligible_date'    => $firstEligDate,
            'days_until_eligible'    => $daysUntilEligible,
            'aadhar_required_above'  => $largeWithdraw,
            'aadhar_verified'        => $kycVerified,
        ],

        'this_month_summary' => [
            'total_earned'  => round($totalEarnedThisMonth, 2),
            'monthly_cap'   => $perUserMonthlyCap,
            'remaining_cap' => round(max(0.0, $perUserMonthlyCap - $totalThisMonth), 2),
            'breakdown'     => $breakdown,
        ],
    ];
} else {
    // ── Coins response ────────────────────────────────────────────────────────
    $coinsBalance   = (int)($coinsWallet['balance'] ?? 0);
    $coinsEarned    = (int)($coinsWallet['total_earned'] ?? 0);
    $coinsValueInr  = round($coinsBalance * $coinInrValue, 2);

    // Redemption options (initially hardcoded)
    $redemptionOptions = [
        [
            'type'        => 'featured_listing',
            'cost_coins'  => 50,
            'cost_display'=> '50 Coins',
            'can_afford'  => $coinsBalance >= 50,
            'need_more'   => max(0, 50 - $coinsBalance),
            'description' => 'Featured listing on news feed for 24 hours',
            'value_inr'   => round(50 * $coinInrValue, 2),
        ],
        [
            'type'        => 'premium_article',
            'cost_coins'  => 20,
            'cost_display'=> '20 Coins',
            'can_afford'  => $coinsBalance >= 20,
            'need_more'   => max(0, 20 - $coinsBalance),
            'description' => 'Unlock a premium article',
            'value_inr'   => round(20 * $coinInrValue, 2),
        ],
        [
            'type'        => 'blue_tick_discount',
            'cost_coins'  => 100,
            'cost_display'=> '100 Coins',
            'can_afford'  => $coinsBalance >= 100,
            'need_more'   => max(0, 100 - $coinsBalance),
            'description' => '₹5 discount on Blue Tick purchase',
            'value_inr'   => round(100 * $coinInrValue, 2),
        ],
        [
            'type'        => 'leaderboard_boost',
            'cost_coins'  => 30,
            'cost_display'=> '30 Coins',
            'can_afford'  => $coinsBalance >= 30,
            'need_more'   => max(0, 30 - $coinsBalance),
            'description' => 'Boost your position on the reporter leaderboard for 12 hours',
            'value_inr'   => round(30 * $coinInrValue, 2),
        ],
    ];

    $response = [
        'success'              => true,
        'wallet_type'          => 'coins',
        'balance'              => $coinsBalance,
        'total_earned'         => $coinsEarned,
        'coins_value_display'  => "₹{$coinsValueInr} equivalent",

        'milestones' => [
            '7_day' => [
                'reward'    => "{$reward7CoinsAmount} Coins",
                'status'    => $milestoneProgress['7_day']['status'],
                'earned_on' => $milestoneProgress['7_day']['earned_on'],
                'progress'  => [
                    'active_days'       => $milestoneProgress['7_day']['active_days'],
                    'required_days'     => $req7Days,
                    'articles_read'     => $milestoneProgress['7_day']['articles_read'],
                    'required_articles' => $req7Articles,
                    'percent'           => $milestoneProgress['7_day']['percent'],
                ],
            ],
            '30_day' => [
                'reward'    => "{$reward30CoinsAmount} Coins",
                'status'    => $milestoneProgress['30_day']['status'],
                'earned_on' => $milestoneProgress['30_day']['earned_on'],
                'progress'  => [
                    'active_days'       => $milestoneProgress['30_day']['active_days'],
                    'required_days'     => $req30Days,
                    'articles_read'     => $milestoneProgress['30_day']['articles_read'],
                    'required_articles' => $req30Articles,
                    'percent'           => $milestoneProgress['30_day']['percent'],
                ],
            ],
            '90_day' => [
                'reward'       => "{$reward90CoinsAmount} Coins",
                'status'       => $milestoneProgress['90_day']['status'],
                'earned_on'    => $milestoneProgress['90_day']['earned_on'],
                'unlock_after' => $milestoneProgress['90_day']['unlock_after'],
                'progress'     => [
                    'active_days'   => $milestoneProgress['90_day']['active_days'],
                    'required_days' => $req90Days,
                    'percent'       => $milestoneProgress['90_day']['percent'],
                ],
            ],
        ],

        'redemption_options' => $redemptionOptions,

        'referral' => [
            'code'                   => $referralCode,
            'share_url'              => $shareUrl,
            'your_bonus_per_referral'=> "{$refBonusCoins} Coins",
            'when'                   => 'Jab referee 7-day milestone complete kare',
        ],
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
