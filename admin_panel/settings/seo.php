<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

function get_setting($key,$pdo){
$stmt=$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
$stmt->execute([$key]);
return $stmt->fetchColumn();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
verify_csrf($_POST['csrf_token'] ?? '');
$meta_title=trim($_POST['meta_title']);
$meta_desc=trim($_POST['meta_description']);
$stmt=$pdo->prepare("REPLACE INTO settings (setting_key,setting_value) VALUES (?,?)");
$stmt->execute(['meta_title',$meta_title]);
$stmt->execute(['meta_description',$meta_desc]);
header("Location: seo.php");exit;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>SEO Settings</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
Meta Title: <input type="text" name="meta_title" value="<?= htmlspecialchars(get_setting('meta_title',$pdo)) ?>"><br>
Meta Description:<br>
<textarea name="meta_description"><?= htmlspecialchars(get_setting('meta_description',$pdo)) ?></textarea><br>
<button type="submit">Save</button>
</form>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
