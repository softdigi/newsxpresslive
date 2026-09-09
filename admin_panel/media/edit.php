<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

$id = (int)($_GET['id'] ?? 0);

$stmt=$pdo->prepare("SELECT * FROM media WHERE id=?");
$stmt->execute([$id]);
$file=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$file) exit('Not found');

if($_SERVER['REQUEST_METHOD']==='POST'){
verify_csrf();
$alt=trim($_POST['alt_text']);
$stmtU=$pdo->prepare("UPDATE media SET alt_text=? WHERE id=?");
$stmtU->execute([$alt,$id]);
header("Location: index.php");
exit;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Edit Media</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
<img src="/uploads/<?= htmlspecialchars($file['file_name']) ?>" width="200"><br>
Alt Text: <input type="text" name="alt_text" value="<?= htmlspecialchars($file['alt_text'] ?? '') ?>">
<button type="submit">Save</button>
</form>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
