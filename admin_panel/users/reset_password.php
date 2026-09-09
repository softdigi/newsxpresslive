<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, name, role FROM admin_users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

if ($user['role'] === 'super_admin' && $_SESSION['admin']['role'] !== 'super_admin') {
    exit('Permission denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        exit('Password must be at least 6 characters');
    }

    if ($password !== $confirm) {
        exit('Passwords do not match');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $update = $pdo->prepare("UPDATE admin_users SET password=? WHERE id=?");
    $update->execute([$hash, $id]);

    header("Location: view.php?id=".$id);
    exit;
}
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Reset Password</h1>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

<div class="form-group">
    <label>New Password</label>
    <input type="password" name="password" class="form-control" required>
</div>

<div class="form-group">
    <label>Confirm Password</label>
    <input type="password" name="confirm_password" class="form-control" required>
</div>

<button type="submit" class="btn btn-danger">Reset Password</button>
<a href="view.php?id=<?= (int)$id ?>" class="btn btn-secondary">Cancel</a>

</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
