<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

/* Only reporter allowed */
if ($_SESSION['admin']['role'] !== 'reporter') {
    exit('Access denied');
}

$reporterId = $_SESSION['admin']['id'];

/* Reporter stats */
$totalMyNews = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE reporter_id=?"
);
$totalMyNews->execute([$reporterId]);

$approved = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE reporter_id=? AND status='approved'"
);
$approved->execute([$reporterId]);

$pending = $pdo->prepare(
    "SELECT COUNT(*) FROM news WHERE reporter_id=? AND status='pending'"
);
$pending->execute([$reporterId]);
?>

<h2>📝 Reporter Dashboard</h2>

<section class="stats-grid">
  <div class="stat-card">
    <div class="stat-title">My News</div>
    <div class="stat-value"><?= $totalMyNews->fetchColumn() ?></div>
  </div>

  <div class="stat-card accent">
    <div class="stat-title">Approved</div>
    <div class="stat-value"><?= $approved->fetchColumn() ?></div>
  </div>

  <div class="stat-card danger">
    <div class="stat-title">Pending</div>
    <div class="stat-value"><?= $pending->fetchColumn() ?></div>
  </div>
</section>

<section class="card p-4">
  <h3>Quick Actions</h3>

  <a href="<?= ADMIN_URL ?>/news/create.php"
     class="btn btn-primary me-2">
     ➕ Submit News
  </a>

  <a href="<?= ADMIN_URL ?>/news/my_news.php"
     class="btn btn-secondary">
     📰 My News
  </a>

</section>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
