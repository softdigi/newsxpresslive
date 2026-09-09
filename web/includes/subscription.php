<?php
/**
 * Subscription & Monetization Helpers
 * NewsXpressLive
 *
 * Functions:
 *  getCurrentUser(PDO)          – Returns user row from session, or null.
 *  isSubscribed(?array $user)   – True when user has an active subscription.
 *  canViewPremium(PDO)          – True when the current visitor may read premium content.
 *  getAdFrequencyCap(PDO, ?arr) – Returns max ads per session for this visitor.
 *  shouldShowAd(PDO, ?arr)      – True when another ad impression is allowed this session.
 *  recordAdImpression()         – Increments the session ad counter.
 *  hashPassword(string)         – bcrypt wrapper.
 *  verifyPassword(string,str)   – bcrypt verify wrapper.
 *  loginUser(PDO, str, str)     – Validates credentials, writes session. Returns user|false.
 *  logoutUser()                 – Destroys session.
 *  registerUser(PDO, str, str, str) – Creates a new free user. Returns user id|false.
 *  activateSubscription(PDO, int, string, string) – Creates a subscription row.
 *  getPlanPrices(PDO)           – Returns ['monthly'=>..., 'yearly'=>..., 'currency'=>...]
 */

/* ── Session key names ────────────────────────────────────────────── */
define('SESSION_USER_ID',    'nxl_uid');
define('SESSION_AD_COUNTER', 'nxl_ad_cnt');

/**
 * Load the current logged-in user row from the database (cached in session).
 */
function getCurrentUser(PDO $pdo): ?array
{
    if (empty($_SESSION[SESSION_USER_ID])) {
        return null;
    }
    $uid = (int)$_SESSION[SESSION_USER_ID];
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, subscription_status, subscription_plan,
                    subscription_expires, ad_frequency_cap
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $uid]);
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Returns true when the given user row has an active, non-expired subscription.
 */
function isSubscribed(?array $user): bool
{
    if (empty($user)) {
        return false;
    }
    if ($user['subscription_status'] !== 'active') {
        return false;
    }
    if (!empty($user['subscription_expires'])) {
        return strtotime($user['subscription_expires']) >= time();
    }
    return false;
}

/**
 * Convenience: true when the current session visitor may read premium articles.
 */
function canViewPremium(PDO $pdo): bool
{
    $user = getCurrentUser($pdo);
    return isSubscribed($user);
}

/**
 * Returns the maximum number of ad impressions allowed per session for this visitor.
 * Subscribers get 0 ads (ad-free). Free users use the DB-configured cap.
 */
function getAdFrequencyCap(PDO $pdo, ?array $user): int
{
    if (isSubscribed($user)) {
        return 0;   // subscribers are ad-free
    }
    // Use per-user cap if set, otherwise fall back to global setting
    if (!empty($user['ad_frequency_cap'])) {
        return (int)$user['ad_frequency_cap'];
    }
    $cap = getSetting($pdo, 'ad_default_freq_cap', '5');
    return max(0, (int)$cap);
}

/**
 * Returns true when another ad impression is still within the session cap.
 */
function shouldShowAd(PDO $pdo, ?array $user): bool
{
    $cap = getAdFrequencyCap($pdo, $user);
    if ($cap === 0) {
        return false;   // subscriber → no ads
    }
    $shown = (int)($_SESSION[SESSION_AD_COUNTER] ?? 0);
    return $shown < $cap;
}

/**
 * Call this once for each ad slot that is actually rendered.
 */
function recordAdImpression(): void
{
    $_SESSION[SESSION_AD_COUNTER] = (int)($_SESSION[SESSION_AD_COUNTER] ?? 0) + 1;
}

/* ── Auth helpers ──────────────────────────────────────────────────── */

function hashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/**
 * Validate credentials and write session. Returns user row or false.
 */
function loginUser(PDO $pdo, string $email, string $plain): array|false
{
    $email = strtolower(trim($email));
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, subscription_status,
                    subscription_plan, subscription_expires, ad_frequency_cap
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }

    if (!$user || !verifyPassword($plain, $user['password_hash'])) {
        return false;
    }

    // Refresh subscription status (expire if past expires_at)
    if ($user['subscription_status'] === 'active'
        && !empty($user['subscription_expires'])
        && strtotime($user['subscription_expires']) < time()
    ) {
        try {
            $pdo->prepare('UPDATE users SET subscription_status = :s WHERE id = :id')
                ->execute([':s' => 'expired', ':id' => $user['id']]);
        } catch (PDOException $e) {}
        $user['subscription_status'] = 'expired';
    }

    $_SESSION[SESSION_USER_ID]    = $user['id'];
    $_SESSION[SESSION_AD_COUNTER] = 0;   // reset ad counter on fresh login

    return $user;
}

function logoutUser(): void
{
    unset($_SESSION[SESSION_USER_ID], $_SESSION[SESSION_AD_COUNTER]);
}

/**
 * Create a new free user. Returns new user id or false on failure.
 * Automatically generates a referral code and applies any pending referral cookie.
 */
function registerUser(PDO $pdo, string $name, string $email, string $plain): int|false
{
    $email = strtolower(trim($email));
    $name  = trim($name);

    if ($name === '' || $email === '' || strlen($plain) < 8) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (:n, :e, :p)'
        );
        $stmt->execute([
            ':n' => $name,
            ':e' => $email,
            ':p' => hashPassword($plain),
        ]);
        $uid = (int)$pdo->lastInsertId();
        $_SESSION[SESSION_USER_ID]    = $uid;
        $_SESSION[SESSION_AD_COUNTER] = 0;

        // Auto-generate a referral code for every new user
        require_once __DIR__ . '/referral.php';
        generateReferralCode($pdo, $uid);

        // Apply any pending referral code (from cookie or GET param stored in session)
        $pendingRef = $_COOKIE[REFERRAL_COOKIE] ?? ($_SESSION['nxl_pending_ref'] ?? '');
        if ($pendingRef !== '') {
            applyReferral($pdo, $uid, $pendingRef);
            // Clear the pending ref
            unset($_SESSION['nxl_pending_ref']);
            setcookie(REFERRAL_COOKIE, '', time() - 1, '/', '', false, true);
        }

        return $uid;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Activate (or extend) a subscription for the given user.
 *
 * @param PDO    $pdo
 * @param int    $userId
 * @param string $plan      'monthly' | 'yearly'
 * @param string $gateway   'manual' | 'stripe' | 'razorpay'
 * @param string $gatewayRef  Payment reference / transaction ID
 * @return bool
 */
function activateSubscription(
    PDO    $pdo,
    int    $userId,
    string $plan,
    string $gateway    = 'manual',
    string $gatewayRef = ''
): bool {
    if (!in_array($plan, ['monthly', 'yearly'], true)) {
        return false;
    }

    $prices = getPlanPrices($pdo);
    $amount = ($plan === 'monthly') ? $prices['monthly'] : $prices['yearly'];

    $startsAt  = date('Y-m-d H:i:s');
    $expiresAt = ($plan === 'monthly')
        ? date('Y-m-d H:i:s', strtotime('+1 month'))
        : date('Y-m-d H:i:s', strtotime('+1 year'));

    try {
        // Insert payment record
        $pdo->prepare(
            'INSERT INTO subscriptions
             (user_id, plan, amount, currency, status, gateway, gateway_ref, starts_at, expires_at)
             VALUES (:uid, :plan, :amt, :cur, :st, :gw, :ref, :sa, :ea)'
        )->execute([
            ':uid'  => $userId,
            ':plan' => $plan,
            ':amt'  => $amount,
            ':cur'  => $prices['currency'],
            ':st'   => 'active',
            ':gw'   => $gateway,
            ':ref'  => $gatewayRef,
            ':sa'   => $startsAt,
            ':ea'   => $expiresAt,
        ]);

        // Update user status
        $pdo->prepare(
            'UPDATE users SET subscription_status = :st,
                              subscription_plan    = :plan,
                              subscription_expires = :ea
             WHERE id = :id'
        )->execute([
            ':st'   => 'active',
            ':plan' => $plan,
            ':ea'   => $expiresAt,
            ':id'   => $userId,
        ]);

        // Grant subscription referral reward to the user's referrer chain
        require_once __DIR__ . '/referral.php';
        try {
            $refRow = $pdo->prepare('SELECT referred_by_id FROM users WHERE id = :id LIMIT 1');
            $refRow->execute([':id' => $userId]);
            $referrerId = (int)($refRow->fetchColumn() ?: 0);
            if ($referrerId > 0) {
                grantReward($pdo, $userId, $referrerId, 'subscription');
            }
        } catch (PDOException $e) { /* non-fatal */ }

        return true;
    } catch (PDOException $e) {
        error_log('activateSubscription failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Return plan prices from settings (or compiled-in defaults).
 */
function getPlanPrices(PDO $pdo): array
{
    return [
        'monthly'  => (float)(getSetting($pdo, 'plan_monthly_price', PLAN_MONTHLY_PRICE)),
        'yearly'   => (float)(getSetting($pdo, 'plan_yearly_price',  PLAN_YEARLY_PRICE)),
        'currency' => getSetting($pdo, 'plan_currency', PLAN_CURRENCY),
    ];
}
