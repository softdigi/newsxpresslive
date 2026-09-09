<?php
// ============================================================
// FIXED: dashboard.php
// BUGS FIXED:
//   1. SELECT COUNT(*) FROM users → admin_users (fatal crash)
//   2. display_errors ON removed (security risk)
//   3. Dual CSRF systems merged — uses csrf_token() from csrf.php
//   4. Viral form uses POST (was submitting to JS fetch — OK,
//      but CSRF token was from $_SESSION['csrf'] not csrf_token())
//   5. stopBoost() now sends proper CSRF header
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

// Quick stats — FIXED: 'users' → 'admin_users'
$totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
$totalNews    = (int)$pdo->query("SELECT COUNT(*) FROM news")->fetchColumn();
$breakingCnt  = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE is_breaking = 1")->fetchColumn();
$activeBoosts = (int)$pdo->query("SELECT COUNT(*) FROM viral_boosts WHERE status = 'active'")->fetchColumn();
?>

<main class="dashboard">

  <!-- TOP STATS -->
  <section class="stats-grid">
    <div class="stat-card">
      <div class="stat-title">Users</div>
      <div class="stat-value"><?= number_format($totalUsers) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-title">News</div>
      <div class="stat-value"><?= number_format($totalNews) ?></div>
    </div>
    <div class="stat-card danger">
      <div class="stat-title">Breaking</div>
      <div class="stat-value"><?= number_format($breakingCnt) ?></div>
    </div>
    <div class="stat-card accent">
      <div class="stat-title">Active Boosts</div>
      <div class="stat-value"><?= number_format($activeBoosts) ?></div>
    </div>
  </section>

  <?php if (in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])): ?>

  <!-- QUICK ACTIONS -->
  <section class="card">
    <h3>⚡ Quick Actions</h3>
    <a href="users/create.php" class="btn-primary">➕ Create New Reporter</a>
  </section>

  <!-- VIRAL ENGINE -->
  <section class="card">
    <h2>🔥 Viral Engine</h2>
    <form id="viralForm">
      <!-- FIXED: use csrf_token() consistently -->
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="form-grid">
        <input type="number" name="news_id" placeholder="News ID" required min="1">
        <select name="boost_level">
          <option value="low">Low (2×)</option>
          <option value="medium">Medium (5×)</option>
          <option value="high">High (10×)</option>
          <option value="mega">Mega (50×)</option>
        </select>
        <select name="duration_hours">
          <option value="24">24 Hours</option>
          <option value="48">48 Hours</option>
          <option value="72">72 Hours</option>
          <option value="168">7 Days</option>
        </select>
        <label class="check">
          <input type="checkbox" name="pin_to_top" value="1"> Pin to Top
        </label>
        <button type="submit" class="btn-primary">MAKE VIRAL</button>
      </div>
    </form>
  </section>

  <!-- ACTIVE BOOSTS -->
  <section class="card">
    <h2>🚀 Active Viral Boosts</h2>
    <table class="table">
      <thead>
        <tr>
          <th>News</th><th>Level</th><th>Ends In</th><th>Action</th>
        </tr>
      </thead>
      <tbody id="boostTable"></tbody>
    </table>
  </section>

  <?php endif; ?>

</main>

<script>
// FIXED: CSRF token for fetch calls — use PHP-generated token
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

async function loadBoosts() {
  try {
    const res  = await fetch('actions/viral_boosts_active.php');
    if (!res.ok) return;
    const json = await res.json();
    const tbody = document.getElementById('boostTable');
    tbody.innerHTML = '';
    if (!json.data || !json.data.boosts) return;
    json.data.boosts.forEach(b => {
      // FIXED: escape output — b.news_title could contain HTML
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escHtml(b.news_title)}</td>
        <td><span class="badge">${escHtml(b.boost_level)}</span></td>
        <td>${parseInt(b.hours_remaining, 10)}h</td>
        <td>
          <button onclick="stopBoost(${parseInt(b.id, 10)})" class="btn-danger">Stop</button>
        </td>`;
      tbody.appendChild(tr);
    });
  } catch (e) {
    console.error('loadBoosts error', e);
  }
}

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function stopBoost(id) {
  if (!confirm('Stop this boost?')) return;
  await fetch('actions/viral_boost_control.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ boost_id: id, action: 'stop', csrf: CSRF_TOKEN })
  });
  loadBoosts();
}

document.getElementById('viralForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  data.csrf = CSRF_TOKEN; // ensure fresh token
  await fetch('actions/viral_boost_create.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  });
  e.target.reset();
  loadBoosts();
});

loadBoosts();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
