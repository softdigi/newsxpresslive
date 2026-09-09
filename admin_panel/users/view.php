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

$stmt = $pdo->prepare("
    SELECT u.*, a.name as agency_name 
    FROM admin_users u
    LEFT JOIN agencies a ON u.agency_id = a.id
    WHERE u.id=?
");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

/* Additional Statistics */

$totalNews = 0;
$approvedNews = 0;
$pendingNews = 0;

if ($user['role'] === 'reporter' || $user['role'] === 'editor') {
    $stmtNews = $pdo->prepare("SELECT COUNT(*) FROM news WHERE reporter_id=?");
    $stmtNews->execute([$user['id']]);
    $totalNews = (int)$stmtNews->fetchColumn();

    $stmtApproved = $pdo->prepare("SELECT COUNT(*) FROM news WHERE reporter_id=? AND status='approved'");
    $stmtApproved->execute([$user['id']]);
    $approvedNews = (int)$stmtApproved->fetchColumn();

    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM news WHERE reporter_id=? AND status='pending'");
    $stmtPending->execute([$user['id']]);
    $pendingNews = (int)$stmtPending->fetchColumn();
}
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>User Details</h1>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<table class="table table-bordered">
<tr>
    <th>ID</th>
    <td><?= htmlspecialchars($user['id']) ?></td>
</tr>
<tr>
    <th>Name</th>
    <td><?= htmlspecialchars($user['name']) ?></td>
</tr>
<tr>
    <th>Email</th>
    <td><?= htmlspecialchars($user['email']) ?></td>
</tr>
<tr>
    <th>Role</th>
    <td><?= htmlspecialchars($user['role']) ?></td>
</tr>
<tr>
    <th>Agency</th>
    <td><?= htmlspecialchars($user['agency_name'] ?? '-') ?></td>
</tr>
<tr>
    <th>Status</th>
    <td>
        <?php if($user['status']=='active'): ?>
            <span class="badge badge-success">Active</span>
        <?php else: ?>
            <span class="badge badge-danger">Blocked</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th>Created At</th>
    <td><?= htmlspecialchars($user['created_at']) ?></td>
</tr>
</table>

<?php if ($user['role'] === 'reporter' || $user['role'] === 'editor'): ?>
<hr>
<h5>News Statistics</h5>
<table class="table table-bordered">
<tr>
    <th>Total News</th>
    <td><?= $totalNews ?></td>
</tr>
<tr>
    <th>Approved</th>
    <td><?= $approvedNews ?></td>
</tr>
<tr>
    <th>Pending</th>
    <td><?= $pendingNews ?></td>
</tr>
</table>
<?php endif; ?>

<a href="index.php" class="btn btn-secondary">Back</a>
<a href="edit.php?id=<?= (int)$user['id'] ?>" class="btn btn-warning">Edit</a>

</div>
</div>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
