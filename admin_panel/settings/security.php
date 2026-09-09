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
$login_attempts=(int)$_POST['login_attempts'];
$stmt=$pdo->prepare("REPLACE INTO settings (setting_key,setting_value) VALUES (?,?)");
$stmt->execute(['login_attempts',$login_attempts]);
header("Location: security.php");exit;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Security Settings</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
Max Login Attempts: <input type="number" name="login_attempts" value="<?= htmlspecialchars(get_setting('login_attempts',$pdo)) ?>"><br>
<button type="submit">Save</button>
</form>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
