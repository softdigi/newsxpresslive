<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin'])) exit('Access denied');
?>

<div class="content-wrapper">
<section class="content-header"><h1>Permissions</h1></section>
<section class="content">
<p>Role-based access controlled via permissions.php include.</p>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
