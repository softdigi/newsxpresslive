<?php
/**
 * admin_panel/rewards/dashboard.php
 *
 * Reward System Dashboard — admin + super_admin.
 * Auto-refresh every 30s. Charts via Chart.js CDN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../../helpers/reward_config.php';

if (!can(['admin', 'super_admin'])) {
    http_response_code(403);
    die('<h2>Access Denied.</h2>');
}

// ── Redis ─────────────────────────────────────────────────────────────────────
$redis = null;
try {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int)(getenv('REDIS_PORT') ?: 6379));
} catch (Throwable) { $redis = null; }
RewardConfig::init($pdo, $redis);

// ── Stat Card 1: Today spent ──────────────────────────────────────────────────
$today = date('Y-m-d');
$month = date('Y-m');
$dailyBudget   = (float)RewardConfig::get('daily_reward_budget', 2000);
$monthlyBudget = (float)RewardConfig::get('monthly_reward_budget', 20000);

$dailySpent = 0.0;
$monthlySpent = 0.0;
if ($redis) {
    try {
        $dailySpent   = (float)($redis->get("reward_budget:daily:{$today}") ?: 0);
        $monthlySpent = (float)($redis->get("reward_budget:monthly:{$month}") ?: 0);
    } catch (Throwable) {}
}
if ($dailySpent == 0.0) {
    $row = $pdo->prepare("SELECT COALESCE(SUM(amount_spent),0) FROM reward_budget_tracking WHERE period_type='daily' AND period_key=?");
    $row->execute([$today]);
    $dailySpent = (float)$row->fetchColumn();
}
if ($monthlySpent == 0.0) {
    $row = $pdo->prepare("SELECT COALESCE(SUM(amount_spent),0) FROM reward_budget_tracking WHERE period_type='monthly' AND period_key=?");
    $row->execute([$month]);
    $monthlySpent = (float)$row->fetchColumn();
}

// ── Stat Card 2: Total all time ───────────────────────────────────────────────
$totalAllTime = (float)$pdo->query(
    "SELECT COALESCE(SUM(balance + total_withdrawn), 0) FROM inr_wallets"
)->fetchColumn();

// ── Stat Card 3: Fraud blocked this month ────────────────────────────────────
$fraudBlocked = (int)$pdo->prepare(
    "SELECT COUNT(*) FROM referrals WHERE status IN ('blocked','flagged') AND created_at >= ?"
)->execute([$month . '-01']) ? $pdo->query(
    "SELECT COUNT(*) FROM referrals WHERE status IN ('blocked','flagged') AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())"
)->fetchColumn() : 0;
$stmtFraud = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE status IN ('blocked','flagged') AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())");
$stmtFraud->execute();
$fraudBlocked = (int)$stmtFraud->fetchColumn();

// ── Row 2 Stat Cards ──────────────────────────────────────────────────────────
$newUsersToday = (int)$pdo->prepare(
    "SELECT COUNT(*) FROM user_reward_progress WHERE DATE(created_at) = CURDATE()"
)->execute() ? 0 : 0;
$stmtNewUsers = $pdo->prepare("SELECT COUNT(*) FROM user_reward_progress WHERE DATE(created_at) = CURDATE()");
$stmtNewUsers->execute();
$newUsersToday = (int)$stmtNewUsers->fetchColumn();

$milestone7Done = (int)$pdo->query(
    "SELECT COUNT(*) FROM user_reward_progress WHERE milestone_7day_done=1 AND DATE(milestone_7day_date)=CURDATE()"
)->fetchColumn();

$milestone30Done = (int)$pdo->query(
    "SELECT COUNT(*) FROM user_reward_progress WHERE milestone_30day_done=1 AND DATE(milestone_30day_date)=CURDATE()"
)->fetchColumn();

$mauCount = (int)$pdo->query(
    "SELECT COUNT(DISTINCT user_id) FROM user_reward_progress WHERE last_active_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)->fetchColumn();

// ── Chart 1: Daily rewards last 30 days ──────────────────────────────────────
$chartStmt = $pdo->query(
    "SELECT period_key AS day, COALESCE(SUM(amount_spent),0) AS spent
     FROM reward_budget_tracking
     WHERE period_type='daily' AND period_key >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY period_key ORDER BY period_key ASC"
);
$chartRows    = $chartStmt->fetchAll();
$chartLabels  = [];
$chartData    = [];
// Fill all 30 days (including zeros)
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d M', strtotime($day));
    $chartData[$day] = 0.0;
}
foreach ($chartRows as $r) {
    if (isset($chartData[$r['day']])) {
        $chartData[$r['day']] = (float)$r['spent'];
    }
}
$chartValues = array_values($chartData);

// ── Chart 2: Funnel ───────────────────────────────────────────────────────────
$totalUsers     = (int)$pdo->query("SELECT COUNT(*) FROM user_reward_progress")->fetchColumn();
$total7Done     = (int)$pdo->query("SELECT COUNT(*) FROM user_reward_progress WHERE milestone_7day_done=1")->fetchColumn();
$total30Done    = (int)$pdo->query("SELECT COUNT(*) FROM user_reward_progress WHERE milestone_30day_done=1")->fetchColumn();
$total90Done    = (int)$pdo->query("SELECT COUNT(*) FROM user_reward_progress WHERE milestone_90day_done=1")->fetchColumn();

// ── Recent Transactions ───────────────────────────────────────────────────────
$recentTx = $pdo->query(
    "SELECT user_id, transaction_type, amount, wallet_type, status, created_at
     FROM reward_transactions
     ORDER BY created_at DESC LIMIT 20"
)->fetchAll();

// ── Pending Withdrawals ───────────────────────────────────────────────────────
$pendingWithdrawals = $pdo->query(
    "SELECT rw.id, rw.user_uid, rw.amount_inr, rw.upi_id, rw.requested_at, rw.status
     FROM reward_withdrawals rw
     WHERE rw.status IN ('pending','processing')
     ORDER BY rw.requested_at ASC
     LIMIT 10"
)->fetchAll();

// ── Flagged Referrals ─────────────────────────────────────────────────────────
$flaggedReferrals = $pdo->query(
    "SELECT rf.id, rf.referral_id, rf.referee_uid, rf.fraud_score, rf.fraud_flags, rf.status, rf.created_at
     FROM referral_fraud_flags rf
     WHERE rf.status = 'pending_review'
     ORDER BY rf.created_at DESC
     LIMIT 10"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<meta http-equiv="refresh" content="30">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
.stats-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
.stat-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.stat-card .s-label { font-size:12px; color:#888; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
.stat-card .s-value { font-size:26px; font-weight:700; color:#1a1a1a; }
.stat-card .s-sub   { font-size:12px; color:#bbb; margin-top:4px; }
.stat-card.danger .s-value   { color:#E50914; }
.stat-card.warning .s-value  { color:#FF9800; }
.stat-card.success .s-value  { color:#4CAF50; }
.stat-card.info .s-value     { color:#1976D2; }

.charts-row { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:20px; }
.chart-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.chart-card h3 { margin:0 0 16px; font-size:15px; color:#333; }

.data-table { width:100%; border-collapse:collapse; }
.data-table th { background:#f9f9f9; padding:10px 14px; text-align:left; font-size:12px; color:#666; border-bottom:2px solid #eee; }
.data-table td { padding:9px 14px; font-size:13px; border-bottom:1px solid #f5f5f5; }
.data-table tr:hover td { background:#fafafa; }

.tables-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
.table-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.table-card h3 { margin:0 0 14px; font-size:14px; color:#333; }

.badge-pill { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge-completed  { background:#e8f5e9; color:#2E7D32; }
.badge-pending    { background:#fff8e1; color:#E65100; }
.badge-processing { background:#e3f2fd; color:#1565C0; }
.badge-flagged    { background:#fce4ec; color:#c62828; }
.badge-blocked    { background:#f3e5f5; color:#6A1B9A; }

.btn-action { padding:4px 10px; border-radius:4px; font-size:12px; border:none; cursor:pointer; }
.btn-review  { background:#1976D2; color:#fff; }
.btn-process { background:#4CAF50; color:#fff; }

.funnel-bar-wrap { padding:4px 0; }
.funnel-row { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.funnel-row .f-label { width:130px; font-size:12px; color:#555; }
.funnel-bar { height:20px; border-radius:4px; transition:width .6s; }
.funnel-count { font-size:12px; color:#888; margin-left:6px; }
.funnel-pct { font-size:11px; color:#bbb; }
</style>

<div style="padding:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
    <h1 style="margin:0;font-size:22px;">📊 Reward Dashboard</h1>
    <span style="font-size:12px;color:#aaa">Auto-refresh: 30s &nbsp;|&nbsp; <?= date('d M Y H:i:s') ?></span>
  </div>

  <!-- Row 1 Stats -->
  <div class="stats-grid-4">
    <div class="stat-card <?= $dailySpent / max($dailyBudget, 1) >= 0.95 ? 'danger' : ($dailySpent / max($dailyBudget, 1) >= 0.8 ? 'warning' : '') ?>">
      <div class="s-label">Today Spent</div>
      <div class="s-value">₹<?= number_format($dailySpent, 0) ?></div>
      <div class="s-sub">Budget: ₹<?= number_format($dailyBudget, 0) ?>
        (<?= $dailyBudget > 0 ? round($dailySpent/$dailyBudget*100, 1) : 0 ?>%)
      </div>
    </div>
    <div class="stat-card <?= $monthlySpent / max($monthlyBudget, 1) >= 0.95 ? 'danger' : ($monthlySpent / max($monthlyBudget, 1) >= 0.8 ? 'warning' : '') ?>">
      <div class="s-label">Month Spent</div>
      <div class="s-value">₹<?= number_format($monthlySpent, 0) ?></div>
      <div class="s-sub">Budget: ₹<?= number_format($monthlyBudget, 0) ?>
        (<?= $monthlyBudget > 0 ? round($monthlySpent/$monthlyBudget*100, 1) : 0 ?>%)
      </div>
    </div>
    <div class="stat-card success">
      <div class="s-label">Total All Time</div>
      <div class="s-value">₹<?= number_format($totalAllTime, 0) ?></div>
      <div class="s-sub">INR wallets earned</div>
    </div>
    <div class="stat-card danger">
      <div class="s-label">Fraud Blocked</div>
      <div class="s-value"><?= number_format($fraudBlocked) ?></div>
      <div class="s-sub">This month</div>
    </div>
  </div>

  <!-- Row 2 Stats -->
  <div class="stats-grid-4" style="margin-bottom:24px">
    <div class="stat-card info">
      <div class="s-label">New Users Today</div>
      <div class="s-value">+<?= number_format($newUsersToday) ?></div>
      <div class="s-sub">Reward progress created</div>
    </div>
    <div class="stat-card success">
      <div class="s-label">7-Day Completed</div>
      <div class="s-value"><?= number_format($milestone7Done) ?></div>
      <div class="s-sub">Today</div>
    </div>
    <div class="stat-card success">
      <div class="s-label">30-Day Completed</div>
      <div class="s-value"><?= number_format($milestone30Done) ?></div>
      <div class="s-sub">Today</div>
    </div>
    <div class="stat-card">
      <div class="s-label">Active MAU</div>
      <div class="s-value"><?= number_format($mauCount) ?></div>
      <div class="s-sub">Last 30 days</div>
    </div>
  </div>

  <!-- Charts -->
  <div class="charts-row">
    <div class="chart-card">
      <h3>📈 Daily Rewards Paid — Last 30 Days</h3>
      <canvas id="rewardsChart" height="100"></canvas>
    </div>
    <div class="chart-card">
      <h3>🔽 Milestone Funnel</h3>
      <?php
      $funnelData = [
          ['Registered',    $totalUsers],
          ['7-Day Done',    $total7Done],
          ['30-Day Done',   $total30Done],
          ['90-Day Done',   $total90Done],
      ];
      $maxFunnel = max(1, $totalUsers);
      $colors = ['#1976D2', '#4CAF50', '#FF9800', '#E50914'];
      ?>
      <div class="funnel-bar-wrap">
        <?php foreach ($funnelData as $i => [$label, $count]): ?>
        <?php $pct = $maxFunnel > 0 ? round($count / $maxFunnel * 100, 1) : 0; ?>
        <div class="funnel-row">
          <span class="f-label"><?= $label ?></span>
          <div class="funnel-bar" style="width:<?= max(4, $pct) ?>%;background:<?= $colors[$i] ?>"></div>
          <span class="funnel-count"><?= number_format($count) ?></span>
          <span class="funnel-pct"><?= $pct ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:16px;font-size:12px;color:#888">
        <?php if ($totalUsers > 0): ?>
        7-day rate: <?= round($total7Done/$totalUsers*100, 1) ?>% &nbsp;|&nbsp;
        30-day rate: <?= round($total30Done/max(1,$total7Done)*100, 1) ?>% of 7-day completers
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tables Row -->
  <div class="tables-row">
    <!-- Recent Transactions -->
    <div class="table-card">
      <h3>💸 Recent Transactions</h3>
      <table class="data-table">
        <thead>
          <tr><th>User</th><th>Type</th><th>Amount</th><th>Time</th></tr>
        </thead>
        <tbody>
        <?php if (empty($recentTx)): ?>
          <tr><td colspan="4" style="text-align:center;color:#bbb;padding:20px">No transactions yet.</td></tr>
        <?php else: ?>
          <?php foreach ($recentTx as $tx): ?>
          <tr>
            <td style="font-size:11px;max-width:80px;overflow:hidden;text-overflow:ellipsis">
              <?= htmlspecialchars(substr($tx['user_id'], 0, 10), ENT_QUOTES) ?>…
            </td>
            <td><span style="font-size:11px;background:#f5f5f5;padding:2px 7px;border-radius:4px">
              <?= htmlspecialchars(str_replace('_', ' ', $tx['transaction_type']), ENT_QUOTES) ?>
            </span></td>
            <td style="font-weight:600;color:<?= $tx['wallet_type']==='inr' ? '#2E7D32' : '#1976D2' ?>">
              <?= $tx['wallet_type'] === 'inr' ? '₹' : '🪙' ?><?= number_format((float)$tx['amount'], 2) ?>
            </td>
            <td style="font-size:11px;color:#999"><?= date('d M H:i', strtotime($tx['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pending Withdrawals -->
    <div class="table-card">
      <h3>💳 Pending Withdrawals
        <a href="withdrawals.php" style="font-size:11px;color:#1976D2;float:right;text-decoration:none">View All →</a>
      </h3>
      <table class="data-table">
        <thead>
          <tr><th>User</th><th>Amount</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php if (empty($pendingWithdrawals)): ?>
          <tr><td colspan="4" style="text-align:center;color:#bbb;padding:20px">No pending withdrawals.</td></tr>
        <?php else: ?>
          <?php foreach ($pendingWithdrawals as $w): ?>
          <tr>
            <td style="font-size:12px"><?= htmlspecialchars(substr($w['user_uid'], 0, 14), ENT_QUOTES) ?>…</td>
            <td style="font-weight:600">₹<?= number_format((float)$w['amount_inr'], 2) ?></td>
            <td><span class="badge-pill badge-<?= htmlspecialchars($w['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($w['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td>
              <a href="withdrawals.php?highlight=<?= (int)$w['id'] ?>" class="btn-action btn-process">Process</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Flagged Referrals -->
    <div class="table-card">
      <h3>🚩 Flagged Referrals</h3>
      <table class="data-table">
        <thead>
          <tr><th>Referral</th><th>Score</th><th>Flags</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php if (empty($flaggedReferrals)): ?>
          <tr><td colspan="4" style="text-align:center;color:#bbb;padding:20px">No flagged referrals.</td></tr>
        <?php else: ?>
          <?php foreach ($flaggedReferrals as $rf): ?>
          <?php $flags = json_decode($rf['fraud_flags'] ?? '[]', true) ?? []; ?>
          <tr>
            <td style="font-size:11px">#<?= (int)$rf['referral_id'] ?><br>
              <span style="color:#999"><?= htmlspecialchars(substr($rf['referee_uid'], 0, 10), ENT_QUOTES) ?>…</span>
            </td>
            <td><span style="font-weight:600;color:<?= (int)$rf['fraud_score'] >= 70 ? '#f44336' : '#FF9800' ?>">
              <?= (int)$rf['fraud_score'] ?>
            </span></td>
            <td style="font-size:11px;color:#666">
              <?= htmlspecialchars(implode(', ', array_slice($flags, 0, 2)), ENT_QUOTES) ?>
              <?= count($flags) > 2 ? '…' : '' ?>
            </td>
            <td>
              <a href="withdrawals.php?tab=fraud&referral=<?= (int)$rf['referral_id'] ?>"
                 class="btn-action btn-review">Review</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
const ctx = document.getElementById('rewardsChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Daily Rewards (₹)',
      data: <?= json_encode($chartValues) ?>,
      fill: true,
      backgroundColor: 'rgba(229,9,20,0.08)',
      borderColor: '#E50914',
      borderWidth: 2,
      pointRadius: 3,
      tension: 0.3,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => '₹' + Number(ctx.raw).toFixed(2)
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: v => '₹' + v.toLocaleString()
        }
      }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
