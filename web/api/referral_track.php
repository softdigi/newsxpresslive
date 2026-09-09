<?php
/**
 * web/api/referral_track.php
 *
 * GET ?ref=CODE[&redirect=URL]
 *
 * 1. Validates the referral code.
 * 2. Logs the install/click (deduped per IP per day).
 * 3. Grants install-event reward to the referrer chain.
 * 4. Sets a first-party cookie (30 days) so the signup page can pick it up.
 * 5. Redirects to:
 *    - ?redirect= param (validated against SITE_URL prefix)
 *    - Or the app store / SITE_URL homepage
 *
 * This endpoint is safe to use in deep-link banners, QR codes, and social posts.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/referral.php';

$code = strtoupper(trim($_GET['ref'] ?? ''));

if ($code === '') {
    // No code — redirect home
    header('Location: ' . SITE_URL . '/');
    exit;
}

// Validate the code exists
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $referrer = $stmt->fetch();
} catch (PDOException $e) {
    $referrer = null;
}

if ($referrer) {
    $referrerId = (int)$referrer['id'];
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Log the click
    recordReferralClick($pdo, $code, $ip);

    // Grant install reward (fire-and-forget; no user logged in yet)
    grantReward($pdo, 0, $referrerId, 'install');

    // Set 30-day cookie so signup can apply the referral
    setcookie(
        REFERRAL_COOKIE,
        $code,
        time() + REFERRAL_COOKIE_TTL,
        '/',
        '',   // domain
        false,
        true  // httponly
    );

    // Also stash in session for same-browser same-session signups
    $_SESSION['nxl_pending_ref'] = $code;
}

// Determine redirect target
$redirect = $_GET['redirect'] ?? '';

// Whitelist: must start with SITE_URL or be one of the app store URLs
$safeTargets = [SITE_URL, PLAY_STORE_URL, APP_STORE_URL];
$isAllowed   = false;
foreach ($safeTargets as $base) {
    if ($redirect !== '' && str_starts_with($redirect, $base)) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed || $redirect === '') {
    // Default: send to subscribe/register page so they can sign up immediately
    $redirect = SITE_URL . '/subscribe/register.php';
}

header('Location: ' . $redirect);
exit;
