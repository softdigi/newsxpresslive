<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin'])) exit('Access denied');

$roles=['super_admin','admin','editor','reporter','agency'];
?>

<div class="content-wrapper">
<section class="content-header"><h1>Roles</h1></section>
<section class="content">
<ul>
<?php foreach($roles as $role): ?>
<li><?= htmlspecialchars($role) ?></li>
<?php endforeach; ?>
</ul>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
