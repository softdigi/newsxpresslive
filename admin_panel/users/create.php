<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

$agencies = [];
if ($_SESSION['admin']['role'] === 'super_admin') {
    $stmtA = $pdo->prepare("SELECT id, name FROM agencies WHERE status='active' ORDER BY name ASC");
    $stmtA->execute();
    $agencies = $stmtA->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $agency_id = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
    $status = $_POST['status'] ?? 'active';

    if ($name && $email && $password && $role) {

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            INSERT INTO admin_users (name,email,password,role,agency_id,status,created_at)
            VALUES (?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([$name,$email,$hash,$role,$agency_id,$status]);

        header("Location: index.php");
        exit;
    }
}

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Create User</h1>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

<div class="form-group">
    <label>Name</label>
    <input type="text" name="name" class="form-control" required>
</div>

<div class="form-group">
    <label>Email</label>
    <input type="email" name="email" class="form-control" required>
</div>

<div class="form-group">
    <label>Password</label>
    <input type="password" name="password" class="form-control" required>
</div>

<div class="form-group">
    <label>Role</label>
    <select name="role" class="form-control" required>
        <option value="">Select Role</option>
        <option value="super_admin">Super Admin</option>
        <option value="admin">Admin</option>
        <option value="editor">Editor</option>
        <option value="reporter">Reporter</option>
        <option value="agency">Agency</option>
    </select>
</div>

<?php if($_SESSION['admin']['role']==='super_admin'): ?>
<div class="form-group">
    <label>Agency (Optional)</label>
    <select name="agency_id" class="form-control">
        <option value="">None</option>
        <?php foreach($agencies as $a): ?>
        <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="form-group">
    <label>Status</label>
    <select name="status" class="form-control">
        <option value="active">Active</option>
        <option value="blocked">Blocked</option>
    </select>
</div>

<button type="submit" class="btn btn-success">Create</button>
<a href="index.php" class="btn btn-secondary">Back</a>

</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
