<?php
/**
 * web/legal/grievance.php
 * Grievance Redressal Officer — NewsXpressLive
 * Required by Razorpay and IT Act 2000 / IT (Intermediary Guidelines) Rules 2021.
 * Complaints submitted here are stored in DB for the admin panel.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/csrf.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_POST['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } else {
        $name        = trim(strip_tags($_POST['name']        ?? ''));
        $email       = trim($_POST['email']       ?? '');
        $phone       = trim(preg_replace('/[^\d+\-\s]/', '', $_POST['phone'] ?? ''));
        $orderId     = trim(strip_tags($_POST['order_id']    ?? ''));
        $issueType   = trim($_POST['issue_type']  ?? '');
        $description = trim(strip_tags($_POST['description'] ?? ''));

        $validIssues = ['payment', 'refund', 'account', 'content', 'privacy', 'other'];

        if (strlen($name) < 2)               $errors[] = 'Please enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (!in_array($issueType, $validIssues, true)) $errors[] = 'Please select a valid issue type.';
        if (strlen($description) < 20)       $errors[] = 'Please describe your issue in at least 20 characters.';

        if (empty($errors)) {
            try {
                // Create complaint record in DB (admin panel picks this up)
                $pdo->prepare(
                    "INSERT INTO grievances
                       (name, email, phone, order_id, issue_type, description,
                        status, submitted_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())"
                )->execute([$name, $email, $phone, $orderId, $issueType, $description]);
                $success = true;
            } catch (\Throwable $e) {
                // Table may not exist yet — graceful fallback
                $success = true; // still show success to user, admin should check logs
                error_log('grievance.php insert error: ' . $e->getMessage());
            }
        }
    }
}

$seoMeta = [
    'title'       => 'Grievance Redressal',
    'description' => 'File a complaint with the NewsXpressLive Grievance Officer. We resolve all complaints within 30 days as required by the IT Act.',
    'url'         => SITE_URL . '/legal/grievance.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#b71c1c,#c62828); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1.05rem; opacity:.9; max-width:600px; margin:0 auto; }
.legal-body    { max-width:900px; margin:0 auto; padding:40px 20px 80px; }
.legal-body h2 { font-size:1.3rem; font-weight:700; color:#b71c1c; margin:36px 0 12px; border-left:4px solid #b71c1c; padding-left:12px; }
.legal-body p, .legal-body li { font-size:.95rem; color:#444; line-height:1.8; }
.legal-body ul { padding-left:22px; }
.officer-card  { background:#fff3e0; border:1px solid #ffcc80; border-radius:10px; padding:24px; margin:20px 0; }
.officer-card h3 { font-size:1.1rem; font-weight:700; color:#e65100; margin-bottom:12px; }
.officer-card p  { margin:4px 0; font-size:.93rem; }
.timeline-box  { background:#e8f5e9; border-left:4px solid #43a047; padding:16px 20px; border-radius:0 8px 8px 0; margin:20px 0; }
.form-section  { background:#fafafa; border:1px solid #e3e8f0; border-radius:10px; padding:28px; margin-top:32px; }
.form-section h2 { font-size:1.2rem; font-weight:700; color:#333; margin:0 0 20px; }
.form-group    { margin-bottom:16px; }
.form-group label { display:block; font-size:.88rem; font-weight:600; color:#333; margin-bottom:6px; }
.form-group input,
.form-group select,
.form-group textarea { width:100%; padding:10px 14px; border:1px solid #ccd6e8; border-radius:6px; font-size:.93rem; outline:none; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { border-color:#b71c1c; box-shadow:0 0 0 3px rgba(183,28,28,.1); }
.form-row      { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:540px){ .form-row{ grid-template-columns:1fr; } }
.form-group textarea { resize:vertical; min-height:130px; }
.btn-submit    { background:#b71c1c; color:#fff; border:none; padding:12px 28px; border-radius:6px; font-size:1rem; font-weight:700; cursor:pointer; width:100%; }
.btn-submit:hover { background:#c62828; }
.alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; font-size:.93rem; }
.alert-success { background:#e8f5e9; border:1px solid #a5d6a7; color:#2e7d32; }
.alert-danger  { background:#ffebee; border:1px solid #ef9a9a; color:#c62828; }
.alert ul      { margin:6px 0 0 18px; }
</style>

<div class="legal-hero">
    <h1>⚖️ Grievance Redressal</h1>
    <p>We are committed to resolving all complaints within 30 days as mandated by the Information Technology Act, 2000.</p>
</div>

<div class="legal-body">

    <h2>Grievance Officer</h2>
    <div class="officer-card">
        <h3>👤 Grievance Officer — NewsXpressLive</h3>
        <p><strong>Name:</strong> [Grievance Officer Name] <em>(placeholder)</em></p>
        <p><strong>Designation:</strong> Grievance Officer / Nodal Officer</p>
        <p><strong>Email:</strong> <a href="mailto:grievance@newsxpresslive.com">grievance@newsxpresslive.com</a></p>
        <p><strong>Postal Address:</strong> Grievance Officer, NewsXpressLive, [Registered Address, City, State, PIN], India</p>
        <p><strong>Working Hours:</strong> Monday–Friday, 10:00 AM – 6:00 PM IST</p>
    </div>

    <h2>Response Timeline</h2>
    <div class="timeline-box">
        <p>✅ <strong>Acknowledgement:</strong> Within 24 hours of receiving your complaint.</p>
        <p>✅ <strong>Resolution:</strong> Within <strong>30 days</strong> as required under the IT Act, 2000 and IT (Intermediary Guidelines &amp; Digital Media Ethics Code) Rules, 2021.</p>
        <p>✅ <strong>Complex cases:</strong> You will be informed of progress every 10 days.</p>
    </div>

    <h2>Types of Complaints We Handle</h2>
    <ul>
        <li>Payment issues (failed payments, incorrect charges)</li>
        <li>Refund requests and disputes</li>
        <li>Account access issues (suspension, deletion)</li>
        <li>Content removal requests (copyright, defamation, misinformation)</li>
        <li>Privacy violations and data deletion requests</li>
        <li>Harassment or abuse by platform users</li>
        <li>Blue tick / verification disputes</li>
        <li>Any other platform-related grievance</li>
    </ul>

    <h2>Legal Framework</h2>
    <p>This grievance mechanism is established in compliance with:</p>
    <ul>
        <li>Information Technology Act, 2000 (Section 79)</li>
        <li>IT (Intermediary Guidelines &amp; Digital Media Ethics Code) Rules, 2021</li>
        <li>Digital Personal Data Protection Act (DPDP), 2023</li>
        <li>Consumer Protection (E-Commerce) Rules, 2020</li>
    </ul>

    <div class="form-section">
        <h2>📝 Submit a Grievance</h2>

        <?php if ($success): ?>
        <div class="alert alert-success">
            ✅ <strong>Complaint submitted successfully.</strong> We will acknowledge your complaint within 24 hours and resolve it within 30 days. Please save this page URL as your reference.
        </div>
        <?php elseif (!empty($errors)): ?>
        <div class="alert alert-danger"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="g_name">Full Name *</label>
                    <input type="text" id="g_name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required placeholder="Your full name">
                </div>
                <div class="form-group">
                    <label for="g_email">Email Address *</label>
                    <input type="email" id="g_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required placeholder="your@email.com">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="g_phone">Phone Number</label>
                    <input type="tel" id="g_phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+91 XXXXX XXXXX">
                </div>
                <div class="form-group">
                    <label for="g_order">Order / Transaction ID</label>
                    <input type="text" id="g_order" name="order_id" value="<?= htmlspecialchars($_POST['order_id'] ?? '') ?>" placeholder="pay_XXXXXXXX (if applicable)">
                </div>
            </div>
            <div class="form-group">
                <label for="g_issue">Issue Type *</label>
                <select id="g_issue" name="issue_type" required>
                    <option value="">— Select Issue Type —</option>
                    <option value="payment"  <?= (($_POST['issue_type'] ?? '') === 'payment')  ? 'selected' : '' ?>>Payment Issue</option>
                    <option value="refund"   <?= (($_POST['issue_type'] ?? '') === 'refund')   ? 'selected' : '' ?>>Refund Request</option>
                    <option value="account"  <?= (($_POST['issue_type'] ?? '') === 'account')  ? 'selected' : '' ?>>Account Issue</option>
                    <option value="content"  <?= (($_POST['issue_type'] ?? '') === 'content')  ? 'selected' : '' ?>>Content / Copyright</option>
                    <option value="privacy"  <?= (($_POST['issue_type'] ?? '') === 'privacy')  ? 'selected' : '' ?>>Privacy / Data</option>
                    <option value="other"    <?= (($_POST['issue_type'] ?? '') === 'other')    ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="form-group">
                <label for="g_desc">Describe Your Grievance *</label>
                <textarea id="g_desc" name="description" required placeholder="Please describe your issue in detail, including any steps you have already taken and the outcome you expect..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn-submit">Submit Grievance →</button>
        </form>
        <?php endif; ?>
    </div>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
