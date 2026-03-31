<?php
/**
 * Subscription Plans – Landing Page
 * NewsXpressLive
 *
 * Shows monthly & yearly plan cards. Redirects logged-in users to checkout
 * and guest users to register.php.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

$user   = getCurrentUser($pdo);
$prices = getPlanPrices($pdo);

$seoMeta = [
    'title'       => 'Go Premium – ' . SITE_NAME,
    'description' => 'Unlock unlimited premium articles ad-free. Choose monthly or yearly plan.',
    'url'         => SITE_URL . '/subscribe/',
    'type'        => 'website',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width:840px;margin:0 auto;">

    <section class="section" style="text-align:center;padding:2rem 0 1rem;">
        <h1 style="font-size:2rem;font-weight:800;margin-bottom:.5rem;">📰 Go Premium</h1>
        <p style="color:#666;font-size:1.05rem;max-width:520px;margin:0 auto 2rem;">
            Unlock unlimited access to all premium articles, enjoy an ad-free reading
            experience, and support quality journalism.
        </p>
    </section>

    <!-- Plan Cards -->
    <div style="display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;margin-bottom:2.5rem;">

        <!-- Monthly -->
        <div class="plan-card" style="border:2px solid #e0e0e0;border-radius:12px;padding:2rem 2.5rem;min-width:240px;text-align:center;background:#fff;flex:1;max-width:340px;">
            <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:.5rem;">Monthly</h2>
            <p style="font-size:2.4rem;font-weight:900;color:#e50914;margin:.5rem 0;">
                <?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($prices['monthly'], 2) ?>
                <span style="font-size:1rem;color:#888;font-weight:400">/mo</span>
            </p>
            <ul style="text-align:left;margin:1.2rem 0;padding:0;list-style:none;font-size:.95rem;color:#444;">
                <li>✅ Unlimited premium articles</li>
                <li>✅ Ad-free experience</li>
                <li>✅ Early access to breaking news</li>
                <li>✅ Cancel anytime</li>
            </ul>
            <?php
            $monthlyUrl = isSubscribed($user)
                ? SITE_URL . '/subscribe/account.php'
                : ($user ? SITE_URL . '/subscribe/checkout.php?plan=monthly'
                         : SITE_URL . '/subscribe/register.php?plan=monthly');
            ?>
            <a href="<?= htmlspecialchars($monthlyUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn--primary" style="display:block;width:100%;text-align:center;padding:.75rem;border-radius:8px;font-weight:700;font-size:1rem;background:#e50914;color:#fff;text-decoration:none;margin-top:1rem;">
               <?= isSubscribed($user) ? 'Your Plan' : 'Subscribe Monthly' ?>
            </a>
        </div>

        <!-- Yearly (highlighted) -->
        <div class="plan-card" style="border:2px solid #e50914;border-radius:12px;padding:2rem 2.5rem;min-width:240px;text-align:center;background:#fff8f8;flex:1;max-width:340px;position:relative;">
            <span style="position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:#e50914;color:#fff;font-size:.72rem;font-weight:700;padding:3px 14px;border-radius:20px;white-space:nowrap;">BEST VALUE – SAVE 33%</span>
            <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:.5rem;">Yearly</h2>
            <p style="font-size:2.4rem;font-weight:900;color:#e50914;margin:.5rem 0;">
                <?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($prices['yearly'], 2) ?>
                <span style="font-size:1rem;color:#888;font-weight:400">/yr</span>
            </p>
            <ul style="text-align:left;margin:1.2rem 0;padding:0;list-style:none;font-size:.95rem;color:#444;">
                <li>✅ Unlimited premium articles</li>
                <li>✅ Ad-free experience</li>
                <li>✅ Early access to breaking news</li>
                <li>✅ Priority newsletter</li>
                <li>✅ Cancel anytime</li>
            </ul>
            <?php
            $yearlyUrl = isSubscribed($user)
                ? SITE_URL . '/subscribe/account.php'
                : ($user ? SITE_URL . '/subscribe/checkout.php?plan=yearly'
                         : SITE_URL . '/subscribe/register.php?plan=yearly');
            ?>
            <a href="<?= htmlspecialchars($yearlyUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn--primary" style="display:block;width:100%;text-align:center;padding:.75rem;border-radius:8px;font-weight:700;font-size:1rem;background:#e50914;color:#fff;text-decoration:none;margin-top:1rem;">
               <?= isSubscribed($user) ? 'Your Plan' : 'Subscribe Yearly' ?>
            </a>
        </div>

    </div>

    <?php if ($user): ?>
    <p style="text-align:center;color:#888;font-size:.9rem;">
        Logged in as <strong><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></strong>.
        <a href="<?= SITE_URL ?>/subscribe/account.php">Manage subscription</a>
        &nbsp;|&nbsp;
        <a href="<?= SITE_URL ?>/subscribe/logout.php">Logout</a>
    </p>
    <?php else: ?>
    <p style="text-align:center;color:#888;font-size:.9rem;">
        Already a subscriber? <a href="<?= SITE_URL ?>/subscribe/login.php">Login</a>
    </p>
    <?php endif; ?>

    <!-- FAQ -->
    <section style="margin:2.5rem 0;max-width:640px;margin-inline:auto;">
        <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">Frequently Asked Questions</h3>
        <details style="margin-bottom:.8rem;border-bottom:1px solid #eee;padding-bottom:.8rem;">
            <summary style="cursor:pointer;font-weight:600;">What is a premium article?</summary>
            <p style="margin-top:.5rem;color:#555;font-size:.9rem;">Premium articles are in-depth investigative reports and exclusive coverage available only to paid subscribers.</p>
        </details>
        <details style="margin-bottom:.8rem;border-bottom:1px solid #eee;padding-bottom:.8rem;">
            <summary style="cursor:pointer;font-weight:600;">Can I cancel anytime?</summary>
            <p style="margin-top:.5rem;color:#555;font-size:.9rem;">Yes. You can cancel at any time from your account page. Access continues until the end of the billing period.</p>
        </details>
        <details style="margin-bottom:.8rem;border-bottom:1px solid #eee;padding-bottom:.8rem;">
            <summary style="cursor:pointer;font-weight:600;">Is payment secure?</summary>
            <p style="margin-top:.5rem;color:#555;font-size:.9rem;">Payments are processed through our secure payment gateway. We never store your card details.</p>
        </details>
    </section>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
