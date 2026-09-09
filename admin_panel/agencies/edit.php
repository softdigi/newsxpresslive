<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id AND role = 'agency'");
$stmt->execute([':id' => $id]);
$agency = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agency) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $status   = in_array($_POST['status'] ?? '', ['active', 'pending', 'blocked', 'approved', 'rejected']) ? $_POST['status'] : $agency['status'];
    $password = $_POST['password'] ?? '';

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM admin_users WHERE email = :email AND id != :id");
        $check->execute([':email' => $email, ':id' => $id]);
        if ($check->fetch()) {
            $errors[] = 'Email already taken by another user.';
        }
    }

    if (empty($errors)) {
        if ($password !== '' && strlen($password) >= 8) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd = $pdo->prepare("UPDATE admin_users SET name = :name, email = :email, password = :password, status = :status WHERE id = :id AND role = 'agency'");
            $upd->execute([
                ':name' => $name, ':email' => $email, ':password' => $hashed,
                ':status' => $status, ':id' => $id,
            ]);
        } else {
            $upd = $pdo->prepare("UPDATE admin_users SET name = :name, email = :email, status = :status WHERE id = :id AND role = 'agency'");
            $upd->execute([
                ':name' => $name, ':email' => $email,
                ':status' => $status, ':id' => $id,
            ]);
        }
        $success = 'Agency updated successfully.';

        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id AND role = 'agency'");
        $stmt->execute([':id' => $id]);
        $agency = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Agency</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:600px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        label{display:block;margin-bottom:5px;font-weight:bold;font-size:14px;color:#555}
        input,select{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .hint{font-size:12px;color:#999;margin-top:-10px;margin-bottom:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>Edit Agency #<?php echo (int)$agency['id']; ?></h1>
    <a href="index.php" class="btn btn-secondary" style="margin-bottom:20px">← Back to List</a>

    <?php if (!empty($errors)): ?>
        <div class="alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <label>Agency Name</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($agency['name']); ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($agency['email']); ?>" required>

        <label>Password</label>
        <input type="password" name="password" minlength="8">
        <p class="hint">Leave blank to keep current password. Minimum 8 characters.</p>

        <label>Status</label>
        <select name="status">
            <option value="active" <?php echo $agency['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="pending" <?php echo $agency['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="approved" <?php echo $agency['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="rejected" <?php echo $agency['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            <option value="blocked" <?php echo $agency['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
        </select>

        <button type="submit" class="btn btn-primary">Update Agency</button>
    </form>
</div>
</body>
</html>
