<?php
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__.'/permissions.php';
require_once __DIR__.'/config.php';

// FIX 3: Apply security headers to every admin panel page.
require_once __DIR__ . '/../../helpers/security_headers.php';
setSecurityHeaders();

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel - News Xpress Live</title>
  <link rel="stylesheet" href="<?= ADMIN_URL ?>/assets/css/admin.css">
</head>

<body>
<div class="admin-wrapper">

<!-- SIDEBAR -->
<aside class="sidebar">

  <!-- BRAND -->
  <div class="brand">
    📰 <strong>News Xpress Live</strong>
  </div>

  <!-- USER PROFILE -->
  <?php if (isset($_SESSION['admin'])): ?>

    <div class="sidebar-profile">

      <div class="profile-name">
        <?= htmlspecialchars($_SESSION['admin']['name'], ENT_QUOTES, 'UTF-8') ?>

        <?php if ($_SESSION['admin']['role'] === 'reporter'): ?>
          <?php
            $stmt = $pdo->prepare(
              "SELECT is_verified FROM reporter_profiles WHERE user_id=?"
            );
            $stmt->execute([$_SESSION['admin']['id']]);
            $isVerified = (int)$stmt->fetchColumn();
          ?>
          <?php if ($isVerified): ?>
            <span class="blue-tick" title="Verified Reporter">✔</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="profile-role">
        <span class="role-badge <?= $_SESSION['admin']['role'] ?>">
          <?= strtoupper($_SESSION['admin']['role']) ?>
        </span>
      </div>

    </div>

  <?php endif; ?>

  <!-- NAVIGATION -->
  <nav class="nav">

    <a href="<?= ADMIN_URL ?>/dashboard.php">📊 Dashboard</a>

    <?php if (can(['admin','super_admin'])): ?>
      <div class="nav-section">User Management</div>
      <a href="<?= ADMIN_URL ?>/users/index.php">👥 Users</a>
      <a href="<?= ADMIN_URL ?>/users/create.php">➕ Add User</a>
      <a href="<?= ADMIN_URL ?>/reporters/index.php">📝 Reporter Verification</a>
      <a href="<?= ADMIN_URL ?>/agencies/index.php">🏢 Agencies</a>
    <?php endif; ?>

    <?php if (can(['editor','admin','super_admin'])): ?>
      <div class="nav-section">Content</div>
      <a href="<?= ADMIN_URL ?>/news/pending.php">📰 Pending News</a>
      <a href="<?= ADMIN_URL ?>/news/ai_generate.php">✨ AI News Generator</a>
    <?php endif; ?>

    <?php if ($_SESSION['admin']['role'] === 'reporter'): ?>
      <div class="nav-section">Reporter</div>
      <a href="<?= ADMIN_URL ?>/reporters/dashboard.php">📝 My Dashboard</a>
      <a href="<?= ADMIN_URL ?>/news/my_news.php">📰 My News</a>
      <a href="<?= ADMIN_URL ?>/news/ai_generate.php">✨ AI News Generator</a>
    <?php endif; ?>

    <div class="nav-section">System</div>
    <a href="<?= ADMIN_URL ?>/notifications/digest.php">🤖 AI Digest</a>
    <a href="<?= ADMIN_URL ?>/viral/index.php">🔥 Viral</a>
    <a href="<?= ADMIN_URL ?>/payouts/index.php">💰 Payouts</a>
    <a href="<?= ADMIN_URL ?>/comments/index.php">💬 Comments</a>
    <?php if (can(['admin','super_admin'])): ?>
    <a href="<?= ADMIN_URL ?>/settings/ads.php">📢 Ad Manager</a>
    <?php endif; ?>

    <?php if (can(['admin','super_admin','editor'])): ?>
    <div class="nav-section">Analytics</div>
    <a href="<?= ADMIN_URL ?>/analytics/overview.php">📊 Overview</a>
    <a href="<?= ADMIN_URL ?>/analytics/heatmap.php">🖱️ Click Heatmap</a>
    <a href="<?= ADMIN_URL ?>/analytics/ab_tests.php">🧪 A/B Tests</a>
    <?php if (can(['admin','super_admin'])): ?>
    <a href="<?= ADMIN_URL ?>/analytics/revenue.php">💰 Revenue</a>
    <?php endif; ?>
    <a href="<?= ADMIN_URL ?>/analytics/traffic.php">📈 Traffic</a>
    <a href="<?= ADMIN_URL ?>/analytics/engagement.php">🤝 Engagement</a>
    <a href="<?= ADMIN_URL ?>/analytics/top_content.php">⭐ Top Content</a>
    <?php endif; ?>

    <a href="<?= ADMIN_URL ?>/logout.php">🚪 Logout</a>

    <?php if (can(['admin','super_admin'])): ?>
      <div class="nav-section">Rewards</div>
      <a href="<?= ADMIN_URL ?>/rewards/dashboard.php">📊 Reward Dashboard</a>
      <a href="<?= ADMIN_URL ?>/rewards/withdrawals.php">💳 Withdrawals</a>
      <?php if ($_SESSION['admin']['role'] === 'super_admin'): ?>
      <a href="<?= ADMIN_URL ?>/rewards/config.php">⚙️ Reward Config</a>
      <a href="<?= ADMIN_URL ?>/rewards/config_audit.php">📋 Config Audit</a>
      <?php endif; ?>
    <?php endif; ?>

  </nav>

</aside>

<!-- MAIN CONTENT -->
<main class="content">
