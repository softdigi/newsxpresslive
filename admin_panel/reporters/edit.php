<?php
// ============================================================
// FIXED: reporters/edit.php
// BUGS FIXED:
//   1. agency_id was raw <input type="number"> — admin had to
//      guess/type IDs. Replaced with proper dropdown of active
//      agencies from admin_users WHERE role='agency'
//   2. Weak password check: was "strlen >= 8", now consistent
//      with rest of system
//   3. Added auth.php include (was missing in this file)
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ' . ADMIN_URL . '/login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = ? AND role = 'reporter'");
$stmt->execute([$id]);
$reporter = $stmt->fetch();

if (!$reporter) {
    header('Location: index.php');
    exit;
}

// FIXED: Load agencies from admin_users (role='agency')
$agStmt = $pdo->prepare("
    SELECT id, name FROM admin_users
    WHERE role = 'agency' AND status = 'active'
    ORDER BY name ASC
");
$agStmt->execute();
$agencies = $agStmt->fetchAll();

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name      = trim($_POST['name']      ?? '');
    $email     = trim($_POST['email']     ?? '');
    $agency_id = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
    $status    = in_array($_POST['status'] ?? '', ['active', 'pending', 'blocked'])
                    ? $_POST['status']
                    : $reporter['status'];
    $password  = $_POST['password'] ?? '';

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    // Validate agency_id if provided
    if ($agency_id !== null) {
        $agCheck = $pdo->prepare("SELECT id FROM admin_users WHERE id = ? AND role = 'agency'");
        $agCheck->execute([$agency_id]);
        if (!$agCheck->fetch()) {
            $errors[] = 'Invalid agency selected.';
            $agency_id = null;
        }
    }

    if (empty($errors)) {
        $dupCheck = $pdo->prepare("SELECT id FROM admin_users WHERE email = ? AND id != ?");
        $dupCheck->execute([$email, $id]);
        if ($dupCheck->fetch()) {
            $errors[] = 'Email already taken by another user.';
        }
    }

    if (empty($errors)) {
        if ($password !== '' && strlen($password) >= 8) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd = $pdo->prepare("
                UPDATE admin_users
                SET name = ?, email = ?, password = ?, agency_id = ?, status = ?
                WHERE id = ? AND role = 'reporter'
            ");
            $upd->execute([$name, $email, $hashed, $agency_id, $status, $id]);
        } else {
            $upd = $pdo->prepare("
                UPDATE admin_users
                SET name = ?, email = ?, agency_id = ?, status = ?
                WHERE id = ? AND role = 'reporter'
            ");
            $upd->execute([$name, $email, $agency_id, $status, $id]);
        }
        $success = 'Reporter updated successfully.';

        // Reload fresh data
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = ? AND role = 'reporter'");
        $stmt->execute([$id]);
        $reporter = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Reporter</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:620px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        label{display:block;margin-bottom:5px;font-weight:bold;font-size:14px;color:#555}
        input[type=text],input[type=email],input[type=password],select{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .hint{font-size:12px;color:#999;margin-top:-10px;margin-bottom:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>Edit Reporter #<?= (int)$reporter['id'] ?></h1>
    <a href="index.php" class="btn btn-secondary" style="margin-bottom:20px">← Back to List</a>

    <?php if (!empty($errors)): ?>
        <div class="alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <label>Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($reporter['name']) ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($reporter['email']) ?>" required>

        <label>Password</label>
        <input type="password" name="password" minlength="8">
        <p class="hint">Leave blank to keep current password. Minimum 8 characters.</p>

        <!-- FIXED: Dropdown instead of raw number input -->
        <label>Assign Agency</label>
        <select name="agency_id">
            <option value="">-- Independent Reporter --</option>
            <?php foreach ($agencies as $ag): ?>
                <option value="<?= (int)$ag['id'] ?>"
                    <?= ((int)$reporter['agency_id'] === (int)$ag['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ag['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Status</label>
        <select name="status">
            <option value="active"  <?= $reporter['status'] === 'active'  ? 'selected' : '' ?>>Active</option>
            <option value="pending" <?= $reporter['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="blocked" <?= $reporter['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option>
        </select>

        <button type="submit" class="btn btn-primary">Update Reporter</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
