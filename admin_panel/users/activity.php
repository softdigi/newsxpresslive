<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, name, role FROM admin_users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

/* Recent News by User */
$recentNews = [];

if ($user['role'] === 'reporter' || $user['role'] === 'editor') {
    $stmtNews = $pdo->prepare("
        SELECT id, title, status, views, created_at 
        FROM news 
        WHERE reporter_id=? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmtNews->execute([$id]);
    $recentNews = $stmtNews->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>User Activity</h1>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<h5>User: <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</h5>
<hr>

<?php if (!empty($recentNews)): ?>
<div class="table-responsive">
<table class="table table-bordered table-striped">
<thead>
<tr>
    <th>ID</th>
    <th>Title</th>
    <th>Status</th>
    <th>Views</th>
    <th>Created</th>
</tr>
</thead>
<tbody>
<?php foreach ($recentNews as $news): ?>
<tr>
    <td><?= (int)$news['id'] ?></td>
    <td><?= htmlspecialchars($news['title']) ?></td>
    <td>
        <?php if ($news['status'] === 'approved'): ?>
            <span class="badge badge-success">Approved</span>
        <?php elseif ($news['status'] === 'pending'): ?>
            <span class="badge badge-warning">Pending</span>
        <?php else: ?>
            <span class="badge badge-danger">Rejected</span>
        <?php endif; ?>
    </td>
    <td><?= (int)$news['views'] ?></td>
    <td><?= htmlspecialchars($news['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p>No recent activity found.</p>
<?php endif; ?>

<a href="view.php?id=<?= (int)$id ?>" class="btn btn-secondary">Back</a>

</div>
</div>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
