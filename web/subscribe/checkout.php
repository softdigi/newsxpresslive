<?php
/**
 * Subscription Checkout
 * NewsXpressLive
 *
 * Shows a payment summary and (for now) a "manual / demo" pay button that
 * activates the subscription immediately.  Replace the POST handler with
 * a real gateway SDK (Stripe, Razorpay, etc.) for production.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

// Must be logged in
$user = getCurrentUser($pdo);
if (!$user) {
    $plan = in_array($_GET['plan'] ?? '', ['monthly', 'yearly'], true) ? $_GET['plan'] : 'monthly';
    header('Location: ' . SITE_URL . '/subscribe/login.php?plan=' . $plan);
    exit;
}

// Already subscribed
if (isSubscribed($user)) {
    header('Location: ' . SITE_URL . '/subscribe/account.php');
    exit;
}

$plan   = in_array($_GET['plan'] ?? $_POST['plan'] ?? '', ['monthly', 'yearly'], true)
          ? ($_GET['plan'] ?? $_POST['plan'])
          : 'monthly';
$prices = getPlanPrices($pdo);
$amount = ($plan === 'monthly') ? $prices['monthly'] : $prices['yearly'];
$error  = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Real integration point ---
    // Replace this block with your payment gateway verification:
    //   e.g. Stripe: \Stripe\PaymentIntent::retrieve($paymentIntentId) → status = 'succeeded'
    //   e.g. Razorpay: verify signature, then activate.
    //
    // For now we activate immediately (demo / manual mode).
    $planPost = in_array($_POST['plan'] ?? '', ['monthly', 'yearly'], true)
                ? $_POST['plan'] : 'monthly';

    $ok = activateSubscription($pdo, (int)$user['id'], $planPost, 'manual', 'demo-' . uniqid());
    if ($ok) {
        $success = true;
    } else {
        $error = 'Payment activation failed. Please try again or contact support.';
    }
}

$seoMeta = [
    'title'       => 'Checkout – ' . SITE_NAME,
    'description' => 'Complete your subscription to ' . SITE_NAME,
    'url'         => SITE_URL . '/subscribe/checkout.php',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width:480px;margin:2rem auto;">

<?php if ($success): ?>
    <div style="text-align:center;padding:2.5rem 1rem;">
        <div style="font-size:3rem;margin-bottom:1rem;">🎉</div>
        <h1 style="font-size:1.6rem;font-weight:800;color:#1a7a1a;">Subscription Activated!</h1>
        <p style="color:#555;margin:1rem 0;">
            Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>!
            Your <?= ucfirst(htmlspecialchars($plan, ENT_QUOTES, 'UTF-8')) ?> plan is now active.
        </p>
        <a href="<?= SITE_URL ?>/"
           style="display:inline-block;margin-top:1rem;padding:.75rem 2rem;background:#e50914;color:#fff;font-weight:700;border-radius:8px;text-decoration:none;">
            Start Reading
        </a>
    </div>
<?php else: ?>

    <h1 style="font-size:1.6rem;font-weight:800;margin-bottom:.4rem;">Complete Your Order</h1>
    <p style="color:#666;margin-bottom:1.5rem;">You're subscribing to <strong><?= ucfirst(htmlspecialchars($plan, ENT_QUOTES, 'UTF-8')) ?></strong> plan.</p>

    <?php if ($error): ?>
    <div style="background:#fff3f3;border:1px solid #f5c6cb;color:#721c24;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Order summary -->
    <div style="border:1px solid #eee;border-radius:10px;padding:1.5rem;margin-bottom:1.5rem;background:#fafafa;">
        <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">Order Summary</h3>
        <div style="display:flex;justify-content:space-between;margin-bottom:.6rem;font-size:.95rem;">
            <span><?= ucfirst(htmlspecialchars($plan, ENT_QUOTES, 'UTF-8')) ?> Subscription</span>
            <span><?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($amount, 2) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.85rem;color:#888;">
            <span>Billing period</span>
            <span><?= $plan === 'monthly' ? '1 month' : '1 year' ?></span>
        </div>
        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee;">
        <div style="display:flex;justify-content:space-between;font-weight:700;font-size:1.05rem;">
            <span>Total</span>
            <span><?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($amount, 2) ?></span>
        </div>
    </div>

    <!-- Payment form placeholder -->
    <form method="POST" action="">
        <input type="hidden" name="plan" value="<?= htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') ?>">

        <!-- Payment method placeholder (replace with Stripe Elements / Razorpay widget) -->
        <div style="border:1px solid #ddd;border-radius:8px;padding:1.2rem;margin-bottom:1.5rem;background:#fff;">
            <p style="font-size:.85rem;color:#888;margin:0 0 .8rem;">
                💳 <strong>Payment Gateway</strong> – Integrate Stripe, Razorpay, or PayPal here.
            </p>
            <div style="background:#f5f5f5;border-radius:6px;padding:1rem;font-size:.8rem;color:#999;font-style:italic;">
                [Payment widget renders here in production]
            </div>
        </div>

        <button type="submit"
                style="width:100%;padding:.85rem;background:#e50914;color:#fff;font-weight:700;font-size:1rem;border:none;border-radius:8px;cursor:pointer;">
            🔒 Confirm &amp; Pay <?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($amount, 2) ?>
        </button>
        <p style="text-align:center;font-size:.8rem;color:#aaa;margin-top:.8rem;">
            Secure checkout &nbsp;·&nbsp; Cancel anytime &nbsp;·&nbsp; No hidden fees
        </p>
    </form>

<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
