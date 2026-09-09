<?php
/**
 * admin_panel/security/2fa_setup.php
 *
 * Three-step TOTP setup wizard for admin users.
 *
 * Step 1  (GET  ?step=1): Generate secret → show QR code
 * Step 2  (POST ?step=2): Verify first code → save secret + enabled flag
 * Step 3  (GET  ?step=3): Show & offer download of backup codes
 *
 * After step 2 the admin is marked totp_enabled = 1.
 * Backup codes are hashed (bcrypt) before DB storage.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/totp.php';

requireAdminAuth();
$admin = $_SESSION['admin'];

// ── Determine step ────────────────────────────────────────────────────────────
$step  = (int)($_GET['step'] ?? 1);
$error = '';

// ── Step-2 POST: verify first code ───────────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
        $step  = 1;
    } else {
        $secret     = trim($_POST['totp_secret'] ?? '');
        $code       = trim($_POST['totp_code']   ?? '');
        $secretLen  = strlen($secret);

        if ($secretLen < 16 || $secretLen > 64 || !ctype_alnum($secret)) {
            $error = 'Invalid session data. Please restart setup.';
            $step  = 1;
        } elseif (!TotpHelper::verifyCode($secret, $code)) {
            $error = 'Incorrect code. Please try again.';
        } else {
            // Generate and hash backup codes
            $plainCodes = TotpHelper::generateBackupCodes(8);
            $hashed     = array_map([TotpHelper::class, 'hashBackupCode'], $plainCodes);

            $stmt = $pdo->prepare(
                "UPDATE admin_users
                 SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ?
                 WHERE id = ?"
            );
            $stmt->execute([$secret, json_encode($hashed), $admin['id']]);

            // Store plain codes in session briefly for step-3 display
            $_SESSION['totp_backup_plain'] = $plainCodes;

            header('Location: 2fa_setup.php?step=3');
            exit;
        }
    }
}

// ── Step-1 GET: generate a fresh secret each visit ───────────────────────────
if ($step === 1) {
    $secret      = TotpHelper::generateSecret(16);
    $email       = $admin['email'] ?? 'admin@newsxpress';
    $otpauthUri  = TotpHelper::qrCodeUrl($secret, $email);
    $qrUrl       = TotpHelper::googleQrImageUrl($otpauthUri);
}

// ── Step-3 GET: show backup codes ────────────────────────────────────────────
if ($step === 3) {
    $backupCodes = $_SESSION['totp_backup_plain'] ?? [];
    unset($_SESSION['totp_backup_plain']);   // show only once
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication Setup — News Xpress Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column}
        .top-bar{height:4px;background:linear-gradient(90deg,#28a745,#007bff,#fd7e14)}
        .page{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}
        .card{background:#fff;border:1px solid #e0e4ea;border-radius:14px;padding:36px 32px;max-width:480px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.08)}
        h1{font-size:20px;font-weight:700;color:#1a1d26;margin-bottom:4px}
        .subtitle{font-size:13px;color:#8b90a0;margin-bottom:24px}
        .steps{display:flex;gap:8px;margin-bottom:28px}
        .step-dot{flex:1;height:4px;border-radius:2px;background:#e0e4ea}
        .step-dot.active{background:#007bff}
        .step-dot.done{background:#28a745}
        .qr-block{text-align:center;margin:20px 0}
        .qr-block img{border:1px solid #e0e4ea;border-radius:8px;padding:8px}
        .secret-box{background:#f8f9fa;border:1px solid #e0e4ea;border-radius:8px;padding:14px 16px;font-family:monospace;font-size:15px;letter-spacing:2px;text-align:center;color:#1a1d26;margin:12px 0 20px}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#1a1d26;margin-bottom:6px}
        .form-group input{width:100%;height:46px;border:1.5px solid #e0e4ea;border-radius:8px;padding:0 14px;font-family:inherit;font-size:14px;color:#1a1d26;outline:none;transition:.2s;text-align:center;letter-spacing:4px;font-size:20px}
        .form-group input:focus{border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.18)}
        .btn{display:inline-block;padding:12px 28px;border:none;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:.2s;text-decoration:none;text-align:center}
        .btn-primary{background:#007bff;color:#fff}
        .btn-primary:hover{background:#0056b3}
        .btn-success{background:#28a745;color:#fff;width:100%}
        .btn-success:hover{background:#218838}
        .error-alert{background:rgba(220,53,69,.06);border:1px solid rgba(220,53,69,.2);border-left:3px solid #dc3545;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:13px;font-weight:500;color:#dc3545}
        .info-note{background:#e8f4fd;border:1px solid #bee5fd;border-radius:8px;padding:12px 14px;font-size:13px;color:#0c5460;margin-bottom:20px}
        .backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:16px 0 20px}
        .backup-code{background:#f8f9fa;border:1px solid #e0e4ea;border-radius:6px;padding:10px;font-family:monospace;font-size:14px;text-align:center;color:#1a1d26}
        .warning-box{background:rgba(253,126,20,.07);border:1px solid rgba(253,126,20,.25);border-radius:8px;padding:12px 14px;font-size:13px;color:#7c3a00;margin-bottom:20px}
        .countdown{font-size:12px;color:#8b90a0;text-align:center;margin-top:8px}
    </style>
</head>
<body>
<div class="top-bar"></div>
<div class="page">
    <div class="card">

        <?php if ($step === 1): ?>
        <!-- ── Step 1: Scan QR code ────────────────────────────────── -->
        <h1>Set Up Two-Factor Authentication</h1>
        <p class="subtitle">Secure your account with a TOTP authenticator app</p>

        <div class="steps">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>

        <div class="info-note">
            Scan the QR code below with <strong>Google Authenticator</strong>,
            <strong>Authy</strong>, or any TOTP-compatible app.
            Then click <em>Next</em> to verify.
        </div>

        <div class="qr-block">
            <img src="<?= htmlspecialchars($qrUrl) ?>" width="200" height="200" alt="2FA QR Code">
        </div>

        <p style="font-size:13px;color:#8b90a0;text-align:center;margin-bottom:4px">
            Can't scan? Enter this secret manually:
        </p>
        <div class="secret-box"><?= htmlspecialchars(chunk_split($secret, 4, ' ')) ?></div>

        <form action="2fa_setup.php?step=2" method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="totp_secret" value="<?= htmlspecialchars($secret) ?>">

            <?php if ($error): ?>
            <div class="error-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label>Enter 6-digit code from your app</label>
                <input type="text" name="totp_code" maxlength="6" inputmode="numeric"
                       autocomplete="one-time-code" required placeholder="000000">
                <div class="countdown" id="countdown">Code refreshes in <span id="secs">30</span>s</div>
            </div>

            <button type="submit" class="btn btn-success">Verify &amp; Enable 2FA</button>
        </form>

        <?php elseif ($step === 3): ?>
        <!-- ── Step 3: Backup codes ────────────────────────────────── -->
        <h1>Save Your Backup Codes</h1>
        <p class="subtitle">These can be used if you lose access to your authenticator</p>

        <div class="steps">
            <div class="step-dot done"></div>
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
        </div>

        <div class="warning-box">
            ⚠️ <strong>Save these codes now.</strong> They will not be shown again.
            Each code can only be used once.
        </div>

        <?php if (empty($backupCodes)): ?>
        <p style="color:#dc3545;font-size:14px">
            Backup codes were already shown and removed from session.
            If you need new codes, please reset 2FA from settings.
        </p>
        <?php else: ?>
        <div class="backup-grid">
            <?php foreach ($backupCodes as $c): ?>
            <div class="backup-code"><?= htmlspecialchars($c) ?></div>
            <?php endforeach; ?>
        </div>

        <a href="#" onclick="downloadCodes()" class="btn btn-primary" style="display:block;margin-bottom:12px">
            Download Codes (.txt)
        </a>
        <?php endif; ?>

        <a href="<?= ADMIN_URL ?>/dashboard.php" class="btn btn-success">
            Done — Go to Dashboard
        </a>

        <script>
        function downloadCodes() {
            const codes = <?= json_encode($backupCodes ?? []) ?>;
            const text  = "NewsXpress Admin — 2FA Backup Codes\n" +
                          "Keep these safe. Each code works only once.\n\n" +
                          codes.join("\n");
            const blob  = new Blob([text], {type:'text/plain'});
            const a     = document.createElement('a');
            a.href      = URL.createObjectURL(blob);
            a.download  = 'newsxpress_backup_codes.txt';
            a.click();
        }
        </script>
        <?php endif; ?>

    </div>
</div>
<script>
// 30-second countdown for TOTP refresh reminder (step 1 only)
(function() {
    const el = document.getElementById('secs');
    if (!el) return;
    function tick() {
        const s = 30 - (Math.floor(Date.now() / 1000) % 30);
        el.textContent = s;
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
</body>
</html>
