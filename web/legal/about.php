<?php
/**
 * web/legal/about.php
 * About Us — NewsXpressLive
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

$seoMeta = [
    'title'       => 'About Us',
    'description' => 'Learn about NewsXpressLive — India\'s hyperlocal news platform. Bharat ki awaaz, har gaon ki khabar. Founded in 2024.',
    'url'         => SITE_URL . '/legal/about.php',
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.legal-hero { background: linear-gradient(135deg,#0d47a1,#1565c0); color:#fff; padding:60px 20px; text-align:center; }
.legal-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:8px; }
.legal-hero p  { font-size:1.1rem; opacity:.9; max-width:600px; margin:0 auto; }
.legal-body    { max-width:900px; margin:0 auto; padding:40px 20px 80px; }
.legal-body h2 { font-size:1.3rem; font-weight:700; color:#0d47a1; margin:36px 0 12px; border-left:4px solid #0d47a1; padding-left:12px; }
.legal-body p,
.legal-body li { font-size:.97rem; line-height:1.8; color:#333; }
.legal-body ul  { padding-left:22px; margin:8px 0; }
.feature-grid   { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; margin:20px 0; }
.feature-card   { background:#f0f4ff; border-radius:10px; padding:20px; }
.feature-card .icon { font-size:2rem; margin-bottom:8px; }
.feature-card h3    { font-size:1rem; font-weight:700; color:#0d47a1; margin-bottom:4px; }
.feature-card p     { font-size:.88rem; color:#555; margin:0; }
.team-grid      { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:20px; margin:20px 0; }
.team-card      { background:#fff; border:1px solid #e3e8f0; border-radius:10px; padding:24px 16px; text-align:center; }
.team-card .avatar { width:64px; height:64px; border-radius:50%; background:#0d47a1; color:#fff; font-size:1.5rem; display:flex; align-items:center; justify-content:center; margin:0 auto 12px; }
.team-card h3   { font-size:.97rem; font-weight:700; margin-bottom:2px; }
.team-card p    { font-size:.82rem; color:#666; margin:0; }
.contact-box    { background:#e8f5e9; border-left:4px solid #43a047; padding:20px; border-radius:0 8px 8px 0; margin:20px 0; }
</style>

<div class="legal-hero">
    <h1>🇮🇳 About NewsXpressLive</h1>
    <p>"Bharat ki awaaz, har gaon ki khabar"</p>
</div>

<div class="legal-body">

    <h2>Our Story</h2>
    <p>NewsXpressLive was founded in 2024 with a singular mission: to give every village, town, and city in India its own voice. We believe that real news doesn't just happen in metros — it happens in your neighbourhood, your block, your market. Our platform empowers hyperlocal reporters and media agencies to bring authentic ground-level journalism to millions.</p>

    <h2>Mission &amp; Vision</h2>
    <p><strong>Mission:</strong> "Bharat ki awaaz, har gaon ki khabar" — Be the voice of India, one village at a time.</p>
    <p><strong>Vision:</strong> Build India's largest network of verified local journalists who earn a sustainable livelihood through credible, impactful reporting.</p>

    <h2>Platform Features</h2>
    <div class="feature-grid">
        <div class="feature-card"><div class="icon">📰</div><h3>Hyperlocal News</h3><p>Breaking news from your district, village, and neighbourhood — in your language.</p></div>
        <div class="feature-card"><div class="icon">✅</div><h3>Verified Reporters</h3><p>Blue-tick verification for journalists and media agencies — building trust at scale.</p></div>
        <div class="feature-card"><div class="icon">💰</div><h3>Reporter Earnings</h3><p>Reporters earn from views, subscriptions, and ad revenue share — a fair economy for journalists.</p></div>
        <div class="feature-card"><div class="icon">📡</div><h3>Live Streaming</h3><p>Report live from the field and reach your audience in real time.</p></div>
        <div class="feature-card"><div class="icon">🎥</div><h3>News Reels</h3><p>Short-form video news for a mobile-first audience.</p></div>
        <div class="feature-card"><div class="icon">🌐</div><h3>Multi-language</h3><p>Hindi, English, Marathi, Tamil, Telugu, Kannada, Bengali, Urdu and more.</p></div>
        <div class="feature-card"><div class="icon">🏘️</div><h3>Agency Network</h3><p>Media agencies can onboard and manage their reporter teams seamlessly.</p></div>
        <div class="feature-card"><div class="icon">📊</div><h3>Credibility Score</h3><p>AI-powered credibility scoring to surface accurate, reliable reporting.</p></div>
    </div>

    <h2>Company Information</h2>
    <ul>
        <li><strong>Platform Name:</strong> NewsXpressLive</li>
        <li><strong>Founded:</strong> 2024</li>
        <li><strong>Country:</strong> India</li>
        <li><strong>Registered Address:</strong> [Registered Office Address, City, State, PIN] <em>(placeholder — update before going live)</em></li>
        <li><strong>Type:</strong> Digital News Platform &amp; Media Technology Company</li>
    </ul>

    <h2>Our Team</h2>
    <div class="team-grid">
        <div class="team-card"><div class="avatar">👤</div><h3>Founder &amp; CEO</h3><p>[Name Placeholder]</p></div>
        <div class="team-card"><div class="avatar">👤</div><h3>CTO</h3><p>[Name Placeholder]</p></div>
        <div class="team-card"><div class="avatar">👤</div><h3>Head of Editorial</h3><p>[Name Placeholder]</p></div>
        <div class="team-card"><div class="avatar">👤</div><h3>Head of Operations</h3><p>[Name Placeholder]</p></div>
    </div>

    <h2>Contact Us</h2>
    <div class="contact-box">
        <p>📧 <strong>General:</strong> <a href="mailto:hello@newsxpresslive.com">hello@newsxpresslive.com</a></p>
        <p>📧 <strong>Support:</strong> <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></p>
        <p>📧 <strong>Press:</strong> <a href="mailto:press@newsxpresslive.com">press@newsxpresslive.com</a></p>
        <p>🌐 <a href="<?= SITE_URL ?>">www.newsxpresslive.com</a></p>
    </div>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
