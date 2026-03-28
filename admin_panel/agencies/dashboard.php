<?php

// SECURITY: Disable debug output in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

/* Only agency allowed */
if ($_SESSION['admin']['role'] !== 'agency') {
    exit('Access denied');
}

$agencyId = $_SESSION['admin']['id'];

/* Total reporters */
$totalReporters = $pdo->prepare("
    SELECT COUNT(*) 
    FROM admin_users 
    WHERE role='reporter' AND agency_id=?
");
$totalReporters->execute([$agencyId]);
$totalReporters = $totalReporters->fetchColumn();

/* News stats */
$newsStats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected
    FROM news
    WHERE agency_id=?
");
$newsStats->execute([$agencyId]);
$news = $newsStats->fetch(PDO::FETCH_ASSOC);

/* Views */
$totalViews = $pdo->prepare("
    SELECT SUM(views)
    FROM news
    WHERE agency_id=?
");
$totalViews->execute([$agencyId]);
$totalViews = $totalViews->fetchColumn() ?: 0;

/* Earnings */
$totalEarnings = $pdo->prepare("
    SELECT SUM(reporter_bonus)
    FROM viral_boosts
    WHERE agency_id=?
");
$totalEarnings->execute([$agencyId]);
$totalEarnings = $totalEarnings->fetchColumn() ?: 0;
?>

<h2>Agency Dashboard</h2>

<section class="stats-grid">

  <div class="stat-card">
    <div class="stat-title">Reporters</div>
    <div class="stat-value"><?= number_format($totalReporters) ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-title">Total News</div>
    <div class="stat-value"><?= number_format($news['total']) ?></div>
  </div>

  <div class="stat-card success">
    <div class="stat-title">Approved</div>
    <div class="stat-value"><?= number_format($news['approved']) ?></div>
  </div>

  <div class="stat-card danger">
    <div class="stat-title">Rejected</div>
    <div class="stat-value"><?= number_format($news['rejected']) ?></div>
  </div>

  <div class="stat-card accent">
    <div class="stat-title">Total Views</div>
    <div class="stat-value"><?= number_format($totalViews) ?></div>
  </div>

  <div class="stat-card highlight">
    <div class="stat-title">Total Earnings</div>
    <div class="stat-value">₹<?= number_format($totalEarnings, 2) ?></div>
  </div>

</section>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
