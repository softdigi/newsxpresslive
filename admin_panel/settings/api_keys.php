<?php
// settings/api_keys.php — FIXED
// get_setting() defined in multiple settings files → fatal error if both included
// FIXED: function_exists() guard + length cap on API key value
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin']);

// FIXED: function_exists guard — prevents fatal error if included multiple times
if (!function_exists('get_setting')) {
    function get_setting(string $key, PDO $pdo): string {
        $stmt = $pdo->prepare(
            "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1"
        );
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    }
}

$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // FIXED: length cap — API keys shouldn't be > 500 chars
    $google_key = mb_substr(trim($_POST['google_api'] ?? ''), 0, 500, 'UTF-8');
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value)
         VALUES ('google_api', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$google_key]);
    $success = true;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>API Keys</h1></section>
<section class="content">
<div class="card" style="max-width:600px">
<div class="card-body">

<?php if ($success): ?>
    <div class="alert alert-success">Settings saved.</div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group">
        <label>Google API Key</label>
        <input type="text" name="google_api" class="form-control"
               value="<?= htmlspecialchars(get_setting('google_api', $pdo)) ?>"
               maxlength="500">
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
