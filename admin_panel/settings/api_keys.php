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
    $keys = [
        'google_api'     => mb_substr(trim($_POST['google_api']     ?? ''), 0, 500, 'UTF-8'),
        'openai_api_key' => mb_substr(trim($_POST['openai_api_key'] ?? ''), 0, 500, 'UTF-8'),
        'gemini_api_key' => mb_substr(trim($_POST['gemini_api_key'] ?? ''), 0, 500, 'UTF-8'),
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($keys as $k => $v) {
        $stmt->execute([$k, $v]);
    }
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
    <hr>
    <h5 style="margin-bottom:12px">🤖 AI Summarizer Keys <small style="font-size:12px; color:#888">(for AI Digest feature)</small></h5>
    <p style="font-size:12px; color:#888; margin-bottom:10px">
        Set either OpenAI <em>or</em> Gemini key. OpenAI takes priority if both are set.
        You can also set <code>OPENAI_API_KEY</code> / <code>GEMINI_API_KEY</code> as server environment variables instead.
    </p>
    <div class="form-group">
        <label>OpenAI API Key <small>(GPT-3.5-turbo)</small></label>
        <input type="password" name="openai_api_key" class="form-control"
               value="<?= htmlspecialchars(get_setting('openai_api_key', $pdo)) ?>"
               maxlength="500" autocomplete="off"
               placeholder="sk-...">
        <small class="form-text text-muted">Get from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a></small>
    </div>
    <div class="form-group">
        <label>Google Gemini API Key <small>(Gemini 1.5 Flash — free tier available)</small></label>
        <input type="password" name="gemini_api_key" class="form-control"
               value="<?= htmlspecialchars(get_setting('gemini_api_key', $pdo)) ?>"
               maxlength="500" autocomplete="off"
               placeholder="AIza...">
        <small class="form-text text-muted">Get from <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com/app/apikey</a></small>
    </div>
    <button type="submit" class="btn btn-primary">Save All Keys</button>
</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
