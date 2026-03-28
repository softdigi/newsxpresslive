<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

$stmt = $pdo->prepare("
    SELECT DATE(created_at) as date,
           SUM(reporter_bonus) as total_paid
    FROM viral_boosts
    WHERE status='paid'
    GROUP BY DATE(created_at)
    ORDER BY date DESC
");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Payout Reports</h1></section>
<section class="content">
<table class="table table-bordered">
<tr><th>Date</th><th>Total Paid</th></tr>
<?php foreach($data as $row): ?>
<tr>
<td><?= htmlspecialchars($row['date']) ?></td>
<td><?= number_format($row['total_paid'],2) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
