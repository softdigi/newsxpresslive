<?php
// notifications/breaking.php — FIXED
// No role check, config loaded after auth (wrong order)
// Form had no CSRF token
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

$countries = $pdo->query(
    "SELECT id, name FROM countries ORDER BY name ASC"
)->fetchAll();

$sent  = !empty($_GET['sent']);
$error = !empty($_GET['error']);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Send Push Notification</h1></section>
<section class="content">

<?php if ($sent): ?>
    <div class="alert alert-success">Notification sent successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger">Missing required fields.</div>
<?php endif; ?>

<div class="card" style="max-width:600px">
<div class="card-body">
<form method="POST" action="<?= ADMIN_URL ?>/actions/send_notification.php">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label>Notification Title <span style="color:red">*</span></label>
        <input type="text" name="title" class="form-control" required maxlength="200">
    </div>

    <div class="form-group">
        <label>Message <span style="color:red">*</span></label>
        <textarea name="message" class="form-control" rows="4" required maxlength="1000"></textarea>
    </div>

    <div class="form-group">
        <label>Target Level</label>
        <select name="target_type" id="target_type" class="form-control">
            <option value="global">Global (All Users)</option>
            <option value="country">Country</option>
            <option value="state">State</option>
            <option value="district">District</option>
        </select>
    </div>

    <div id="country_box" style="display:none">
        <div class="form-group">
            <label>Country</label>
            <select name="country_id" class="form-control">
                <option value="">Select Country</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <button type="submit" class="btn btn-danger">Send Notification</button>
</form>
</div>
</div>

</section>
</div>

<script>
document.getElementById('target_type').addEventListener('change', function () {
    document.getElementById('country_box').style.display =
        (this.value !== 'global') ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
