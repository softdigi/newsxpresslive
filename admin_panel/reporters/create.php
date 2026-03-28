<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['admin','super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$errors = [];
$success = '';

// Fetch agencies for dropdown
$agencyStmt = $pdo->query("SELECT id,name FROM admin_users WHERE role='agency' AND status='active' ORDER BY name ASC");
$agencies = $agencyStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $agency_id = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
    $status    = in_array($_POST['status'] ?? '', ['active','pending','blocked']) 
                    ? $_POST['status'] 
                    : 'pending';

    if ($name === '') $errors[] = "Name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required.";
    if (strlen($password) < 8) $errors[] = "Password must be minimum 8 characters.";

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM admin_users WHERE email=?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = "Email already exists.";
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);

        $stmt = $pdo->prepare("
            INSERT INTO admin_users
            (name,email,password,role,agency_id,status,created_at)
            VALUES (?,?,?,?,?,?,NOW())
        ");

        $stmt->execute([
            $name,
            $email,
            $hashed,
            'reporter',
            $agency_id,
            $status
        ]);

        $success = "Reporter created successfully.";
    }
}
?>

<div class="page-header">
    <h2>Create Reporter</h2>
    <a href="index.php" class="btn btn-secondary">← Back to Reporters</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('htmlspecialchars',$errors)) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" class="form-control"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password"
                       class="form-control" minlength="8" required>
                <small class="form-hint">Minimum 8 characters</small>
            </div>

            <div class="form-group">
                <label>Assign Agency (Optional)</label>
                <select name="agency_id" class="form-control">
                    <option value="">-- Independent Reporter --</option>
                    <?php foreach ($agencies as $agency): ?>
                        <option value="<?= $agency['id'] ?>"
                            <?= (($_POST['agency_id'] ?? '') == $agency['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($agency['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="pending" <?= (($_POST['status'] ?? '')==='pending')?'selected':'' ?>>Pending</option>
                    <option value="active" <?= (($_POST['status'] ?? '')==='active')?'selected':'' ?>>Active</option>
                    <option value="blocked" <?= (($_POST['status'] ?? '')==='blocked')?'selected':'' ?>>Blocked</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    Create Reporter
                </button>
                <a href="index.php" class="btn btn-outline">
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
