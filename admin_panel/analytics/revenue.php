<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

$stmt = $pdo->prepare("
    SELECT DATE(created_at) as date,
           SUM(reporter_bonus) as revenue
    FROM viral_boosts
    WHERE status='paid'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels=[];$values=[];
foreach($data as $row){
    $labels[]=$row['date'];
    $values[]=(float)$row['revenue'];
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Revenue</h1></section>
<section class="content">
<canvas id="revenueChart"></canvas>
</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('revenueChart'),{
type:'bar',
data:{
labels: <?= json_encode($labels) ?>,
datasets:[{label:'Revenue',data:<?= json_encode($values) ?>,backgroundColor:'green'}]
}
});
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
