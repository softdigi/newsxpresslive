<?php
// ============================================================
// FIXED: agencies/create.php
// CRITICAL BUG:
//   CSRF was COMMENTED OUT — unauthenticated POST could create
//   a new agency account. Also removed display_errors.
// ALSO FIXED:
//   2. display_errors ON removed
//   3. includes/sidebar.php was missing
//   4. Role check: only super_admin/admin
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    exit('Access denied');
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FIXED: CSRF verification (was commented out!)
    verify_csrf();

    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $status   = in_array($_POST['status'] ?? '', ['active', 'blocked'])
                    ? $_POST['status']
                    : 'active';

    if ($name === '') {
        $errors[] = 'Agency name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM admin_users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = 'Email already exists.';
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare("
            INSERT INTO admin_users
                (name, email, password, role, agency_id, status, created_at)
            VALUES
                (?, ?, ?, 'agency', NULL, ?, NOW())
        ");
        $stmt->execute([$name, $email, $hashed, $status]);

        $success = 'Agency created successfully.';
    }
}
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Create Agency</h1>
    <a href="index.php" class="btn btn-secondary btn-sm">← Back</a>
</section>
<section class="content">
<div class="card">
<div class="card-body" style="max-width:600px">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label>Agency Name <span style="color:red">*</span></label>
        <input type="text" name="name" class="form-control" required
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
    </div>

    <div class="form-group">
        <label>Email <span style="color:red">*</span></label>
        <input type="email" name="email" class="form-control" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>

    <div class="form-group">
        <label>Password <span style="color:red">*</span></label>
        <input type="password" name="password" class="form-control" required minlength="8">
        <small class="form-text text-muted">Minimum 8 characters</small>
    </div>

    <div class="form-group">
        <label>Status</label>
        <select name="status" class="form-control">
            <option value="active"   <?= (($_POST['status'] ?? '') === 'active')  ? 'selected' : '' ?>>Active</option>
            <option value="blocked"  <?= (($_POST['status'] ?? '') === 'blocked') ? 'selected' : '' ?>>Blocked</option>
        </select>
    </div>

    <button type="submit" class="btn btn-primary">Create Agency</button>
    <a href="index.php" class="btn btn-secondary">Cancel</a>
</form>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
