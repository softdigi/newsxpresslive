<?php
/**
 * Subscriber Registration
 * NewsXpressLive
 *
 * Creates a free account then proceeds to checkout for the chosen plan.
 * Already-logged-in users are sent straight to checkout.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

// Already logged in → go to checkout
$user = getCurrentUser($pdo);
if ($user) {
    $plan = in_array($_GET['plan'] ?? '', ['monthly', 'yearly'], true) ? $_GET['plan'] : 'monthly';
    header('Location: ' . SITE_URL . '/subscribe/checkout.php?plan=' . $plan);
    exit;
}

$plan   = in_array($_GET['plan'] ?? '', ['monthly', 'yearly'], true) ? $_GET['plan'] : 'monthly';
$error  = '';
$prices = getPlanPrices($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $planPost = in_array($_POST['plan'] ?? '', ['monthly', 'yearly'], true)
                ? $_POST['plan'] : 'monthly';

    if ($name === '' || $email === '' || strlen($password) < 8) {
        $error = 'All fields are required. Password must be at least 8 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $uid = registerUser($pdo, $name, $email, $password);
        if ($uid === false) {
            $error = 'That email address is already registered. <a href="' . SITE_URL . '/subscribe/login.php?plan=' . htmlspecialchars($planPost, ENT_QUOTES, 'UTF-8') . '">Login instead</a>.';
        } else {
            header('Location: ' . SITE_URL . '/subscribe/checkout.php?plan=' . urlencode($planPost));
            exit;
        }
    }
}

$seoMeta = [
    'title'       => 'Create Account – ' . SITE_NAME,
    'description' => 'Create your NewsXpressLive account to access premium content.',
    'url'         => SITE_URL . '/subscribe/register.php',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width:480px;margin:2rem auto;">

    <h1 style="font-size:1.6rem;font-weight:800;margin-bottom:.4rem;">Create Account</h1>
    <p style="color:#666;margin-bottom:1.5rem;">
        You're signing up for the
        <strong><?= ucfirst(htmlspecialchars($plan, ENT_QUOTES, 'UTF-8')) ?> plan</strong>
        (<?= htmlspecialchars($prices['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format($plan === 'monthly' ? $prices['monthly'] : $prices['yearly'], 2) ?>).
    </p>

    <?php if ($error): ?>
    <div style="background:#fff3f3;border:1px solid #f5c6cb;color:#721c24;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;">
        <?= $error ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="plan" value="<?= htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') ?>">

        <div style="margin-bottom:1rem;">
            <label style="display:block;font-weight:600;margin-bottom:.4rem;">Full Name</label>
            <input type="text" name="name" required autocomplete="name"
                   value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%;padding:.65rem .9rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;">
        </div>

        <div style="margin-bottom:1rem;">
            <label style="display:block;font-weight:600;margin-bottom:.4rem;">Email Address</label>
            <input type="email" name="email" required autocomplete="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%;padding:.65rem .9rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;">
        </div>

        <div style="margin-bottom:1.5rem;">
            <label style="display:block;font-weight:600;margin-bottom:.4rem;">Password <span style="font-weight:400;color:#888;">(min. 8 characters)</span></label>
            <input type="password" name="password" required autocomplete="new-password" minlength="8"
                   style="width:100%;padding:.65rem .9rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;">
        </div>

        <button type="submit"
                style="width:100%;padding:.8rem;background:#e50914;color:#fff;font-weight:700;font-size:1rem;border:none;border-radius:8px;cursor:pointer;">
            Continue to Payment →
        </button>
    </form>

    <p style="text-align:center;margin-top:1.2rem;color:#888;font-size:.9rem;">
        Already have an account? <a href="<?= SITE_URL ?>/subscribe/login.php?plan=<?= htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') ?>">Login</a>
    </p>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
