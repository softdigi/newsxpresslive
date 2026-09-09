<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $ids = $_POST['ids'] ?? [];
    $action = $_POST['bulk_action'] ?? '';

    // Sanitize IDs
    $ids = array_map('intval', array_filter($ids, 'is_numeric'));
    $ids = array_filter($ids, function ($v) { return $v > 0; });

    if (empty($ids)) {
        $error = 'No reporters selected.';
    } elseif (!in_array($action, ['block', 'delete'])) {
        $error = 'Invalid action selected.';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'block') {
            $stmt = $pdo->prepare("UPDATE admin_users SET status = 'blocked' WHERE id IN ({$placeholders}) AND role = 'reporter'");
            $stmt->execute(array_values($ids));
            $affected = $stmt->rowCount();
            $success = "{$affected} reporter(s) blocked successfully.";
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id IN ({$placeholders}) AND role = 'reporter'");
            $stmt->execute(array_values($ids));
            $affected = $stmt->rowCount();
            $success = "{$affected} reporter(s) deleted successfully.";
        }
    }
}

// If came via POST from index, show result then redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($success || $error)) {
    // Show result page
} else {
    // Direct GET access — show bulk action form
}

// Fetch reporters for standalone form (GET request)
$reporters = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, name, email, status FROM admin_users WHERE role = 'reporter' ORDER BY name ASC");
    $stmt->execute();
    $reporters = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk Actions</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:800px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-warning{background:#ffc107;color:#333}.btn-danger{background:#dc3545}
        .alert-success{background:#d4edda;color:#155724;padding:12px;border-radius:4px;margin-bottom:15px}
        .alert-danger{background:#f8d7da;color:#721c24;padding:12px;border-radius:4px;margin-bottom:15px}
        .action-bar{display:flex;gap:10px;align-items:center;margin:15px 0}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
    </style>
</head>
<body>
<div class="container">
    <h1>Bulk Actions</h1>
    <a href="index.php" class="btn btn-secondary">← Back to List</a><br><br>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
        <a href="index.php" class="btn btn-primary">Return to List</a>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'GET'): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="action-bar">
            <select name="bulk_action" required>
                <option value="">Select Action...</option>
                <option value="block">Block Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to apply this action?')">Apply Action</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all"></th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reporters as $r): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>"></td>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><?php echo htmlspecialchars($r['email']); ?></td>
                    <td><span class="badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <script>
    document.getElementById('check-all')?.addEventListener('change', function(){
        document.querySelectorAll('input[name="ids[]"]').forEach(c => c.checked = this.checked);
    });
    </script>
    <?php endif; ?>
</div>
</body>
</html>
