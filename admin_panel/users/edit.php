<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $agency_id = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;

    if (!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE admin_users 
            SET name=?, email=?, password=?, role=?, agency_id=?, status=?
            WHERE id=?
        ");
        $stmt->execute([$name,$email,$hash,$role,$agency_id,$status,$id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE admin_users 
            SET name=?, email=?, role=?, agency_id=?, status=?
            WHERE id=?
        ");
        $stmt->execute([$name,$email,$role,$agency_id,$status,$id]);
    }

    header("Location: index.php");
    exit;
}

$stmtA = $pdo->prepare("SELECT id,name FROM agencies WHERE status='active' ORDER BY name ASC");
$stmtA->execute();
$agencies = $stmtA->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Edit User</h1>
</section>

<section class="content">
<div class="card">
<div class="card-body">

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

<div class="form-group">
    <label>Name</label>
    <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" class="form-control" required>
</div>

<div class="form-group">
    <label>Email</label>
    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="form-control" required>
</div>

<div class="form-group">
    <label>Password (Leave blank to keep)</label>
    <input type="password" name="password" class="form-control">
</div>

<div class="form-group">
    <label>Role</label>
    <select name="role" class="form-control" required>
        <?php foreach(['super_admin','admin','editor','reporter','agency'] as $r): ?>
        <option value="<?= $r ?>" <?= $user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label>Agency</label>
    <select name="agency_id" class="form-control">
        <option value="">None</option>
        <?php foreach($agencies as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $user['agency_id']==$a['id']?'selected':'' ?>>
            <?= htmlspecialchars($a['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label>Status</label>
    <select name="status" class="form-control">
        <option value="active" <?= $user['status']==='active'?'selected':'' ?>>Active</option>
        <option value="blocked" <?= $user['status']==='blocked'?'selected':'' ?>>Blocked</option>
    </select>
</div>

<button type="submit" class="btn btn-primary">Update</button>
<a href="index.php" class="btn btn-secondary">Back</a>

</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
