<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) {
    exit('Access denied');
}

$page = max(1,(int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page-1)*$limit;

$search = trim($_GET['q'] ?? '');
$where = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (c.content LIKE ? OR c.author_name LIKE ?) ";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT SQL_CALC_FOUND_ROWS c.*, n.title
    FROM comments c
    LEFT JOIN news n ON c.news_id = n.id
    $where
    ORDER BY c.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
$total_pages = ceil($total/$limit);
?>

<div class="content-wrapper">
<section class="content-header"><h1>All Comments</h1></section>
<section class="content">

<form method="GET">
<input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search comments">
<button type="submit">Search</button>
</form>

<form method="POST" action="bulk_actions.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

<table class="table table-bordered">
<tr>
<th><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(el=>el.checked=this.checked)"></th>
<th>Author</th>
<th>Comment</th>
<th>News</th>
<th>Status</th>
<th>Date</th>
</tr>
<?php foreach($data as $row): ?>
<tr>
<td><input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" class="chk"></td>
<td><?= htmlspecialchars($row['author_name']) ?></td>
<td><?= htmlspecialchars($row['content']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= htmlspecialchars($row['status']) ?></td>
<td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>

<select name="action">
<option value="approve">Approve</option>
<option value="spam">Mark Spam</option>
<option value="delete">Delete</option>
</select>
<button type="submit">Apply</button>
</form>

<?php for($i=1;$i<=$total_pages;$i++): ?>
<a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
<?php endfor; ?>

</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
