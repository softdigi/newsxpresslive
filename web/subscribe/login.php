<?php
/**
 * Subscriber Login
 * NewsXpressLive
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

$user = getCurrentUser($pdo);
if ($user) {
    header('Location: ' . SITE_URL . '/subscribe/account.php');
    exit;
}

$plan  = in_array($_GET['plan'] ?? '', ['monthly', 'yearly'], true) ? $_GET['plan'] : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $planPost = in_array($_POST['plan'] ?? '', ['monthly', 'yearly'], true)
                ? $_POST['plan'] : '';

    $loggedIn = loginUser($pdo, $email, $password);
    if ($loggedIn === false) {
        $error = 'Invalid email or password. Please try again.';
    } else {
        $redirect = $planPost
            ? SITE_URL . '/subscribe/checkout.php?plan=' . urlencode($planPost)
            : SITE_URL . '/subscribe/account.php';
        header('Location: ' . $redirect);
        exit;
    }
}

$seoMeta = [
    'title'       => 'Login – ' . SITE_NAME,
    'description' => 'Login to your NewsXpressLive subscriber account.',
    'url'         => SITE_URL . '/subscribe/login.php',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width:420px;margin:2rem auto;">

    <h1 style="font-size:1.6rem;font-weight:800;margin-bottom:1.5rem;">Login</h1>

    <?php if ($error): ?>
    <div style="background:#fff3f3;border:1px solid #f5c6cb;color:#721c24;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="plan" value="<?= htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') ?>">

        <div style="margin-bottom:1rem;">
            <label style="display:block;font-weight:600;margin-bottom:.4rem;">Email Address</label>
            <input type="email" name="email" required autocomplete="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%;padding:.65rem .9rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;">
        </div>

        <div style="margin-bottom:1.5rem;">
            <label style="display:block;font-weight:600;margin-bottom:.4rem;">Password</label>
            <input type="password" name="password" required autocomplete="current-password"
                   style="width:100%;padding:.65rem .9rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;">
        </div>

        <button type="submit"
                style="width:100%;padding:.8rem;background:#e50914;color:#fff;font-weight:700;font-size:1rem;border:none;border-radius:8px;cursor:pointer;">
            Login
        </button>
    </form>

    <p style="text-align:center;margin-top:1.2rem;color:#888;font-size:.9rem;">
        Don't have an account?
        <a href="<?= SITE_URL ?>/subscribe/register.php<?= $plan ? '?plan=' . htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') : '' ?>">Sign up</a>
    </p>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
