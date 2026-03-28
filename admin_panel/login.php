<?php
// SECURITY: Disable debug output in production
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ============================================================
// admin_panel/login.php — UPDATED
// Changes:
//   1. setAdminSession() se session set hota hai
//      (session_regenerate_id + IP binding automatic)
//   2. Rate limiting: 5 attempts per 15 min per IP
//   3. agency_id session mein store hota hai
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../../auth/rate_limit.php';

// Already logged in redirect
if (!empty($_SESSION['admin'])) {
    header('Location: ' . ADMIN_URL . (
        $_SESSION['admin']['role'] === 'reporter'
            ? '/reporters/dashboard.php'
            : '/dashboard.php'
    ));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: 5 attempts per 15 minutes per IP
    rateLimit($pdo, 'admin_login', $ip, 5, 900);

    // CSRF check
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid credentials.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, name, role, agency_id, password, status
                 FROM admin_users
                 WHERE email = ? AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Reset rate limit on success
                resetRateLimit($pdo, 'admin_login', $ip);

                // Set session via auth/session.php
                setAdminSession($user);

                // Update last_login (non-fatal)
                try {
                    $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")
                        ->execute([$user['id']]);
                } catch (PDOException $e) {
                    error_log('last_login update failed: ' . $e->getMessage());
                }

                header('Location: ' . ADMIN_URL . (
                    $user['role'] === 'reporter'
                        ? '/reporters/dashboard.php'
                        : '/dashboard.php'
                ));
                exit;
            }

            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — News Xpress</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column}
        .top-bar{height:4px;background:linear-gradient(90deg,#28a745,#007bff,#fd7e14,#dc3545)}
        .login-layout{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}
        .login-container{width:100%;max-width:440px}
        .brand-header{text-align:center;margin-bottom:28px}
        .brand-header h1{font-size:24px;font-weight:700;color:#1a1d26;margin-bottom:6px}
        .brand-header p{font-size:14px;color:#8b90a0}
        .login-card{background:#fff;border:1px solid #e0e4ea;border-radius:14px;padding:36px 32px;box-shadow:0 12px 40px rgba(0,0,0,.08)}
        .error-alert{background:rgba(220,53,69,.06);border:1px solid rgba(220,53,69,.2);border-left:3px solid #dc3545;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:13px;font-weight:500;color:#dc3545}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#1a1d26;margin-bottom:6px}
        .form-group input{width:100%;height:46px;border:1.5px solid #e0e4ea;border-radius:8px;padding:0 14px;font-family:inherit;font-size:14px;color:#1a1d26;outline:none;transition:.2s}
        .form-group input:focus{border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.18)}
        .btn-submit{width:100%;height:48px;border:none;border-radius:8px;background:#28a745;color:#fff;font-family:inherit;font-size:15px;font-weight:600;cursor:pointer;transition:.2s}
        .btn-submit:hover{background:#218838}
        .login-footer{text-align:center;margin-top:20px;font-size:12px;color:#8b90a0}
    </style>
</head>
<body>
<div class="top-bar"></div>
<div class="login-layout">
    <div class="login-container">
        <div class="brand-header">
            <h1>Admin Panel</h1>
            <p>Sign in to manage your workspace</p>
        </div>
        <div class="login-card">
            <?php if ($error): ?>
                <div class="error-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required autocomplete="email"
                           value="<?= isset($email) ? htmlspecialchars($email) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-submit">Sign In</button>
            </form>
        </div>
        <div class="login-footer">&copy; <?= date('Y') ?> News Xpress &mdash; Admin Panel</div>
    </div>
</div>
</body>
</html>
