<?php
/**
 * web/legal/privacy.php
 * Privacy Policy — NewsXpressLive
 * Compliant with India Digital Personal Data Protection (DPDP) Act, 2023
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

$lastUpdated = '12 April 2026';
$seoMeta = [
    'title'       => 'Privacy Policy',
    'description' => 'NewsXpressLive Privacy Policy — how we collect, use, and protect your personal data. DPDP Act 2023 compliant.',
    'url'         => SITE_URL . '/legal/privacy.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#1a237e,#283593); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1rem; opacity:.85; }
.legal-body    { max-width:860px; margin:0 auto; padding:40px 20px 80px; }
.legal-body h2 { font-size:1.2rem; font-weight:700; color:#1a237e; margin:36px 0 12px; border-left:4px solid #1a237e; padding-left:12px; }
.legal-body h3 { font-size:1rem; font-weight:700; color:#333; margin:18px 0 6px; }
.legal-body p, .legal-body li { font-size:.93rem; color:#444; line-height:1.85; }
.legal-body ul  { padding-left:22px; margin:8px 0 12px; }
.highlight-box  { background:#e8eaf6; border:1px solid #9fa8da; border-radius:8px; padding:16px 20px; margin:16px 0; }
.table-wrap     { overflow-x:auto; margin:14px 0; }
.legal-table    { width:100%; border-collapse:collapse; font-size:.88rem; }
.legal-table th, .legal-table td { border:1px solid #ddd; padding:9px 13px; text-align:left; }
.legal-table th { background:#e8eaf6; font-weight:600; }
.last-updated   { text-align:center; color:#666; font-size:.85rem; margin-top:40px; }
</style>

<div class="legal-hero">
    <h1>🔒 Privacy Policy</h1>
    <p>Last Updated: <?= htmlspecialchars($lastUpdated) ?> &nbsp;|&nbsp; Effective: 1 January 2025</p>
</div>

<div class="legal-body">

    <div class="highlight-box">
        <p>This Privacy Policy describes how <strong>NewsXpressLive</strong> ("we", "our", "platform") collects, uses, stores, and protects your personal data. This policy is compliant with the <strong>Digital Personal Data Protection (DPDP) Act, 2023</strong> and other applicable Indian laws.</p>
    </div>

    <h2>1. Data We Collect</h2>
    <div class="table-wrap">
    <table class="legal-table">
        <tr><th>Data Type</th><th>Examples</th><th>Collected When</th></tr>
        <tr><td>Identity</td><td>Name, display name, profile photo</td><td>Registration, profile update</td></tr>
        <tr><td>Contact</td><td>Email address, phone number</td><td>Registration, OTP verification</td></tr>
        <tr><td>Location</td><td>City, district, state, PIN code</td><td>News personalisation, account setup</td></tr>
        <tr><td>Device</td><td>Device ID, OS version, FCM token</td><td>App installation, notifications</td></tr>
        <tr><td>Payment</td><td>Payment method (masked), transaction ID</td><td>Blue tick purchase, subscription</td></tr>
        <tr><td>Content</td><td>Articles, photos, videos you publish</td><td>Reporter content submission</td></tr>
        <tr><td>Usage</td><td>Articles read, searches, clicks, watch time</td><td>Continuous — for personalisation</td></tr>
        <tr><td>Financial</td><td>Wallet balance, UPI ID / bank account (encrypted)</td><td>Withdrawal request</td></tr>
    </table>
    </div>

    <h2>2. How We Use Your Data</h2>
    <ul>
        <li><strong>News Personalisation:</strong> Serving relevant local news based on your location and interests.</li>
        <li><strong>Account Management:</strong> Creating and maintaining your reporter / agency / reader account.</li>
        <li><strong>Payments &amp; Wallets:</strong> Processing blue tick purchases, subscriptions, and reporter earnings via Razorpay and Stripe.</li>
        <li><strong>Notifications:</strong> Sending breaking news alerts, account updates via Firebase Cloud Messaging.</li>
        <li><strong>Analytics:</strong> Understanding platform usage to improve features and performance.</li>
        <li><strong>Legal Compliance:</strong> Fulfilling obligations under Indian law (IT Act, DPDP Act, TRAI guidelines).</li>
        <li><strong>Fraud Prevention:</strong> Detecting and preventing fraudulent transactions and fake accounts.</li>
    </ul>

    <h2>3. Third-Party Services We Use</h2>
    <div class="table-wrap">
    <table class="legal-table">
        <tr><th>Service</th><th>Purpose</th><th>Data Shared</th><th>Privacy Policy</th></tr>
        <tr><td>Firebase (Google)</td><td>Authentication, push notifications, realtime DB</td><td>UID, device token, email</td><td><a href="https://firebase.google.com/support/privacy" target="_blank" rel="noopener">View</a></td></tr>
        <tr><td>Razorpay</td><td>Indian payment processing</td><td>Payment amount, name, email, phone</td><td><a href="https://razorpay.com/privacy/" target="_blank" rel="noopener">View</a></td></tr>
        <tr><td>Stripe</td><td>International payment processing</td><td>Payment details (encrypted)</td><td><a href="https://stripe.com/privacy" target="_blank" rel="noopener">View</a></td></tr>
        <tr><td>Google AdMob</td><td>In-app advertising</td><td>Device ID, location, interests</td><td><a href="https://policies.google.com/privacy" target="_blank" rel="noopener">View</a></td></tr>
        <tr><td>SendGrid (Twilio)</td><td>Transactional email</td><td>Email address, name</td><td><a href="https://www.twilio.com/legal/privacy" target="_blank" rel="noopener">View</a></td></tr>
    </table>
    </div>
    <p>We do <strong>not</strong> sell your personal data to third parties for marketing purposes.</p>

    <h2>4. Data Retention</h2>
    <ul>
        <li><strong>Active account data:</strong> Retained for the lifetime of your account plus 3 years after closure.</li>
        <li><strong>Transaction records:</strong> 7 years (as required by Indian financial regulations).</li>
        <li><strong>Published content:</strong> Retained until you request deletion (subject to editorial review).</li>
        <li><strong>Usage logs:</strong> 90 days rolling.</li>
        <li><strong>Payment data:</strong> Minimal data retained; full card/bank details are handled by Razorpay/Stripe.</li>
    </ul>

    <h2>5. Your Rights (DPDP Act 2023)</h2>
    <ul>
        <li><strong>Right to Access:</strong> Request a copy of your personal data.</li>
        <li><strong>Right to Correction:</strong> Request correction of inaccurate data.</li>
        <li><strong>Right to Erasure:</strong> Request deletion of your account and associated data.</li>
        <li><strong>Right to Nominate:</strong> Nominate a person to exercise rights on your behalf in case of death or incapacity.</li>
        <li><strong>Right to Grievance:</strong> File a complaint with our Grievance Officer (see below).</li>
    </ul>
    <p>To exercise any of these rights, email <a href="mailto:privacy@newsxpresslive.com">privacy@newsxpresslive.com</a>. We will respond within 30 days.</p>

    <h2>6. Cookies Policy</h2>
    <p>Our website uses cookies and similar technologies for:</p>
    <ul>
        <li><strong>Essential cookies:</strong> Session management, CSRF protection, authentication state.</li>
        <li><strong>Analytics cookies:</strong> Understanding page visits and user behaviour (Google Analytics).</li>
        <li><strong>Preference cookies:</strong> Remembering your language, location, and news preferences.</li>
        <li><strong>Advertising cookies:</strong> Google AdSense / AdMob interest-based advertising.</li>
    </ul>
    <p>You can manage or disable cookies in your browser settings. Disabling essential cookies may affect platform functionality.</p>

    <h2>7. Data Security</h2>
    <ul>
        <li>All data transmitted over HTTPS/TLS 1.3.</li>
        <li>Passwords are never stored — authentication is handled via Firebase (Google).</li>
        <li>Payment details are encrypted and handled by PCI-DSS compliant processors (Razorpay, Stripe).</li>
        <li>Bank account / UPI details for withdrawals are stored encrypted at rest.</li>
        <li>Access to personal data is restricted on a need-to-know basis.</li>
    </ul>

    <h2>8. Children's Privacy</h2>
    <p>NewsXpressLive is not intended for users under 13 years of age. We do not knowingly collect data from children. If you believe a child has provided us data, please contact <a href="mailto:privacy@newsxpresslive.com">privacy@newsxpresslive.com</a> immediately.</p>

    <h2>9. Contact for Data Requests</h2>
    <ul>
        <li><strong>Data Protection Contact:</strong> <a href="mailto:privacy@newsxpresslive.com">privacy@newsxpresslive.com</a></li>
        <li><strong>Grievance Officer:</strong> <a href="<?= SITE_URL ?>/legal/grievance.php">File a grievance</a></li>
        <li><strong>General Support:</strong> <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></li>
    </ul>

    <h2>10. Changes to This Policy</h2>
    <p>We may update this Privacy Policy periodically. We will notify you of significant changes via email or an in-app notification. Continued use of the platform after changes constitutes acceptance of the updated policy.</p>

    <p class="last-updated">Last Updated: <?= htmlspecialchars($lastUpdated) ?> &nbsp;|&nbsp; NewsXpressLive</p>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
