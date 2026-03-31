<?php
/**
 * Subscriber Account Page
 * NewsXpressLive
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

$user = getCurrentUser($pdo);
if (!$user) {
    header('Location: ' . SITE_URL . '/subscribe/login.php');
    exit;
}

// Cancel subscription action
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    try {
        $pdo->prepare(
            "UPDATE users SET subscription_status = 'cancelled' WHERE id = :id"
        )->execute([':id' => $user['id']]);
        $pdo->prepare(
            "UPDATE subscriptions SET status = 'cancelled'
             WHERE user_id = :uid AND status = 'active'"
        )->execute([':uid' => $user['id']]);
        $message = 'Your subscription has been cancelled. Access continues until the end of the billing period.';
        // Refresh user
        $user = getCurrentUser($pdo);
    } catch (PDOException $e) {
        $message = 'An error occurred. Please try again.';
    }
}

// Load subscription history
$subHistory = [];
try {
    $histStmt = $pdo->prepare(
        'SELECT plan, amount, currency, status, starts_at, expires_at, gateway
         FROM subscriptions WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10'
    );
    $histStmt->execute([':uid' => $user['id']]);
    $subHistory = $histStmt->fetchAll();
} catch (PDOException $e) {}

$seoMeta = [
    'title'       => 'My Account – ' . SITE_NAME,
    'description' => 'Manage your NewsXpressLive subscription.',
    'url'         => SITE_URL . '/subscribe/account.php',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width:620px;margin:2rem auto;">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
        <h1 style="font-size:1.5rem;font-weight:800;">My Account</h1>
        <a href="<?= SITE_URL ?>/subscribe/logout.php"
           style="font-size:.85rem;color:#e50914;text-decoration:none;">Logout</a>
    </div>

    <?php if ($message): ?>
    <div style="background:#f0fff0;border:1px solid #b2dfdb;color:#1a5e20;padding:.75rem 1rem;border-radius:6px;margin-bottom:1.2rem;font-size:.9rem;">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Profile card -->
    <div style="border:1px solid #eee;border-radius:10px;padding:1.5rem;margin-bottom:1.5rem;background:#fafafa;">
        <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">Profile</h3>
        <p style="margin:.3rem 0;"><strong>Name:</strong> <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></p>
        <p style="margin:.3rem 0;"><strong>Email:</strong> <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <!-- Subscription card -->
    <div style="border:1px solid #eee;border-radius:10px;padding:1.5rem;margin-bottom:1.5rem;background:#fafafa;">
        <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">Subscription</h3>
        <?php if (isSubscribed($user)): ?>
            <p style="margin:.3rem 0;">
                <strong>Status:</strong>
                <span style="color:#1a7a1a;font-weight:700;">✅ Active</span>
            </p>
            <p style="margin:.3rem 0;">
                <strong>Plan:</strong> <?= ucfirst(htmlspecialchars($user['subscription_plan'], ENT_QUOTES, 'UTF-8')) ?>
            </p>
            <p style="margin:.3rem 0;">
                <strong>Renews / Expires:</strong>
                <?= date('M j, Y', strtotime($user['subscription_expires'])) ?>
            </p>
            <form method="POST" action="" style="margin-top:1rem;"
                  onsubmit="return confirm('Are you sure you want to cancel your subscription?');">
                <input type="hidden" name="action" value="cancel">
                <button type="submit"
                        style="padding:.5rem 1.2rem;border:1px solid #e50914;color:#e50914;background:#fff;border-radius:6px;cursor:pointer;font-size:.9rem;">
                    Cancel Subscription
                </button>
            </form>
        <?php else: ?>
            <p style="margin:.3rem 0;">
                <strong>Status:</strong>
                <span style="color:#888;">
                    <?= ucfirst(htmlspecialchars($user['subscription_status'], ENT_QUOTES, 'UTF-8')) ?>
                </span>
            </p>
            <a href="<?= SITE_URL ?>/subscribe/"
               style="display:inline-block;margin-top:.8rem;padding:.6rem 1.4rem;background:#e50914;color:#fff;border-radius:6px;font-weight:700;text-decoration:none;font-size:.9rem;">
               Upgrade to Premium →
            </a>
        <?php endif; ?>
    </div>

    <!-- Billing history -->
    <?php if (!empty($subHistory)): ?>
    <div style="border:1px solid #eee;border-radius:10px;padding:1.5rem;background:#fafafa;">
        <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">Billing History</h3>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <thead>
                <tr style="border-bottom:2px solid #eee;text-align:left;color:#888;">
                    <th style="padding:.4rem .6rem;">Plan</th>
                    <th style="padding:.4rem .6rem;">Amount</th>
                    <th style="padding:.4rem .6rem;">Status</th>
                    <th style="padding:.4rem .6rem;">Expires</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subHistory as $s): ?>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:.4rem .6rem;"><?= ucfirst(htmlspecialchars($s['plan'], ENT_QUOTES, 'UTF-8')) ?></td>
                    <td style="padding:.4rem .6rem;"><?= htmlspecialchars($s['currency'], ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$s['amount'], 2) ?></td>
                    <td style="padding:.4rem .6rem;"><?= ucfirst(htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8')) ?></td>
                    <td style="padding:.4rem .6rem;"><?= date('M j, Y', strtotime($s['expires_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
