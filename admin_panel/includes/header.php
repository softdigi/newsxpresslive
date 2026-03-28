<?php
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__.'/permissions.php';
require_once __DIR__.'/config.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel - News Xpress Live</title>
  <link rel="stylesheet" href="/newsxpresslive_api/admin_panel/assets/css/admin.css">
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

    <a href="/newsxpresslive_api/admin_panel/dashboard.php">📊 Dashboard</a>

    <?php if (can(['admin','super_admin'])): ?>
      <div class="nav-section">User Management</div>
      <a href="/newsxpresslive_api/admin_panel/users/index.php">👥 Users</a>
      <a href="/newsxpresslive_api/admin_panel/users/create.php">➕ Add User</a>
      <a href="/newsxpresslive_api/admin_panel/reporters/index.php">📝 Reporter Verification</a>
      <a href="/newsxpresslive_api/admin_panel/agencies/index.php">🏢 Agencies</a>
    <?php endif; ?>

    <?php if (can(['editor','admin','super_admin'])): ?>
      <div class="nav-section">Content</div>
      <a href="/newsxpresslive_api/admin_panel/news/pending.php">📰 Pending News</a>
    <?php endif; ?>

    <?php if ($_SESSION['admin']['role'] === 'reporter'): ?>
      <div class="nav-section">Reporter</div>
      <a href="/newsxpresslive_api/admin_panel/reporters/dashboard.php">📝 My Dashboard</a>
      <a href="/newsxpresslive_api/admin_panel/news/my_news.php">📰 My News</a>
    <?php endif; ?>

    <div class="nav-section">System</div>
    <a href="/newsxpresslive_api/admin_panel/notifications/digest.php">🤖 AI Digest</a>
    <a href="/newsxpresslive_api/admin_panel/viral/index.php">🔥 Viral</a>
    <a href="/newsxpresslive_api/admin_panel/payouts/index.php">💰 Payouts</a>
    <a href="/newsxpresslive_api/admin_panel/logout.php">🚪 Logout</a>

  </nav>

</aside>

<!-- MAIN CONTENT -->
<main class="content">
