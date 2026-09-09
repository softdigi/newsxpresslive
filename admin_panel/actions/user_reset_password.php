<?php
// actions/user_reset_password.php — FIXED
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

verify_csrf();

$userId = (int)($_POST['id'] ?? 0);
if ($userId <= 0 || $userId === adminId()) {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}

// Only super_admin can reset super_admin passwords
$check = $pdo->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
$check->execute([$userId]);
$target = $check->fetch();
if (!$target) {
    header('Location: ' . ADMIN_URL . '/users/index.php');
    exit;
}
if ($target['role'] === 'super_admin' && adminRole() !== 'super_admin') {
    http_response_code(403);
    exit('Permission denied');
}

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#';
$tempPassword = substr(str_shuffle($chars), 0, 12);
$hashed = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?")
    ->execute([$hashed, $userId]);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Password Reset</title>
<style>
body{font-family:Arial;background:#f5f5f5;padding:40px}
.box{max-width:420px;margin:auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.code{font-size:18px;font-weight:bold;background:#eee;padding:10px;text-align:center;border-radius:4px;letter-spacing:2px}
.warn{color:#b00;font-size:13px;margin-top:12px}
</style>
</head>
<body>
<div class="box">
    <h3>✅ Password Reset Successful</h3>
    <p><strong>Temporary Password:</strong></p>
    <div class="code"><?= htmlspecialchars($tempPassword) ?></div>
    <p class="warn">⚠️ Share securely. This page will not show it again.</p>
    <br><a href="<?= ADMIN_URL ?>/users/index.php">← Back to Users</a>
</div>
</body>
</html>
