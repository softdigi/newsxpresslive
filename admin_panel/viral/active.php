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
    SELECT vb.*, n.title, r.name as reporter_name
    FROM viral_boosts vb
    LEFT JOIN news n ON vb.news_id = n.id
    LEFT JOIN admin_users r ON vb.reporter_id = r.id
    WHERE vb.status='active'
    ORDER BY vb.created_at DESC
");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Active Viral Boosts</h1></section>
<section class="content">
<table class="table table-bordered">
<tr>
<th>ID</th>
<th>News</th>
<th>Reporter</th>
<th>Level</th>
<th>Bonus</th>
<th>Date</th>
</tr>
<?php foreach($data as $row): ?>
<tr>
<td><?= (int)$row['id'] ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= htmlspecialchars($row['reporter_name']) ?></td>
<td><?= htmlspecialchars($row['boost_level']) ?></td>
<td><?= number_format($row['reporter_bonus'],2) ?></td>
<td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
