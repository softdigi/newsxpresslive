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
    SELECT r.name,
           SUM(vb.reporter_bonus) as total_bonus,
           COUNT(vb.id) as total_boosts
    FROM viral_boosts vb
    LEFT JOIN admin_users r ON vb.reporter_id = r.id
    GROUP BY vb.reporter_id
    ORDER BY total_bonus DESC
");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Viral Leaderboard</h1></section>
<section class="content">
<table class="table table-bordered">
<tr>
<th>Reporter</th>
<th>Total Boosts</th>
<th>Total Earnings</th>
</tr>
<?php foreach($data as $row): ?>
<tr>
<td><?= htmlspecialchars($row['name']) ?></td>
<td><?= (int)$row['total_boosts'] ?></td>
<td><?= number_format($row['total_bonus'],2) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
