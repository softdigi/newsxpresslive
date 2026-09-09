<?php
/**
 * web/legal/refund.php
 * Refund Policy — NewsXpressLive
 * Required by Razorpay for live mode activation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

$lastUpdated = '12 April 2026';
$seoMeta = [
    'title'       => 'Refund Policy',
    'description' => 'NewsXpressLive Refund Policy — Blue Tick, subscription, and payment refund terms. Razorpay-compliant.',
    'url'         => SITE_URL . '/legal/refund.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#880e4f,#ad1457); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1rem; opacity:.85; }
.legal-body    { max-width:860px; margin:0 auto; padding:40px 20px 80px; }
.legal-body h2 { font-size:1.2rem; font-weight:700; color:#880e4f; margin:36px 0 12px; border-left:4px solid #880e4f; padding-left:12px; }
.legal-body p, .legal-body li { font-size:.93rem; color:#444; line-height:1.85; }
.legal-body ul  { padding-left:22px; margin:8px 0 12px; }
.table-wrap { overflow-x:auto; margin:14px 0; }
.legal-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.legal-table th, .legal-table td { border:1px solid #ddd; padding:10px 14px; text-align:left; }
.legal-table th { background:#fce4ec; font-weight:600; }
.warn-box  { background:#fff3e0; border-left:4px solid #ff9800; padding:16px 20px; border-radius:0 8px 8px 0; margin:16px 0; }
.ok-box    { background:#e8f5e9; border-left:4px solid #43a047; padding:16px 20px; border-radius:0 8px 8px 0; margin:16px 0; }
.last-updated { text-align:center; color:#666; font-size:.85rem; margin-top:40px; }
</style>

<div class="legal-hero">
    <h1>💳 Refund Policy</h1>
    <p>Last Updated: <?= htmlspecialchars($lastUpdated) ?></p>
</div>

<div class="legal-body">

    <p>At NewsXpressLive, we aim to be transparent and fair about all payment-related matters. This policy describes under what conditions refunds are available.</p>

    <h2>1. Blue Tick Verification Fee</h2>
    <div class="table-wrap">
    <table class="legal-table">
        <tr><th>Scenario</th><th>Refund Eligibility</th><th>Timeline</th></tr>
        <tr><td>Application is <strong>approved</strong></td><td>❌ Non-refundable</td><td>—</td></tr>
        <tr><td>Application is <strong>rejected</strong> by our team</td><td>✅ Full refund</td><td>5–7 business days</td></tr>
        <tr><td>Application is <strong>pending</strong> and you request cancellation within 24 hours</td><td>✅ Full refund</td><td>5–7 business days</td></tr>
        <tr><td>Application is <strong>pending</strong> for more than 24 hours and you request cancellation</td><td>✅ Refund (less ₹9 processing fee)</td><td>5–7 business days</td></tr>
        <tr><td>Payment was charged but application was never created (technical error)</td><td>✅ Full refund</td><td>3–5 business days</td></tr>
    </table>
    </div>
    <div class="warn-box">
        <p>⚠️ Once your Blue Tick is <strong>approved and activated</strong>, the fee is <strong>non-refundable</strong>, even if you later choose to delete your account.</p>
    </div>

    <h2>2. Agency Reporter Tick Assignment (₹49)</h2>
    <div class="table-wrap">
    <table class="legal-table">
        <tr><th>Scenario</th><th>Refund Eligibility</th></tr>
        <tr><td>Reporter assignment was never activated</td><td>✅ Full refund within 7 days</td></tr>
        <tr><td>Reporter was successfully assigned</td><td>❌ Non-refundable</td></tr>
    </table>
    </div>

    <h2>3. Premium Subscription</h2>
    <div class="ok-box">
        <p>✅ Subscriptions are eligible for a <strong>pro-rata refund</strong> for the unused portion of the billing period, calculated from the date of the refund request.</p>
    </div>
    <ul>
        <li>Monthly subscription: Refund = (remaining_days / 30) × ₹29</li>
        <li>Refund requests must be made within 30 days of the billing date.</li>
        <li>After 30 days, the subscription can be cancelled (takes effect at the end of the billing cycle) but no refund is issued.</li>
    </ul>

    <h2>4. Refund Process</h2>
    <ul>
        <li><strong>How to request:</strong> Email <a href="mailto:refunds@newsxpresslive.com">refunds@newsxpresslive.com</a> with your registered email, order ID, and reason for the refund request.</li>
        <li><strong>Processing time:</strong> 5–7 business days from approval of the refund request.</li>
        <li><strong>Refund method:</strong> Refunds are credited to the original payment method (card, UPI, net banking). We do not offer cash refunds.</li>
        <li><strong>Bank processing time:</strong> After we process the refund, your bank may take an additional 2–5 business days to reflect it in your account.</li>
    </ul>

    <h2>5. Non-Refundable Items</h2>
    <ul>
        <li>Approved Blue Tick verifications (reporter or agency)</li>
        <li>Completed reporter tick assignments</li>
        <li>Subscription periods that have already been used</li>
        <li>Any amount earned or transferred to reporter wallets</li>
    </ul>

    <h2>6. Contact for Refund Requests</h2>
    <ul>
        <li><strong>Email:</strong> <a href="mailto:refunds@newsxpresslive.com">refunds@newsxpresslive.com</a></li>
        <li><strong>Subject:</strong> "Refund Request — [Your Order ID]"</li>
        <li><strong>Support:</strong> <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></li>
        <li><strong>Grievance:</strong> <a href="<?= SITE_URL ?>/legal/grievance.php">File a Grievance</a></li>
    </ul>

    <p class="last-updated">Last Updated: <?= htmlspecialchars($lastUpdated) ?> &nbsp;|&nbsp; NewsXpressLive</p>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
