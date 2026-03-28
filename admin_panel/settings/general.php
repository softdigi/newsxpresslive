<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

function get_setting($key,$pdo){
    $stmt=$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['csrf_token'] ?? '');

    $site_name=trim($_POST['site_name']);
    $site_email=filter_var($_POST['site_email'],FILTER_VALIDATE_EMAIL);

    if(!$site_name || !$site_email) exit('Invalid data');

    $stmt=$pdo->prepare("REPLACE INTO settings (setting_key,setting_value) VALUES (?,?)");
    $stmt->execute(['site_name',$site_name]);
    $stmt->execute(['site_email',$site_email]);

    header("Location: general.php");
    exit;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>General Settings</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
Site Name: <input type="text" name="site_name" value="<?= htmlspecialchars(get_setting('site_name',$pdo)) ?>"><br>
Site Email: <input type="email" name="site_email" value="<?= htmlspecialchars(get_setting('site_email',$pdo)) ?>"><br>
<button type="submit">Save</button>
</form>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
