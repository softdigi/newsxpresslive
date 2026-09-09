<?php
/**
 * web/referral/index.php
 * Refer & Earn – User Dashboard
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/referral.php';

$user  = getCurrentUser($pdo);
$stats = [];
$history = [];

if ($user) {
    // Ensure the user has a code (idempotent)
    generateReferralCode($pdo, (int)$user['id']);
    $stats   = getUserReferralStats($pdo, (int)$user['id']);
    $history = getUserRewardHistory($pdo, (int)$user['id'], 30);
}

$rewardCfg = getRewardConfig($pdo);

$seoMeta = [
    'title'       => 'Refer & Earn – ' . SITE_NAME,
    'description' => 'Share NewsXpressLive with friends and earn reward points for every signup and subscription.',
    'url'         => SITE_URL . '/referral/',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/referral.css">

<div class="container page-body">
<div class="layout-main" style="max-width:760px;margin:0 auto;">

<!-- Hero -->
<div class="referral-hero">
    <h1>🎉 Refer &amp; Earn</h1>
    <p>Share NewsXpressLive with friends and earn points for every signup and subscription.</p>
</div>

<?php if (!$user): ?>
<!-- Login CTA -->
<div class="referral-login-cta">
    <h2>Sign in to get your referral code</h2>
    <p>Create a free account or log in to access your unique referral link and start earning rewards.</p>
    <a href="<?= SITE_URL ?>/subscribe/register.php" class="btn-primary">Create Free Account</a>
    &nbsp;
    <a href="<?= SITE_URL ?>/subscribe/login.php" class="btn-primary" style="background:#333;">Log In</a>
</div>

<?php else: ?>

<!-- Stats -->
<div class="referral-stats">
    <div class="referral-stat-card">
        <div class="referral-stat-card__value"><?= number_format($stats['total_points']) ?></div>
        <div class="referral-stat-card__label">Total Points</div>
    </div>
    <div class="referral-stat-card">
        <div class="referral-stat-card__value"><?= number_format($stats['direct_referrals']) ?></div>
        <div class="referral-stat-card__label">Direct Referrals</div>
    </div>
    <div class="referral-stat-card">
        <div class="referral-stat-card__value"><?= number_format($stats['total_referrals']) ?></div>
        <div class="referral-stat-card__label">Total Network</div>
    </div>
    <div class="referral-stat-card">
        <div class="referral-stat-card__value"><?= number_format($stats['clicks']) ?></div>
        <div class="referral-stat-card__label">Link Clicks</div>
    </div>
</div>

<!-- Code box -->
<div class="referral-code-box">
    <h2>Your Referral Code &amp; Link</h2>

    <div class="referral-code-display">
        <div class="referral-code-chip" id="rfc-chip"><?= htmlspecialchars($stats['referral_code'], ENT_QUOTES, 'UTF-8') ?></div>
        <button class="referral-copy-btn" id="rfc-copy-code" onclick="copyText('<?= htmlspecialchars($stats['referral_code'], ENT_QUOTES, 'UTF-8') ?>', this, 'Code Copied!')">
            📋 Copy Code
        </button>
    </div>

    <div class="referral-url-row">
        <input class="referral-url-input" type="text" readonly
               id="rfc-url-input"
               value="<?= htmlspecialchars($stats['referral_url'], ENT_QUOTES, 'UTF-8') ?>">
        <button class="referral-copy-link-btn" onclick="copyText(document.getElementById('rfc-url-input').value, this, '✅ Copied!')">
            🔗 Copy Link
        </button>
    </div>

    <div class="referral-share-row">
        <a class="referral-share-btn referral-share-btn--wa"
           href="https://wa.me/?text=<?= urlencode('Read breaking news on NewsXpressLive! Sign up free: ' . $stats['referral_url']) ?>"
           target="_blank" rel="noopener">💬 WhatsApp</a>
        <a class="referral-share-btn referral-share-btn--tw"
           href="https://twitter.com/intent/tweet?text=<?= urlencode('Stay updated with @NewsXpressLive!') ?>&url=<?= urlencode($stats['referral_url']) ?>"
           target="_blank" rel="noopener">🐦 Twitter</a>
        <a class="referral-share-btn referral-share-btn--fb"
           href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($stats['referral_url']) ?>"
           target="_blank" rel="noopener">👍 Facebook</a>
        <button class="referral-share-btn referral-share-btn--copy"
                onclick="nativeShare('<?= htmlspecialchars($stats['referral_url'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(SITE_NAME . ' – Refer & Earn', ENT_QUOTES, 'UTF-8') ?>')">
            📤 Share
        </button>
    </div>
</div>

<?php endif; ?>

<!-- How it works -->
<div class="referral-how">
    <h2>How It Works</h2>
    <div class="referral-steps">
        <div class="referral-step">
            <div class="referral-step__icon">🔗</div>
            <div class="referral-step__title">Share Your Link</div>
            <div class="referral-step__desc">Share your unique referral link on social media or with friends.</div>
        </div>
        <div class="referral-step">
            <div class="referral-step__icon">📱</div>
            <div class="referral-step__title">Friend Installs</div>
            <div class="referral-step__points">+<?= $rewardCfg['install'] ?> pts</div>
            <div class="referral-step__desc">Earn points when someone clicks your link and visits the site.</div>
        </div>
        <div class="referral-step">
            <div class="referral-step__icon">✍️</div>
            <div class="referral-step__title">Friend Signs Up</div>
            <div class="referral-step__points">+<?= $rewardCfg['signup'] ?> pts</div>
            <div class="referral-step__desc">Earn more when they create a free account.</div>
        </div>
        <div class="referral-step">
            <div class="referral-step__icon">⭐</div>
            <div class="referral-step__title">Friend Subscribes</div>
            <div class="referral-step__points">+<?= $rewardCfg['subscription'] ?> pts</div>
            <div class="referral-step__desc">Earn big when they go Premium!</div>
        </div>
    </div>
    <p style="margin-top:1rem;font-size:.82rem;color:#777;text-align:center;">
        Multi-level rewards: Your referrer's referrers also earn
        <?= $rewardCfg['level2_pct'] ?>% (level 2) and <?= $rewardCfg['level3_pct'] ?>% (level 3) bonus points.
    </p>
</div>

<?php if ($user && !empty($history)): ?>
<!-- Reward history -->
<div class="referral-history">
    <h2>Reward History</h2>
    <table class="referral-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Friend</th>
                <th>Event</th>
                <th>Level</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $row): ?>
            <tr>
                <td style="color:#888;font-size:.8rem;"><?= htmlspecialchars(date('M j, Y', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['referred_name'] ?: 'User', ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-transform:capitalize;"><?= htmlspecialchars($row['event'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="referral-level-badge referral-level-badge--<?= (int)$row['level'] ?>">L<?= (int)$row['level'] ?></span></td>
                <td class="referral-points-cell">+<?= (int)$row['points'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($user): ?>
<div class="referral-history" style="text-align:center;color:#888;padding:2rem;">
    <p>No rewards yet. Share your link to start earning!</p>
</div>
<?php endif; ?>

<!-- Leaderboard teaser -->
<div style="text-align:center;margin-bottom:2rem;">
    <a href="<?= SITE_URL ?>/referral/leaderboard.php" class="btn-primary">
        🏆 View Full Leaderboard
    </a>
</div>

</div><!-- /.layout-main -->
</div><!-- /.container -->

<script>
function copyText(text, btn, label) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = label;
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 2000);
    }).catch(() => {
        // Fallback for older browsers
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        const orig = btn.textContent;
        btn.textContent = label;
        setTimeout(() => { btn.textContent = orig; }, 2000);
    });
}

function nativeShare(url, title) {
    if (navigator.share) {
        navigator.share({ title, url }).catch(() => {});
    } else {
        copyText(url, document.querySelector('.referral-share-btn--copy'), '✅ Copied!');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
