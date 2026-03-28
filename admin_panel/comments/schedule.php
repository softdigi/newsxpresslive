<?php
// notifications/schedule.php — FIXED
// No role check, no CSRF, topic field could inject malicious FCM topics
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

$scheduled = !empty($_GET['scheduled']);
$error     = $_GET['error'] ?? '';
?>

<div class="content-wrapper">
<section class="content-header"><h1>Schedule Push Notification</h1></section>
<section class="content">

<?php if ($scheduled): ?>
    <div class="alert alert-success">Notification scheduled successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= match($error) {
            'missing'      => 'Title and message are required.',
            'invalid_topic'=> 'Invalid topic. Use: global, country_1, state_5, etc.',
            'invalid_date' => 'Scheduled date must be in the future.',
            default        => 'An error occurred.'
        } ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:600px">
<div class="card-body">
<form method="POST" action="<?= ADMIN_URL ?>/actions/schedule_notification.php">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label>Title <span style="color:red">*</span></label>
        <input type="text" name="title" class="form-control" required maxlength="200">
    </div>

    <div class="form-group">
        <label>Message <span style="color:red">*</span></label>
        <textarea name="message" class="form-control" rows="4" required maxlength="1000"></textarea>
    </div>

    <div class="form-group">
        <label>Topic <span style="color:red">*</span></label>
        <input type="text" name="topic" class="form-control" required maxlength="100"
               placeholder="global / country_1 / state_5 / district_12"
               pattern="[a-zA-Z0-9_\-]+"
               title="Only letters, numbers, underscores and hyphens allowed">
        <small class="form-text text-muted">Examples: global, country_1, state_5, district_12</small>
    </div>

    <div class="form-group">
        <label>Schedule Date & Time <span style="color:red">*</span></label>
        <input type="datetime-local" name="scheduled_at" class="form-control" required
               min="<?= date('Y-m-d\TH:i') ?>">
    </div>

    <button type="submit" class="btn btn-success">Schedule Notification</button>
    <a href="breaking.php" class="btn btn-secondary">Cancel</a>
</form>
</div>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
