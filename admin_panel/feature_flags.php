<?php
// ============================================================
// FIXED: feature_flags.php
// BUGS FIXED (confirmed in error_log):
//   FATAL: Failed to open stream: ../config/database.php
//   — File was using wrong relative path from wrong directory
//   — Fixed to use __DIR__ + correct includes/config.php path
// ALSO FIXED:
//   2. No auth check at all — anyone could access this page
//   3. No session check
//   4. Toggle button (onclick="toggleFlag()") called a JS
//      function that didn't exist — was making unauthenticated
//      GET API calls. Replaced with POST forms + CSRF.
//   5. $pdo->query() with no error handling — wrapped.
// NOTE: 'feature_flags' table may not exist in your DB yet.
//       The query is wrapped in try/catch to prevent crash.
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Only super_admin can manage feature flags
if ($_SESSION['admin']['role'] !== 'super_admin') {
    http_response_code(403);
    exit('Access denied. Super Admin only.');
}

// Handle toggle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $flag_id  = (int)($_POST['flag_id'] ?? 0);
    $new_val  = (int)($_POST['new_value'] ?? 0); // 0 or 1

    if ($flag_id > 0 && in_array($new_val, [0, 1], true)) {
        try {
            $upd = $pdo->prepare("UPDATE feature_flags SET enabled = ? WHERE id = ?");
            $upd->execute([$new_val, $flag_id]);
        } catch (PDOException $e) {
            error_log('feature_flags toggle error: ' . $e->getMessage());
        }
    }

    header('Location: feature_flags.php');
    exit;
}

// Fetch all flags
$flags = [];
try {
    $stmt  = $pdo->query("SELECT * FROM feature_flags ORDER BY created_at DESC");
    $flags = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
    error_log('feature_flags fetch error: ' . $e->getMessage());
    $flags = [];
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>⚙️ Feature Flags Management</h1>
    <small style="color:#888">Super Admin only — changes take effect immediately</small>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<?php if (empty($flags)): ?>
    <div class="alert alert-info">
        No feature flags found. The <code>feature_flags</code> table may not exist yet,
        or no flags have been created.
    </div>
<?php else: ?>

<table class="table table-bordered table-striped">
<thead>
<tr>
    <th>Flag Name</th>
    <th>Key</th>
    <th>Platform</th>
    <th>Rollout %</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($flags as $flag): ?>
<tr>
    <td><strong><?= htmlspecialchars($flag['name']) ?></strong></td>
    <td><code style="font-size:12px;color:#666"><?= htmlspecialchars($flag['flag_key'] ?? '') ?></code></td>
    <td><?= htmlspecialchars(strtoupper($flag['platform'] ?? '')) ?></td>
    <td>
        <div style="background:#e9ecef;border-radius:10px;height:8px;overflow:hidden;width:100px;display:inline-block;vertical-align:middle">
            <div style="width:<?= (int)($flag['rollout_percentage'] ?? 0) ?>%;height:100%;background:#007bff;border-radius:10px"></div>
        </div>
        <span style="font-size:13px;margin-left:6px"><?= (int)($flag['rollout_percentage'] ?? 0) ?>%</span>
    </td>
    <td>
        <?php if ($flag['enabled']): ?>
            <span class="badge badge-success">Enabled</span>
        <?php else: ?>
            <span class="badge badge-secondary">Disabled</span>
        <?php endif; ?>
    </td>
    <td>
        <!-- FIXED: POST form with CSRF instead of unauthenticated JS call -->
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="flag_id"    value="<?= (int)$flag['id'] ?>">
            <input type="hidden" name="new_value"  value="<?= $flag['enabled'] ? '0' : '1' ?>">
            <button type="submit"
                    class="btn btn-sm <?= $flag['enabled'] ? 'btn-danger' : 'btn-success' ?>"
                    onclick="return confirm('<?= $flag['enabled'] ? 'Disable' : 'Enable' ?> this flag?')">
                <?= $flag['enabled'] ? 'Disable' : 'Enable' ?>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
