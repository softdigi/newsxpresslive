<?php
/**
 * admin_panel/security/2fa_verify.php
 *
 * Second-factor verification page.
 * Shown after successful password login when totp_enabled = 1.
 *
 * Session variable 'pending_2fa_uid' must be set by login.php.
 * On success:   finalize session → redirect to dashboard.
 * On failure:   show error + 30-second countdown.
 * Backup code:  switch to backup-code form via ?mode=backup.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/totp.php';

// Must have a pending 2FA session
if (empty($_SESSION['pending_2fa_uid'])) {
    header('Location: ' . ADMIN_URL . '/login.php');
    exit;
}

// If already fully logged in, just redirect
if (!empty($_SESSION['admin'])) {
    header('Location: ' . ADMIN_URL . '/dashboard.php');
    exit;
}

$error   = '';
$mode    = $_GET['mode'] ?? 'totp';   // 'totp' | 'backup'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } else {
        $uid = (int)$_SESSION['pending_2fa_uid'];

        $stmt = $pdo->prepare(
            "SELECT id, name, role, agency_id, totp_secret, totp_backup_codes, status
             FROM admin_users
             WHERE id = ? AND status = 'active' AND totp_enabled = 1
             LIMIT 1"
        );
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        if (!$user) {
            // Admin no longer exists or 2FA was disabled meanwhile
            session_destroy();
            header('Location: ' . ADMIN_URL . '/login.php');
            exit;
        }

        $verified = false;

        if ($mode === 'backup') {
            // ── Backup code path ──────────────────────────────────────
            $inputCode = strtoupper(trim($_POST['backup_code'] ?? ''));
            $hashes    = json_decode($user['totp_backup_codes'] ?? '[]', true);

            $updated = TotpHelper::verifyBackupCode($inputCode, $hashes);
            if ($updated !== false) {
                // Consume the used code
                $pdo->prepare("UPDATE admin_users SET totp_backup_codes = ? WHERE id = ?")
                    ->execute([json_encode($updated), $uid]);
                $verified = true;
            } else {
                $error = 'Invalid backup code.';
            }
        } else {
            // ── TOTP path ─────────────────────────────────────────────
            $code = trim($_POST['totp_code'] ?? '');
            if (TotpHelper::verifyCode($user['totp_secret'], $code)) {
                $verified = true;
            } else {
                $error = 'Incorrect code. Please wait for the next 30-second window and try again.';
            }
        }

        if ($verified) {
            unset($_SESSION['pending_2fa_uid']);

            // Use the same setAdminSession helper as login.php
            setAdminSession([
                'id'        => $user['id'],
                'name'      => $user['name'],
                'role'      => $user['role'],
                'agency_id' => $user['agency_id'],
            ]);

            $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")
                ->execute([$user['id']]);

            header('Location: ' . ADMIN_URL . (
                $user['role'] === 'reporter' ? '/reporters/dashboard.php' : '/dashboard.php'
            ));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication — News Xpress Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column}
        .top-bar{height:4px;background:linear-gradient(90deg,#28a745,#007bff,#fd7e14)}
        .page{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}
        .card{background:#fff;border:1px solid #e0e4ea;border-radius:14px;padding:36px 32px;max-width:420px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.08)}
        h1{font-size:20px;font-weight:700;color:#1a1d26;margin-bottom:4px}
        .subtitle{font-size:13px;color:#8b90a0;margin-bottom:24px}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#1a1d26;margin-bottom:6px}
        .form-group input{width:100%;height:56px;border:1.5px solid #e0e4ea;border-radius:8px;padding:0 14px;font-family:inherit;font-size:24px;color:#1a1d26;outline:none;transition:.2s;text-align:center;letter-spacing:6px}
        .form-group input.backup{font-size:16px;letter-spacing:2px}
        .form-group input:focus{border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.18)}
        .btn-submit{width:100%;height:48px;border:none;border-radius:8px;background:#007bff;color:#fff;font-family:inherit;font-size:15px;font-weight:600;cursor:pointer;transition:.2s}
        .btn-submit:hover{background:#0056b3}
        .error-alert{background:rgba(220,53,69,.06);border:1px solid rgba(220,53,69,.2);border-left:3px solid #dc3545;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:13px;font-weight:500;color:#dc3545}
        .switch-link{text-align:center;margin-top:16px;font-size:13px;color:#8b90a0}
        .switch-link a{color:#007bff;text-decoration:none;font-weight:600}
        .countdown-bar{height:4px;background:#e0e4ea;border-radius:2px;overflow:hidden;margin-bottom:8px}
        .countdown-fill{height:100%;background:#007bff;transition:width 1s linear}
        .countdown-label{font-size:12px;color:#8b90a0;text-align:right;margin-bottom:18px}
        .shield-icon{font-size:40px;text-align:center;margin-bottom:12px}
    </style>
</head>
<body>
<div class="top-bar"></div>
<div class="page">
<div class="card">

    <div class="shield-icon">🔐</div>

    <?php if ($mode === 'backup'): ?>
    <h1>Use a Backup Code</h1>
    <p class="subtitle">Enter one of your saved one-time backup codes</p>

    <?php if ($error): ?>
    <div class="error-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="2fa_verify.php?mode=backup">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="form-group">
            <label>Backup Code (format: XXXX-XXXX)</label>
            <input type="text" name="backup_code" class="backup" required
                   placeholder="XXXX-XXXX" autocomplete="off" maxlength="9">
        </div>
        <button type="submit" class="btn-submit">Verify Backup Code</button>
    </form>

    <div class="switch-link">
        <a href="2fa_verify.php">← Use authenticator app instead</a>
    </div>

    <?php else: ?>
    <h1>Two-Factor Authentication</h1>
    <p class="subtitle">Enter the 6-digit code from your authenticator app</p>

    <?php if ($error): ?>
    <div class="error-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="countdown-bar">
        <div class="countdown-fill" id="cbar" style="width:100%"></div>
    </div>
    <div class="countdown-label">Code refreshes in <span id="secs">30</span>s</div>

    <form method="POST" action="2fa_verify.php">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="form-group">
            <label>6-Digit Code</label>
            <input type="text" name="totp_code" maxlength="6" inputmode="numeric"
                   autocomplete="one-time-code" required placeholder="000000"
                   autofocus>
        </div>
        <button type="submit" class="btn-submit">Verify</button>
    </form>

    <div class="switch-link">
        Lost your device? <a href="2fa_verify.php?mode=backup">Use a backup code</a>
    </div>
    <?php endif; ?>

</div>
</div>
<script>
(function () {
    const secsEl = document.getElementById('secs');
    const barEl  = document.getElementById('cbar');
    if (!secsEl) return;

    function tick() {
        const elapsed = Math.floor(Date.now() / 1000) % 30;
        const rem     = 30 - elapsed;
        secsEl.textContent    = rem;
        if (barEl) barEl.style.width = ((rem / 30) * 100) + '%';
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
</body>
</html>
