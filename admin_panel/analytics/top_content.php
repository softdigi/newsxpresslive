<?php
// analytics/top_content.php — FIXED: LIMIT/OFFSET interpolation removed
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$total       = (int)$pdo->query("SELECT COUNT(*) FROM news")->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

// FIXED: bindValue for LIMIT/OFFSET
$stmt = $pdo->prepare(
    "SELECT id, title, views FROM news ORDER BY views DESC LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$data = $stmt->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header"><h1>Top Content by Views</h1></section>
<section class="content">

<table class="table table-bordered table-striped">
<thead>
<tr><th>#</th><th>Title</th><th>Views</th><th>Action</th></tr>
</thead>
<tbody>
<?php foreach ($data as $i => $row): ?>
<tr>
    <td><?= $offset + $i + 1 ?></td>
    <td><?= htmlspecialchars(mb_strimwidth($row['title'], 0, 80, '...')) ?></td>
    <td><strong><?= number_format((int)$row['views']) ?></strong></td>
    <td><a href="<?= ADMIN_URL ?>/news/view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">View</a></td>
</tr>
<?php endforeach; ?>
<?php if (empty($data)): ?>
<tr><td colspan="4" style="text-align:center">No data found.</td></tr>
<?php endif; ?>
</tbody>
</table>

<div style="display:flex;gap:5px;margin-top:10px">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i === $page): ?>
            <span style="padding:5px 10px;background:#007bff;color:#fff;border-radius:4px"><?= $i ?></span>
        <?php else: ?>
            <a href="?page=<?= $i ?>"
               style="padding:5px 10px;border:1px solid #ddd;border-radius:4px;text-decoration:none"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
