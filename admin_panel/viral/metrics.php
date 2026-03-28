<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if ($_SESSION['admin']['role'] !== 'super_admin' && $_SESSION['admin']['role'] !== 'admin') {
    exit('Access denied');
}

$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_boosts,
        SUM(reporter_bonus) as total_earnings,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_boosts,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed_boosts
    FROM viral_boosts
");
$stmt->execute();
$metrics = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Viral Metrics</h1></section>
<section class="content">
<table class="table table-bordered">
<tr><th>Total Boosts</th><td><?= (int)$metrics['total_boosts'] ?></td></tr>
<tr><th>Total Earnings</th><td><?= number_format($metrics['total_earnings'],2) ?></td></tr>
<tr><th>Active Boosts</th><td><?= (int)$metrics['active_boosts'] ?></td></tr>
<tr><th>Completed Boosts</th><td><?= (int)$metrics['completed_boosts'] ?></td></tr>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
