<?php
// actions/user_create.php — FIXED
// Issues: no CSRF, no email validation, str_shuffle weak password
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/users/create.php');
    exit;
}

verify_csrf();

$name      = trim($_POST['name']      ?? '');
$email     = trim($_POST['email']     ?? '');
$role      = trim($_POST['role']      ?? '');
$agency_id = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit('Name and valid email required');
}

$allowedRoles = ['reporter', 'agency', 'editor', 'admin'];
// Only super_admin can create admin accounts
if ($role === 'admin' && adminRole() !== 'super_admin') {
    http_response_code(403);
    exit('Only super admin can create admin accounts');
}
if (!in_array($role, $allowedRoles, true)) {
    exit('Invalid role');
}

// Check duplicate email
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    exit('Email already exists');
}

if ($role !== 'reporter') {
    $agency_id = null;
}

// FIXED: cryptographically secure random password
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
$plainPassword = '';
for ($i = 0; $i < 12; $i++) {
    $plainPassword .= $chars[random_int(0, strlen($chars) - 1)];
}
$hashed = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare("
    INSERT INTO admin_users (name, email, password, role, agency_id, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'active', NOW())
");
$stmt->execute([$name, $email, $hashed, $role, $agency_id]);
$userId = (int)$pdo->lastInsertId();

if ($role === 'reporter') {
    try {
        $pdo->prepare("INSERT INTO reporter_profiles (user_id, is_verified) VALUES (?, 0)")
            ->execute([$userId]);
    } catch (PDOException $e) {
        error_log('reporter_profiles insert failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>User Created</title>
<style>
body{font-family:Arial;background:#f7f7f7;padding:40px}
.box{max-width:420px;margin:auto;background:#fff;border:1px solid #ddd;padding:24px;border-radius:8px}
.pass{background:#f1f1f1;padding:10px;font-weight:bold;border-radius:4px;letter-spacing:1px}
.warn{color:#b00;font-size:13px}
</style>
</head>
<body>
<div class="box">
    <h3>✅ User Created</h3>
    <p><strong>Name:</strong> <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Role:</strong> <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Password:</strong></p>
    <div class="pass"><?= htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') ?></div>
    <p class="warn">⚠️ Copy & share securely. Not shown again.</p>
    <br><a href="<?= ADMIN_URL ?>/users/index.php">← Back to Users</a>
</div>
</body>
</html>
