<?php
/**
 * web/referral/leaderboard.php
 * Public Referral Leaderboard
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/referral.php';

$user  = getCurrentUser($pdo);
$board = getLeaderboard($pdo, 50);

// Find current user's rank if logged in
$myRank = null;
if ($user) {
    foreach ($board as $i => $row) {
        if ((int)$row['id'] === (int)$user['id']) {
            $myRank = $i + 1;
            break;
        }
    }
}

$seoMeta = [
    'title'       => 'Referral Leaderboard – ' . SITE_NAME,
    'description' => 'Top referrers on NewsXpressLive. Earn points by sharing your referral link.',
    'url'         => SITE_URL . '/referral/leaderboard.php',
    'type'        => 'website',
];
require_once __DIR__ . '/../includes/header.php';

$rankIcons = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/referral.css">

<div class="container page-body">
<div class="layout-main" style="max-width:640px;margin:0 auto;">

<!-- Hero -->
<div class="referral-hero" style="margin-bottom:1.5rem;">
    <h1>🏆 Leaderboard</h1>
    <p>Top referrers ranked by total points earned.</p>
</div>

<?php if ($myRank): ?>
<div style="background:#fff3f3;border:1px solid #f5c6cb;border-radius:10px;padding:.85rem 1.2rem;margin-bottom:1.5rem;font-size:.9rem;display:flex;align-items:center;gap:.6rem;">
    <span style="font-size:1.4rem;">🎯</span>
    <span>Your current rank: <strong>#<?= $myRank ?></strong></span>
    <a href="<?= SITE_URL ?>/referral/" style="margin-left:auto;color:var(--color-primary,#e50914);font-weight:700;text-decoration:none;">My Dashboard →</a>
</div>
<?php endif; ?>

<div class="referral-leaderboard">
    <h2>Top Referrers</h2>
    <?php if (empty($board)): ?>
    <p style="text-align:center;color:#888;padding:1.5rem 0;">No referrals yet — be the first!</p>
    <?php else: ?>
    <ul class="leaderboard-list">
    <?php foreach ($board as $i => $row):
        $rank   = $i + 1;
        $icon   = $rankIcons[$rank] ?? '#' . $rank;
        $nameParts = explode(' ', $row['name']);
        $display   = $nameParts[0];
        if (count($nameParts) > 1) {
            $display .= ' ' . mb_substr(end($nameParts), 0, 1) . '.';
        }
        $initial = mb_strtoupper(mb_substr($display, 0, 1));
        $isMe    = $user && (int)$row['id'] === (int)$user['id'];
    ?>
    <li class="leaderboard-item" <?= $isMe ? 'style="background:#fffde7;border-radius:8px;padding-left:.5rem;"' : '' ?>>
        <div class="leaderboard-rank leaderboard-rank--<?= min($rank, 4) ?>"><?= $icon ?></div>
        <div class="leaderboard-avatar"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="leaderboard-info">
            <div class="leaderboard-name">
                <?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?>
                <?= $isMe ? ' <span style="font-size:.72rem;background:#e50914;color:#fff;border-radius:3px;padding:1px 5px;">YOU</span>' : '' ?>
            </div>
            <div class="leaderboard-meta"><?= (int)$row['direct_referrals'] ?> direct referral<?= $row['direct_referrals'] != 1 ? 's' : '' ?></div>
        </div>
        <div class="leaderboard-points"><?= number_format((int)$row['referral_points']) ?> pts</div>
    </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- CTA -->
<div style="text-align:center;margin-bottom:2rem;">
    <?php if ($user): ?>
    <a href="<?= SITE_URL ?>/referral/" class="btn-primary">📤 Share My Link</a>
    <?php else: ?>
    <p style="color:#666;margin-bottom:.75rem;">Sign up to get your referral link and climb the leaderboard!</p>
    <a href="<?= SITE_URL ?>/subscribe/register.php" class="btn-primary">Join Free →</a>
    <?php endif; ?>
</div>

</div><!-- /.layout-main -->
</div><!-- /.container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
