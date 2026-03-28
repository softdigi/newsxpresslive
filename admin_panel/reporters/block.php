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

$stmt = $pdo->prepare("SELECT id, name, email, status FROM admin_users WHERE id = :id AND role = 'reporter'");
$stmt->execute([':id' => $id]);
$reporter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporter) {
    header('Location: index.php');
    exit;
}

$is_blocked = ($reporter['status'] === 'blocked');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $new_status = $is_blocked ? 'active' : 'blocked';
    $upd = $pdo->prepare("UPDATE admin_users SET status = :status WHERE id = :id AND role = 'reporter'");
    $upd->execute([':status' => $new_status, ':id' => $id]);

    header('Location: index.php?status_changed=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $is_blocked ? 'Unblock' : 'Block'; ?> Reporter</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:500px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:<?php echo $is_blocked ? '#28a745' : '#dc3545'; ?>;margin-bottom:20px}
        .info{background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-danger{background:#dc3545}.btn-success{background:#28a745}.btn-secondary{background:#6c757d}
    </style>
</head>
<body>
<div class="container">
    <h1><?php echo $is_blocked ? 'Unblock' : 'Block'; ?> Reporter</h1>

    <div class="info">
        <strong>Name:</strong> <?php echo htmlspecialchars($reporter['name']); ?><br>
        <strong>Email:</strong> <?php echo htmlspecialchars($reporter['email']); ?><br>
        <strong>Current Status:</strong> <?php echo htmlspecialchars(ucfirst($reporter['status'])); ?>
    </div>

    <p><?php echo $is_blocked ? 'This reporter is currently blocked. Unblocking will restore their access.' : 'Blocking this reporter will disable their access immediately.'; ?></p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$reporter['id']; ?>">
        <?php if ($is_blocked): ?>
            <button type="submit" class="btn btn-success">Confirm Unblock</button>
        <?php else: ?>
            <button type="submit" class="btn btn-danger">Confirm Block</button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
