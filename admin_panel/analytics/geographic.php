<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) exit('Access denied');

// Placeholder example (no geo table exists)
?>

<div class="content-wrapper">
<section class="content-header"><h1>Geographic</h1></section>
<section class="content">
<p>No geographic data table defined.</p>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
