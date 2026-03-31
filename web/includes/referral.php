<?php
/**
 * web/includes/referral.php
 *
 * Viral Referral System helpers.
 *
 * Functions:
 *  generateReferralCode(PDO, int)        – Creates & stores a unique code for a user.
 *  getReferralUrl(string)                – Returns the canonical share URL for a code.
 *  recordReferralClick(PDO, string, str) – Logs an install-click (deduped by IP).
 *  applyReferral(PDO, int, string)       – Links a new user to the referrer chain.
 *  grantReward(PDO, int, int, string)    – Walks the chain, grants multi-level rewards.
 *  getRewardConfig(PDO)                  – Returns reward point config from settings.
 *  getUserReferralStats(PDO, int)        – Returns stats for a user's dashboard.
 *  getUserRewardHistory(PDO, int, int)   – Returns reward log rows for a user.
 *  getLeaderboard(PDO, int)             – Returns top referrers by total points.
 */

require_once __DIR__ . '/functions.php';   // getSetting()

/* ── Constants ───────────────────────────────────────────────────── */
define('REFERRAL_COOKIE',     'nxl_ref');
define('REFERRAL_COOKIE_TTL', 30 * 86400); // 30 days

/* ── Code generation ─────────────────────────────────────────────── */

/**
 * Generates and persists a unique alphanumeric referral code for the given user.
 * Returns the code (existing or newly created).
 */
function generateReferralCode(PDO $pdo, int $userId): string
{
    // Return existing code if already assigned
    try {
        $row = $pdo->prepare('SELECT referral_code FROM users WHERE id = :id LIMIT 1');
        $row->execute([':id' => $userId]);
        $existing = $row->fetchColumn();
        if ($existing) {
            return $existing;
        }
    } catch (PDOException $e) {
        // fall through to generate
    }

    // Generate a unique 8-char uppercase alphanumeric code
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    for ($attempts = 0; $attempts < 20; $attempts++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        try {
            $pdo->prepare('UPDATE users SET referral_code = :code WHERE id = :id AND referral_code IS NULL')
                ->execute([':code' => $code, ':id' => $userId]);
            return $code;
        } catch (PDOException $e) {
            // UNIQUE constraint violation — try again
        }
    }

    // Fallback: user-id-based code
    $code = 'NXL' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
    try {
        $pdo->prepare('UPDATE users SET referral_code = :code WHERE id = :id AND referral_code IS NULL')
            ->execute([':code' => $code, ':id' => $userId]);
    } catch (PDOException $e) {}
    return $code;
}

/**
 * Returns the public referral / install-tracking URL for a code.
 */
function getReferralUrl(string $code): string
{
    return SITE_URL . '/api/referral_track.php?ref=' . urlencode($code);
}

/* ── Click tracking ──────────────────────────────────────────────── */

/**
 * Logs one install/click for a referral code.
 * Deduped: same IP can only log one click per code per day.
 */
function recordReferralClick(PDO $pdo, string $code, string $ip): void
{
    $ipHash = hash('sha256', $ip . date('Y-m-d'));
    try {
        // Check for recent click from this IP
        $chk = $pdo->prepare(
            'SELECT id FROM referral_clicks
             WHERE referral_code = :c AND ip_hash = :ip
               AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
             LIMIT 1'
        );
        $chk->execute([':c' => $code, ':ip' => $ipHash]);
        if ($chk->fetch()) {
            return; // already logged today
        }
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $pdo->prepare(
            'INSERT INTO referral_clicks (referral_code, ip_hash, user_agent) VALUES (:c, :ip, :ua)'
        )->execute([':c' => $code, ':ip' => $ipHash, ':ua' => $ua]);
    } catch (PDOException $e) { /* silent */ }
}

/* ── Referral linking ────────────────────────────────────────────── */

/**
 * Links a newly registered user to a referrer.
 * Looks up the referrer by code, sets referred_by_id on the new user,
 * and grants signup rewards up the chain.
 *
 * @param PDO    $pdo
 * @param int    $newUserId   The just-registered user
 * @param string $code        Referral code from cookie/GET param
 * @return bool  True if a valid referrer was found and linked
 */
function applyReferral(PDO $pdo, int $newUserId, string $code): bool
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return false;
    }

    try {
        // Find referrer
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $referrer = $stmt->fetch();
        if (!$referrer || (int)$referrer['id'] === $newUserId) {
            return false;
        }

        $referrerId = (int)$referrer['id'];

        // Link the new user
        $pdo->prepare('UPDATE users SET referred_by_id = :rid WHERE id = :uid AND referred_by_id IS NULL')
            ->execute([':rid' => $referrerId, ':uid' => $newUserId]);

        // Grant signup rewards up the chain
        grantReward($pdo, $newUserId, $referrerId, 'signup');

        return true;
    } catch (PDOException $e) {
        error_log('applyReferral failed: ' . $e->getMessage());
        return false;
    }
}

/* ── Reward granting ─────────────────────────────────────────────── */

/**
 * Reads reward configuration from settings.
 * Returns an array with keys: install, signup, subscription, max_levels, level2_pct, level3_pct
 */
function getRewardConfig(PDO $pdo): array
{
    return [
        'install'      => (int)getSetting($pdo, 'referral_points_install',      '5'),
        'signup'       => (int)getSetting($pdo, 'referral_points_signup',       '10'),
        'subscription' => (int)getSetting($pdo, 'referral_points_subscription', '50'),
        'max_levels'   => (int)getSetting($pdo, 'referral_max_levels',          '3'),
        'level2_pct'   => (int)getSetting($pdo, 'referral_level2_pct',          '50'),
        'level3_pct'   => (int)getSetting($pdo, 'referral_level3_pct',          '25'),
    ];
}

/**
 * Grants multi-level referral rewards starting from the direct referrer.
 *
 * Level 1 (direct referrer)    → full points
 * Level 2 (referrer's referrer) → level2_pct% of level-1 points
 * Level 3                       → level3_pct% of level-1 points
 *
 * @param PDO    $pdo
 * @param int    $newUserId    The user whose action triggered the reward
 * @param int    $referrerId   The level-1 referrer
 * @param string $event        'install' | 'signup' | 'subscription'
 */
function grantReward(PDO $pdo, int $newUserId, int $referrerId, string $event): void
{
    $cfg        = getRewardConfig($pdo);
    $basePoints = $cfg[$event] ?? 0;
    if ($basePoints <= 0) {
        return;
    }

    // Walk up the chain
    $currentId = $referrerId;
    $level     = 1;

    while ($currentId && $level <= $cfg['max_levels']) {
        // Calculate points for this level
        $points = match ($level) {
            1 => $basePoints,
            2 => (int)ceil($basePoints * $cfg['level2_pct'] / 100),
            3 => (int)ceil($basePoints * $cfg['level3_pct'] / 100),
            default => 0,
        };

        if ($points <= 0) {
            break;
        }

        try {
            // Avoid duplicate rewards for the same referred_id + event + level
            $dup = $pdo->prepare(
                'SELECT id FROM referral_rewards
                 WHERE user_id = :uid AND referred_id = :rid AND event = :ev AND level = :lv LIMIT 1'
            );
            $dup->execute([':uid' => $currentId, ':rid' => $newUserId, ':ev' => $event, ':lv' => $level]);
            if (!$dup->fetch()) {
                $pdo->prepare(
                    'INSERT INTO referral_rewards (user_id, referred_id, level, event, points)
                     VALUES (:uid, :rid, :lv, :ev, :pts)'
                )->execute([
                    ':uid' => $currentId,
                    ':rid' => $newUserId,
                    ':lv'  => $level,
                    ':ev'  => $event,
                    ':pts' => $points,
                ]);
                // Update running total on users table
                $pdo->prepare('UPDATE users SET referral_points = referral_points + :pts WHERE id = :uid')
                    ->execute([':pts' => $points, ':uid' => $currentId]);
            }
        } catch (PDOException $e) {
            error_log('grantReward failed at level ' . $level . ': ' . $e->getMessage());
        }

        // Move up one level
        try {
            $parentRow = $pdo->prepare('SELECT referred_by_id FROM users WHERE id = :uid LIMIT 1');
            $parentRow->execute([':uid' => $currentId]);
            $parent    = $parentRow->fetchColumn();
            $currentId = $parent ? (int)$parent : null;
        } catch (PDOException $e) {
            break;
        }
        $level++;
    }
}

/* ── Stats ───────────────────────────────────────────────────────── */

/**
 * Returns referral dashboard stats for a user.
 */
function getUserReferralStats(PDO $pdo, int $userId): array
{
    $stats = [
        'referral_code'    => '',
        'referral_url'     => '',
        'total_referrals'  => 0,
        'direct_referrals' => 0,
        'total_points'     => 0,
        'pending_points'   => 0,
        'clicks'           => 0,
    ];

    try {
        $row = $pdo->prepare(
            'SELECT referral_code, referral_points FROM users WHERE id = :uid LIMIT 1'
        );
        $row->execute([':uid' => $userId]);
        $user = $row->fetch();
        if (!$user) {
            return $stats;
        }

        $code = $user['referral_code'] ?: generateReferralCode($pdo, $userId);
        $stats['referral_code']   = $code;
        $stats['referral_url']    = getReferralUrl($code);
        $stats['total_points']    = (int)$user['referral_points'];

        // Direct referrals (level-1 only)
        $direct = $pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE referred_by_id = :uid'
        );
        $direct->execute([':uid' => $userId]);
        $stats['direct_referrals'] = (int)$direct->fetchColumn();

        // All levels (count distinct referred_ids in rewards table)
        $total = $pdo->prepare(
            'SELECT COUNT(DISTINCT referred_id) FROM referral_rewards WHERE user_id = :uid'
        );
        $total->execute([':uid' => $userId]);
        $stats['total_referrals'] = (int)$total->fetchColumn();

        // Click count
        $clicks = $pdo->prepare(
            'SELECT COUNT(*) FROM referral_clicks WHERE referral_code = :code'
        );
        $clicks->execute([':code' => $code]);
        $stats['clicks'] = (int)$clicks->fetchColumn();

    } catch (PDOException $e) { /* return defaults */ }

    return $stats;
}

/**
 * Returns recent reward history rows for a user.
 */
function getUserRewardHistory(PDO $pdo, int $userId, int $limit = 20): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT rr.level, rr.event, rr.points, rr.created_at,
                    u.name AS referred_name
             FROM referral_rewards rr
             LEFT JOIN users u ON u.id = rr.referred_id
             WHERE rr.user_id = :uid
             ORDER BY rr.created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* ── Leaderboard ─────────────────────────────────────────────────── */

/**
 * Returns top referrers ordered by total referral_points.
 */
function getLeaderboard(PDO $pdo, int $limit = 20): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name,
                    referral_code,
                    referral_points,
                    (SELECT COUNT(*) FROM users u2 WHERE u2.referred_by_id = users.id) AS direct_referrals
             FROM users
             WHERE referral_points > 0
             ORDER BY referral_points DESC, direct_referrals DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
