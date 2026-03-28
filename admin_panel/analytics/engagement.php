<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

$stmt=$pdo->prepare("
SELECT status,COUNT(*) as total
FROM news
GROUP BY status
");
$stmt->execute();
$data=$stmt->fetchAll(PDO::FETCH_ASSOC);

$labels=[];$values=[];
foreach($data as $row){
$labels[]=$row['status'];
$values[]=(int)$row['total'];
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Engagement</h1></section>
<section class="content">
<canvas id="engagementChart"></canvas>
</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('engagementChart'),{
type:'pie',
data:{
labels: <?= json_encode($labels) ?>,
datasets:[{data:<?= json_encode($values) ?>}]
}
});
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
