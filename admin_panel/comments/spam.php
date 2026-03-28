<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

$stmt=$pdo->prepare("SELECT c.*, n.title FROM comments c LEFT JOIN news n ON c.news_id=n.id WHERE c.status='spam' ORDER BY c.created_at DESC");
$stmt->execute();
$data=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Spam Comments</h1></section>
<section class="content">
<table class="table table-bordered">
<tr><th>Author</th><th>Comment</th><th>News</th><th>Date</th></tr>
<?php foreach($data as $row): ?>
<tr>
<td><?= htmlspecialchars($row['author_name']) ?></td>
<td><?= htmlspecialchars($row['content']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
