<?php
/**
 * admin_panel/settings/ads.php
 * Ad Code Manager — paste Google AdSense / any ad code for each slot.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

if (!function_exists('get_setting')) {
    function get_setting(string $key, PDO $pdo): string {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    }
}

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $slots = ['ad_header', 'ad_in_content', 'ad_sidebar'];
    $stmt  = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($slots as $slot) {
        // Limit ad code to 5000 chars; allow HTML/script tags (it IS ad code)
        $value = mb_substr(trim($_POST[$slot] ?? ''), 0, 5000, 'UTF-8');
        $stmt->execute([$slot, $value]);
    }
    $success = true;
}

$adHeader    = get_setting('ad_header',     $pdo);
$adInContent = get_setting('ad_in_content', $pdo);
$adSidebar   = get_setting('ad_sidebar',    $pdo);
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>📢 Ad Code Manager</h1>
    <small>Paste Google AdSense or any banner code for each slot. Leave blank to hide that slot.</small>
</section>
<section class="content">

<?php if ($success): ?>
    <div class="alert alert-success">✅ Ad codes saved successfully.</div>
<?php endif; ?>

<div class="card" style="max-width:800px">
<div class="card-body">

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <!-- Slot 1: Header Ad -->
    <div class="form-group">
        <label><strong>📌 Header Ad</strong> <small style="color:#888">(shown below navigation, above article)</small></label>
        <textarea name="ad_header" class="form-control" rows="5"
                  placeholder="Paste Google AdSense or any HTML/script ad code here..."
                  style="font-family:monospace;font-size:12px"><?= htmlspecialchars($adHeader, ENT_QUOTES, 'UTF-8') ?></textarea>
        <small class="form-text text-muted">Recommended size: 728×90 (Leaderboard) or 320×50 (Mobile Banner)</small>
    </div>

    <hr>

    <!-- Slot 2: In-Content Ad -->
    <div class="form-group">
        <label><strong>📖 In-Content Ad</strong> <small style="color:#888">(shown after first 3 paragraphs in article body)</small></label>
        <textarea name="ad_in_content" class="form-control" rows="5"
                  placeholder="Paste ad code here..."
                  style="font-family:monospace;font-size:12px"><?= htmlspecialchars($adInContent, ENT_QUOTES, 'UTF-8') ?></textarea>
        <small class="form-text text-muted">Recommended size: 336×280 (Large Rectangle) or 300×250 (Medium Rectangle)</small>
    </div>

    <hr>

    <!-- Slot 3: Sidebar Ad -->
    <div class="form-group">
        <label><strong>🗂️ Sidebar Ad</strong> <small style="color:#888">(shown in the right sidebar of article pages)</small></label>
        <textarea name="ad_sidebar" class="form-control" rows="5"
                  placeholder="Paste ad code here..."
                  style="font-family:monospace;font-size:12px"><?= htmlspecialchars($adSidebar, ENT_QUOTES, 'UTF-8') ?></textarea>
        <small class="form-text text-muted">Recommended size: 300×600 (Half Page) or 300×250 (Medium Rectangle)</small>
    </div>

    <div class="form-group" style="margin-top:1.5rem">
        <button type="submit" class="btn btn-primary">💾 Save Ad Codes</button>
        <a href="<?= SITE_URL ?? '../' ?>/" target="_blank" class="btn btn-default" style="margin-left:8px">
            👁 Preview Site
        </a>
    </div>
</form>

</div><!-- /.card-body -->
</div><!-- /.card -->

<div class="card" style="max-width:800px;margin-top:1.5rem">
<div class="card-header"><strong>💡 How to use Google AdSense</strong></div>
<div class="card-body" style="font-size:13px;line-height:1.7">
    <ol>
        <li>Go to <strong>Google AdSense</strong> → My Ads → New ad unit</li>
        <li>Choose type: Display (Responsive) or In-article</li>
        <li>Copy the full <code>&lt;script&gt;</code> code block</li>
        <li>Paste it in the appropriate slot above and click Save</li>
        <li>AdSense typically takes 24–48 hours to start showing ads</li>
    </ol>
    <p style="color:#888;margin-top:0.5rem">
        <strong>Note:</strong> Make sure your site is approved by AdSense before pasting live ad codes.
    </p>
</div>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
