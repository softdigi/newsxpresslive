<?php
/**
 * web/legal/pricing.php
 * Pricing — NewsXpressLive
 * Required by Razorpay for live mode activation.
 * Shows dynamic slot counters from DB (early_bird_counters table).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

// Fetch early-bird slot data from DB
$reporterFreeRemaining = 0;
$agencyFreeRemaining   = 0;
try {
    $stmt = $pdo->query(
        "SELECT type, free_limit, count FROM early_bird_counters WHERE type IN ('reporter','agency')"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $remaining = max(0, (int)$row['free_limit'] - (int)$row['count']);
        if ($row['type'] === 'reporter') $reporterFreeRemaining = $remaining;
        if ($row['type'] === 'agency')   $agencyFreeRemaining   = $remaining;
    }
} catch (\Throwable $e) {
    // Table not yet migrated — show defaults
    $reporterFreeRemaining = 100;
    $agencyFreeRemaining   = 10;
}

$seoMeta = [
    'title'       => 'Pricing',
    'description' => 'NewsXpressLive Pricing — Reporter Blue Tick ₹99, Agency Blue Tick ₹299. First 100 reporters FREE. Transparent, no hidden fees.',
    'url'         => SITE_URL . '/legal/pricing.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#e65100,#f57c00); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1.1rem; opacity:.9; }
.pricing-wrap  { max-width:1000px; margin:0 auto; padding:48px 20px 80px; }
.pricing-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:28px; margin-bottom:48px; }
.pricing-card  { background:#fff; border:2px solid #e3e8f0; border-radius:14px; padding:28px 24px; text-align:center; position:relative; transition:box-shadow .2s; }
.pricing-card:hover { box-shadow:0 8px 32px rgba(0,0,0,.1); }
.pricing-card.popular { border-color:#f57c00; }
.popular-badge { position:absolute; top:-14px; left:50%; transform:translateX(-50%); background:#f57c00; color:#fff; padding:4px 18px; border-radius:99px; font-size:.78rem; font-weight:700; white-space:nowrap; }
.pricing-card .plan-icon { font-size:2.8rem; margin-bottom:10px; }
.pricing-card h3 { font-size:1.2rem; font-weight:800; color:#333; margin-bottom:6px; }
.pricing-card .price { font-size:2.4rem; font-weight:900; color:#e65100; margin:10px 0; }
.pricing-card .price span { font-size:1rem; font-weight:500; color:#888; }
.pricing-card .original { text-decoration:line-through; color:#aaa; font-size:.85rem; margin-bottom:6px; }
.pricing-card .slot-badge { background:#fff3e0; color:#e65100; border:1px solid #ffcc80; border-radius:6px; padding:5px 12px; font-size:.82rem; font-weight:700; display:inline-block; margin-bottom:14px; }
.pricing-card .slot-badge.sold-out { background:#ffebee; color:#c62828; border-color:#ef9a9a; }
.pricing-card ul { text-align:left; padding-left:0; list-style:none; margin:14px 0 20px; }
.pricing-card ul li { padding:5px 0; font-size:.88rem; color:#555; }
.pricing-card ul li::before { content:'✓ '; color:#43a047; font-weight:700; }
.btn-pricing { display:inline-block; background:#e65100; color:#fff; padding:11px 26px; border-radius:8px; font-weight:700; font-size:.95rem; text-decoration:none; }
.btn-pricing:hover { background:#f57c00; color:#fff; }
.btn-pricing.disabled { background:#ccc; pointer-events:none; }
.btn-coming-soon { background:#9e9e9e; color:#fff; padding:11px 26px; border-radius:8px; font-weight:700; font-size:.95rem; border:none; cursor:not-allowed; }
.faq-section   { max-width:860px; margin:0 auto 60px; padding:0 20px; }
.faq-section h2 { font-size:1.3rem; font-weight:700; color:#e65100; margin:0 0 24px; border-left:4px solid #e65100; padding-left:12px; }
.faq-item      { border-bottom:1px solid #eee; padding:14px 0; }
.faq-item h3   { font-size:.95rem; font-weight:700; color:#333; margin-bottom:6px; }
.faq-item p    { font-size:.88rem; color:#555; line-height:1.7; margin:0; }
</style>

<div class="legal-hero">
    <h1>💰 Pricing</h1>
    <p>Simple, transparent pricing. No hidden fees. Pay once, earn forever.</p>
</div>

<div class="pricing-wrap">
    <div class="pricing-grid">

        <!-- Reporter Blue Tick -->
        <div class="pricing-card popular">
            <div class="popular-badge">🔥 Most Popular</div>
            <div class="plan-icon">📰</div>
            <h3>Reporter Blue Tick</h3>
            <?php if ($reporterFreeRemaining > 0): ?>
            <div class="original">₹99</div>
            <div class="price">FREE <span>/ lifetime</span></div>
            <div class="slot-badge">🎯 <?= $reporterFreeRemaining ?> free slot<?= $reporterFreeRemaining !== 1 ? 's' : '' ?> remaining</div>
            <?php else: ?>
            <div class="price">₹99 <span>/ lifetime</span></div>
            <div class="slot-badge sold-out">✗ Free slots exhausted</div>
            <?php endif; ?>
            <ul>
                <li>Verified journalist identity</li>
                <li>Blue tick badge on all articles</li>
                <li>Increased article credibility</li>
                <li>Access to reporter earnings</li>
                <li>Priority content distribution</li>
                <li>Lifetime — one-time payment</li>
            </ul>
            <a href="<?= SITE_URL ?>/reporter/verify" class="btn-pricing">Get Verified →</a>
        </div>

        <!-- Agency Blue Tick -->
        <div class="pricing-card">
            <div class="plan-icon">🏢</div>
            <h3>Agency Blue Tick</h3>
            <?php if ($agencyFreeRemaining > 0): ?>
            <div class="original">₹299</div>
            <div class="price">FREE <span>/ lifetime</span></div>
            <div class="slot-badge">🎯 <?= $agencyFreeRemaining ?> free slot<?= $agencyFreeRemaining !== 1 ? 's' : '' ?> remaining</div>
            <?php else: ?>
            <div class="price">₹299 <span>/ lifetime</span></div>
            <div class="slot-badge sold-out">✗ Free slots exhausted</div>
            <?php endif; ?>
            <ul>
                <li>Verified media agency badge</li>
                <li>Manage up to 50 reporters</li>
                <li>40% revenue share from network</li>
                <li>Agency dashboard &amp; analytics</li>
                <li>Priority support</li>
                <li>Lifetime — one-time payment</li>
            </ul>
            <a href="<?= SITE_URL ?>/reporter/verify" class="btn-pricing">Get Agency Verified →</a>
        </div>

        <!-- Agency Reporter Assignment -->
        <div class="pricing-card">
            <div class="plan-icon">👤</div>
            <h3>Reporter Tick Assignment</h3>
            <div class="price">₹49 <span>/ reporter</span></div>
            <div style="height:32px"></div>
            <ul>
                <li>Agency assigns verified tick to reporter</li>
                <li>Reporter gets agency-verified badge</li>
                <li>Managed under agency account</li>
                <li>Revenue split via agency dashboard</li>
                <li>One-time per reporter</li>
            </ul>
            <a href="<?= SITE_URL ?>/reporter/verify" class="btn-pricing">Assign Reporter →</a>
        </div>

        <!-- Premium Subscription -->
        <div class="pricing-card">
            <div class="plan-icon">⭐</div>
            <h3>Reader Premium</h3>
            <div class="price">₹29 <span>/ month</span></div>
            <div class="slot-badge" style="background:#f3e5f5;color:#7b1fa2;border-color:#ce93d8;">🚀 Coming Soon</div>
            <ul>
                <li>Ad-free reading experience</li>
                <li>Exclusive premium content</li>
                <li>Early access to features</li>
                <li>Priority customer support</li>
                <li>Monthly — cancel anytime</li>
            </ul>
            <button class="btn-coming-soon" disabled>Coming Soon</button>
        </div>

    </div>
</div>

<div class="faq-section">
    <h2>Frequently Asked Questions</h2>

    <div class="faq-item">
        <h3>What happens after the free slots are exhausted?</h3>
        <p>Once the early-bird free slots are claimed, the standard one-time pricing applies (₹99 for reporters, ₹299 for agencies). The slot counter above is updated in real time.</p>
    </div>
    <div class="faq-item">
        <h3>Is the Blue Tick fee refundable?</h3>
        <p>Once approved, the fee is non-refundable. If your application is pending and you cancel within 24 hours, a full refund is provided. See our <a href="<?= SITE_URL ?>/legal/refund.php">Refund Policy</a> for details.</p>
    </div>
    <div class="faq-item">
        <h3>What payment methods are accepted?</h3>
        <p>We accept all major Indian payment methods via Razorpay: UPI, debit/credit cards, net banking, and wallets.</p>
    </div>
    <div class="faq-item">
        <h3>Are there any recurring charges?</h3>
        <p>Blue Tick and reporter assignment fees are one-time lifetime payments. Only the Premium Subscription (coming soon) is a recurring monthly charge.</p>
    </div>
    <div class="faq-item">
        <h3>How do I contact support for payment issues?</h3>
        <p>Email <a href="mailto:refunds@newsxpresslive.com">refunds@newsxpresslive.com</a> or visit our <a href="<?= SITE_URL ?>/legal/contact.php">Contact page</a>.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
