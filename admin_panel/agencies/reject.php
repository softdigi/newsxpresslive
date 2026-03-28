<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, status FROM admin_users WHERE id = :id AND role = 'agency'");
$stmt->execute([':id' => $id]);
$agency = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agency) {
    header('Location: index.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $reason = trim($_POST['reason'] ?? '');

    if ($agency['status'] === 'rejected') {
        $error = 'This agency is already rejected.';
    } else {
        $upd = $pdo->prepare("UPDATE admin_users SET status = 'rejected' WHERE id = :id AND role = 'agency'");
        $upd->execute([':id' => $id]);
        $success = 'Agency has been rejected.';
        $agency['status'] = 'rejected';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reject Agency</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:500px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:#dc3545;margin-bottom:20px}
        .info{background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-danger{background:#dc3545}.btn-secondary{background:#6c757d}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
        label{display:block;margin-bottom:5px;font-weight:bold;font-size:14px;color:#555}
        textarea{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px;min-height:80px}
    </style>
</head>
<body>
<div class="container">
    <h1>Reject Agency</h1>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
        <a href="index.php" class="btn btn-secondary">← Back to List</a>
    <?php elseif ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <a href="index.php" class="btn btn-secondary">← Back to List</a>
    <?php else: ?>
        <div class="info">
            <strong>Name:</strong> <?php echo htmlspecialchars($agency['name']); ?><br>
            <strong>Email:</strong> <?php echo htmlspecialchars($agency['email']); ?><br>
            <strong>Current Status:</strong> <?php echo htmlspecialchars(ucfirst($agency['status'])); ?>
        </div>

        <p style="color:#dc3545">Rejecting this agency will revoke their access to the panel.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$agency['id']; ?>">

            <label>Rejection Reason (optional)</label>
            <textarea name="reason" placeholder="Enter reason for rejection..."></textarea>

            <button type="submit" class="btn btn-danger">Confirm Reject</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
