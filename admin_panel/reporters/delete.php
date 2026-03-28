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

$stmt = $pdo->prepare("SELECT id, name, email FROM admin_users WHERE id = :id AND role = 'reporter'");
$stmt->execute([':id' => $id]);
$reporter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporter) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['confirm'] ?? '') === 'yes') {
        $del = $pdo->prepare("DELETE FROM admin_users WHERE id = :id AND role = 'reporter'");
        $del->execute([':id' => $id]);
        header('Location: index.php?deleted=1');
        exit;
    } else {
        $error = 'Please confirm deletion.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Reporter</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:500px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:#dc3545;margin-bottom:20px}
        .warning{background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:6px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-danger{background:#dc3545}.btn-secondary{background:#6c757d}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>Delete Reporter</h1>

    <?php if ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="warning">
        <strong>Warning:</strong> You are about to permanently delete this reporter.<br><br>
        <strong>Name:</strong> <?php echo htmlspecialchars($reporter['name']); ?><br>
        <strong>Email:</strong> <?php echo htmlspecialchars($reporter['email']); ?><br>
        <strong>ID:</strong> <?php echo (int)$reporter['id']; ?>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$reporter['id']; ?>">
        <input type="hidden" name="confirm" value="yes">
        <button type="submit" class="btn btn-danger">Confirm Delete</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
