<?php
/**
 * web/legal/terms.php
 * Terms of Service — NewsXpressLive
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

$lastUpdated = '12 April 2026';
$seoMeta = [
    'title'       => 'Terms of Service',
    'description' => 'NewsXpressLive Terms of Service — rules for using our platform as a reader, reporter, or agency.',
    'url'         => SITE_URL . '/legal/terms.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#004d40,#00695c); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1rem; opacity:.85; }
.legal-body    { max-width:860px; margin:0 auto; padding:40px 20px 80px; }
.legal-body h2 { font-size:1.2rem; font-weight:700; color:#004d40; margin:36px 0 12px; border-left:4px solid #004d40; padding-left:12px; }
.legal-body h3 { font-size:1rem; font-weight:700; color:#333; margin:18px 0 6px; }
.legal-body p, .legal-body li { font-size:.93rem; color:#444; line-height:1.85; }
.legal-body ul, .legal-body ol { padding-left:22px; margin:8px 0 12px; }
.warn-box  { background:#fff3e0; border-left:4px solid #ff9800; padding:16px 20px; border-radius:0 8px 8px 0; margin:16px 0; }
.info-box  { background:#e0f2f1; border-left:4px solid #26a69a; padding:16px 20px; border-radius:0 8px 8px 0; margin:16px 0; }
.table-wrap { overflow-x:auto; margin:14px 0; }
.legal-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.legal-table th, .legal-table td { border:1px solid #ddd; padding:9px 13px; text-align:left; }
.legal-table th { background:#e0f2f1; font-weight:600; }
.last-updated { text-align:center; color:#666; font-size:.85rem; margin-top:40px; }
</style>

<div class="legal-hero">
    <h1>📋 Terms of Service</h1>
    <p>Last Updated: <?= htmlspecialchars($lastUpdated) ?> &nbsp;|&nbsp; Effective: 1 January 2025</p>
</div>

<div class="legal-body">

    <p>Welcome to <strong>NewsXpressLive</strong>. By accessing or using our platform (website, mobile app, or API), you agree to be bound by these Terms of Service. Please read them carefully.</p>

    <h2>1. Platform Usage Rules</h2>
    <ul>
        <li>You must be at least 13 years of age to use this platform.</li>
        <li>You are responsible for maintaining the security of your account credentials.</li>
        <li>One person may not operate multiple accounts without prior written approval.</li>
        <li>You may not use automated tools, bots, or scrapers without our explicit permission.</li>
        <li>You agree not to interfere with the platform's technical infrastructure.</li>
        <li>Commercial use of platform content requires a separate licensing agreement.</li>
    </ul>

    <h2>2. Reporter Responsibilities</h2>
    <ul>
        <li>All published content must be factual, accurate, and verifiable at the time of publication.</li>
        <li>Reporters must disclose conflicts of interest relevant to any story.</li>
        <li>Reporters are solely responsible for the accuracy of the news they publish.</li>
        <li>Plagiarism, fabrication, or misrepresentation of facts is strictly prohibited and will result in permanent account termination.</li>
        <li>Reporters must not publish content that is defamatory, obscene, or in violation of any individual's privacy rights.</li>
        <li>A credibility score is maintained for every reporter. Consistently low scores may result in content restrictions or account suspension.</li>
    </ul>

    <h2>3. Agency Terms</h2>
    <ul>
        <li>Agencies are responsible for the conduct and content published by reporters under their account.</li>
        <li>Agencies must ensure all assigned reporters have completed identity verification.</li>
        <li>Agencies receive <strong>40% of the revenue</strong> generated from their network's content; the platform retains a <strong>10% agency management fee</strong> from the agency's share.</li>
        <li>Agencies may not assign blue tick credentials to reporters who have not completed the verification process.</li>
        <li>Agency accounts found to be facilitating fake news or coordinated disinformation will be permanently terminated without refund.</li>
    </ul>

    <h2>4. Blue Tick Verification Terms</h2>
    <div class="warn-box">
        <p>⚠️ <strong>Non-Refundable After Approval:</strong> Once your Blue Tick application is reviewed and approved, the fee is <strong>non-refundable</strong>. See our <a href="<?= SITE_URL ?>/legal/refund.php">Refund Policy</a> for pending-state refunds.</p>
    </div>
    <ul>
        <li>Blue Tick signifies identity verification — it does not imply editorial endorsement.</li>
        <li>Verified status can be revoked if the account subsequently violates platform policies.</li>
        <li>Blue Tick is personal and non-transferable.</li>
        <li>Providing false documents for verification is a criminal offence under Section 66D of the IT Act, 2000.</li>
    </ul>

    <h2>5. Content Ownership</h2>
    <div class="info-box">
        <p>✅ <strong>You own your content.</strong> Reporters and agencies retain full intellectual property rights over the news articles, photos, and videos they publish on NewsXpressLive.</p>
    </div>
    <ul>
        <li>By publishing on our platform, you grant NewsXpressLive a worldwide, non-exclusive, royalty-free licence to display, distribute, and promote your content across our platform and partner channels.</li>
        <li>You may request removal of your content by contacting <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a>.</li>
        <li>NewsXpressLive retains the right to remove content that violates these terms without compensation.</li>
    </ul>

    <h2>6. Revenue Sharing</h2>
    <div class="table-wrap">
    <table class="legal-table">
        <tr><th>Role</th><th>Revenue Share</th><th>Notes</th></tr>
        <tr><td>Reporter (independent)</td><td>90%</td><td>Of ad revenue from reporter's articles</td></tr>
        <tr><td>Agency</td><td>40%</td><td>Of combined revenue from agency's reporter network</td></tr>
        <tr><td>Agency reporter</td><td>50% of agency share</td><td>Split between reporter and agency</td></tr>
        <tr><td>Platform fee</td><td>10%</td><td>Retained by NewsXpressLive</td></tr>
    </table>
    </div>
    <ul>
        <li>Revenue is credited to the reporter's in-platform wallet every month.</li>
        <li>Minimum wallet balance of ₹50 is required for withdrawal.</li>
        <li>Revenue is subject to applicable TDS deductions as per Indian tax law.</li>
    </ul>

    <h2>7. Prohibited Content</h2>
    <p>The following content is strictly prohibited on NewsXpressLive:</p>
    <ul>
        <li>Fake news, fabricated stories, misinformation, or disinformation</li>
        <li>Hate speech targeting individuals or groups based on religion, caste, race, gender, or sexuality</li>
        <li>Sexually explicit or pornographic content</li>
        <li>Content glorifying terrorism, violence, or illegal activities</li>
        <li>Content that violates someone's privacy or right to be forgotten</li>
        <li>Defamatory statements without factual basis</li>
        <li>Plagiarised or copyright-infringing content</li>
        <li>Political propaganda disguised as news</li>
        <li>Spam, advertising disguised as editorial content</li>
        <li>Content promoting financial fraud or scams</li>
    </ul>

    <h2>8. Account Termination</h2>
    <ul>
        <li>We may suspend or terminate your account for violation of these terms, with or without prior notice depending on severity.</li>
        <li>First violation: Warning + temporary content restriction (7 days).</li>
        <li>Second violation: Account suspension (30 days).</li>
        <li>Third violation / severe violation: Permanent account termination.</li>
        <li>Wallet balance will be forfeited upon termination for policy violations.</li>
        <li>You may appeal a termination decision by emailing <a href="mailto:appeals@newsxpresslive.com">appeals@newsxpresslive.com</a> within 14 days.</li>
    </ul>

    <h2>9. Limitation of Liability</h2>
    <p>NewsXpressLive provides the platform on an "as-is" basis. We are not liable for any loss or damage arising from reliance on content published by third-party reporters, technical outages, or force majeure events. Our maximum liability to any user shall not exceed the amount paid by that user to us in the 12 months preceding the claim.</p>

    <h2>10. Governing Law &amp; Jurisdiction</h2>
    <p>These Terms are governed by the laws of India. Any dispute arising from these terms shall be subject to the exclusive jurisdiction of the courts at <strong>[City, State]</strong>, India.</p>

    <h2>11. Changes to Terms</h2>
    <p>We may update these Terms periodically. Material changes will be notified via email or in-app notification at least 7 days before taking effect. Continued use after the effective date constitutes acceptance.</p>

    <p class="last-updated">Last Updated: <?= htmlspecialchars($lastUpdated) ?> &nbsp;|&nbsp; NewsXpressLive</p>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
