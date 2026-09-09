<?php
/**
 * web/legal/contact.php
 * Contact Us — NewsXpressLive
 * Form submissions are queued via EmailJob for async sending.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/csrf.php';
require_once __DIR__ . '/../../helpers/job_queue.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (session_status() === PHP_SESSION_NONE) session_start();
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_POST['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } else {
        $name    = trim(strip_tags($_POST['name']    ?? ''));
        $email   = trim($_POST['email']   ?? '');
        $subject = trim(strip_tags($_POST['subject'] ?? ''));
        $message = trim(strip_tags($_POST['message'] ?? ''));

        if (strlen($name) < 2)               $errors[] = 'Please enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (strlen($subject) < 3)            $errors[] = 'Please enter a subject.';
        if (strlen($message) < 10)           $errors[] = 'Message must be at least 10 characters.';

        if (empty($errors)) {
            // Queue email job (async via job queue)
            try {
                JobQueue::dispatch('EmailJob', [
                    'template' => 'contact_form',
                    'to'       => 'support@newsxpresslive.com',
                    'reply_to' => $email,
                    'subject'  => '[Contact Form] ' . $subject,
                    'data'     => [
                        'name'    => $name,
                        'email'   => $email,
                        'subject' => $subject,
                        'message' => $message,
                        'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
                        'time'    => date('d M Y H:i:s'),
                    ],
                ], 'default', 0, $pdo);
                $success = true;
            } catch (\Throwable $e) {
                $errors[] = 'Could not submit your message. Please email us directly at support@newsxpresslive.com';
            }
        }
    }
}

$seoMeta = [
    'title'       => 'Contact Us',
    'description' => 'Get in touch with the NewsXpressLive team. We respond within 24–48 hours.',
    'url'         => SITE_URL . '/legal/contact.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#0d47a1,#1565c0); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1.1rem; opacity:.9; }
.legal-body    { max-width:900px; margin:0 auto; padding:40px 20px 80px; display:grid; grid-template-columns:1fr 1fr; gap:40px; }
@media(max-width:640px){ .legal-body{ grid-template-columns:1fr; } }
.contact-info  { }
.contact-info h2 { font-size:1.3rem; font-weight:700; color:#0d47a1; margin:0 0 16px; border-left:4px solid #0d47a1; padding-left:12px; }
.contact-info p  { font-size:.95rem; color:#444; line-height:1.7; margin-bottom:10px; }
.info-item      { display:flex; align-items:flex-start; gap:12px; margin:12px 0; }
.info-item .icon { font-size:1.4rem; flex-shrink:0; }
.info-item div  { font-size:.93rem; color:#333; }
.contact-form   { }
.contact-form h2 { font-size:1.3rem; font-weight:700; color:#0d47a1; margin:0 0 16px; border-left:4px solid #0d47a1; padding-left:12px; }
.form-group     { margin-bottom:16px; }
.form-group label { display:block; font-size:.88rem; font-weight:600; color:#333; margin-bottom:6px; }
.form-group input,
.form-group textarea,
.form-group select { width:100%; padding:10px 14px; border:1px solid #ccd6e8; border-radius:6px; font-size:.93rem; outline:none; transition:border-color .2s; }
.form-group input:focus,
.form-group textarea:focus { border-color:#0d47a1; box-shadow:0 0 0 3px rgba(13,71,161,.1); }
.form-group textarea { resize:vertical; min-height:130px; }
.btn-submit { background:#0d47a1; color:#fff; border:none; padding:12px 28px; border-radius:6px; font-size:1rem; font-weight:700; cursor:pointer; width:100%; }
.btn-submit:hover { background:#1565c0; }
.alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; font-size:.93rem; }
.alert-success { background:#e8f5e9; border:1px solid #a5d6a7; color:#2e7d32; }
.alert-danger  { background:#ffebee; border:1px solid #ef9a9a; color:#c62828; }
.alert ul { margin:6px 0 0 18px; }
</style>

<div class="legal-hero">
    <h1>📬 Contact Us</h1>
    <p>We'd love to hear from you. Our team responds within 24–48 hours.</p>
</div>

<div class="legal-body">
    <div class="contact-info">
        <h2>Get In Touch</h2>
        <div class="info-item"><span class="icon">📧</span><div><strong>Support</strong><br><a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></div></div>
        <div class="info-item"><span class="icon">📧</span><div><strong>Editorial</strong><br><a href="mailto:editorial@newsxpresslive.com">editorial@newsxpresslive.com</a></div></div>
        <div class="info-item"><span class="icon">📧</span><div><strong>Partnerships</strong><br><a href="mailto:partners@newsxpresslive.com">partners@newsxpresslive.com</a></div></div>
        <div class="info-item"><span class="icon">📞</span><div><strong>Phone</strong><br>+91 XXXXX XXXXX <em>(placeholder)</em></div></div>
        <div class="info-item"><span class="icon">🕐</span><div><strong>Response Time</strong><br>We respond within 24–48 business hours.</div></div>
        <div class="info-item"><span class="icon">📍</span><div><strong>Address</strong><br>[Registered Office Address]<br>India</div></div>
        <p style="margin-top:20px;font-size:.88rem;color:#666;">For payment disputes or grievances, please use our <a href="<?= SITE_URL ?>/legal/grievance.php">Grievance Redressal</a> page.</p>
    </div>

    <div class="contact-form">
        <h2>Send a Message</h2>

        <?php if ($success): ?>
        <div class="alert alert-success">✅ Your message has been sent! We'll get back to you within 24–48 hours.</div>
        <?php elseif (!empty($errors)): ?>
        <div class="alert alert-danger"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="form-group">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required placeholder="Rahul Kumar">
            </div>
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required placeholder="you@example.com">
            </div>
            <div class="form-group">
                <label for="subject">Subject *</label>
                <input type="text" id="subject" name="subject" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required placeholder="How can we help?">
            </div>
            <div class="form-group">
                <label for="message">Message *</label>
                <textarea id="message" name="message" required placeholder="Describe your query in detail..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn-submit">Send Message →</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
