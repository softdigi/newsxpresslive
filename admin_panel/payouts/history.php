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
    SELECT vb.*, r.name
    FROM viral_boosts vb
    JOIN admin_users r ON vb.reporter_id=r.id
    WHERE vb.status IN ('paid','rejected')
    ORDER BY vb.created_at DESC
");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Payout History</h1></section>
<section class="content">
<table class="table table-bordered">
<tr>
<th>Reporter</th>
<th>Bonus</th>
<th>Status</th>
<th>Date</th>
</tr>
<?php foreach($data as $row): ?>
<tr>
<td><?= htmlspecialchars($row['name']) ?></td>
<td><?= number_format($row['reporter_bonus'],2) ?></td>
<td><?= htmlspecialchars($row['status']) ?></td>
<td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
