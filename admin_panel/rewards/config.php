<?php
/**
 * admin_panel/rewards/config.php
 *
 * Reward System Config Panel — super_admin only.
 * 6 tabs: India Rewards, Global Rewards, Activity Rules,
 *         Budget & Limits, Fraud Control, Features
 * Budget status bar + Impact Calculator (AJAX simulate)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../../helpers/reward_config.php';

// Super admin only
if ($_SESSION['admin']['role'] !== 'super_admin') {
    http_response_code(403);
    die('<h2>Access Denied — Super Admin only.</h2>');
}

$adminUid = (string)$_SESSION['admin']['id'];

// ── Redis (optional) ──────────────────────────────────────────────────────────
$redis = null;
try {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: '127.0.0.1',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );
} catch (Throwable) {
    $redis = null;
}
RewardConfig::init($pdo, $redis);

// ── Config definitions with validation ranges ─────────────────────────────────
$configMeta = [
    // India Rewards
    'reward_7day_inr'            => ['label' => '7-Day Milestone (₹)',          'group' => 'india',    'min' => 0,   'max' => 500,   'step' => '0.50'],
    'reward_30day_inr'           => ['label' => '30-Day Milestone (₹)',         'group' => 'india',    'min' => 0,   'max' => 1000,  'step' => '0.50'],
    'reward_90day_inr'           => ['label' => '90-Day Milestone (₹)',         'group' => 'india',    'min' => 0,   'max' => 5000,  'step' => '1.00'],
    'reward_article_inr'         => ['label' => 'Per Article (₹)',              'group' => 'india',    'min' => 0,   'max' => 50,    'step' => '0.50'],
    'reward_article_monthly_cap' => ['label' => 'Article Monthly Cap (₹)',      'group' => 'india',    'min' => 0,   'max' => 500,   'step' => '5.00'],
    'reward_referral_inr'        => ['label' => 'Referral Bonus (₹)',           'group' => 'india',    'min' => 0,   'max' => 100,   'step' => '0.50'],
    'lifetime_share_percent'     => ['label' => 'Lifetime Share %',             'group' => 'india',    'min' => 0,   'max' => 20,    'step' => '0.10'],
    'lifetime_share_months'      => ['label' => 'Lifetime Share (months)',      'group' => 'india',    'min' => 1,   'max' => 24,    'step' => '1'],
    // Global Rewards
    'reward_7day_coins'          => ['label' => '7-Day Milestone (coins)',      'group' => 'global',   'min' => 0,   'max' => 500,   'step' => '1'],
    'reward_30day_coins'         => ['label' => '30-Day Milestone (coins)',     'group' => 'global',   'min' => 0,   'max' => 1000,  'step' => '1'],
    'reward_90day_coins'         => ['label' => '90-Day Milestone (coins)',     'group' => 'global',   'min' => 0,   'max' => 5000,  'step' => '1'],
    'reward_referral_coins'      => ['label' => 'Referral Bonus (coins)',       'group' => 'global',   'min' => 0,   'max' => 200,   'step' => '1'],
    'coin_inr_value'             => ['label' => 'Coin INR Value (₹/coin)',      'group' => 'global',   'min' => 0,   'max' => 1,     'step' => '0.001'],
    // Activity Rules
    'req_7day_min_days'          => ['label' => '7-Day: Min Active Days',       'group' => 'activity', 'min' => 1,   'max' => 7,     'step' => '1'],
    'req_7day_min_articles'      => ['label' => '7-Day: Min Articles',          'group' => 'activity', 'min' => 1,   'max' => 100,   'step' => '1'],
    'req_7day_min_shares'        => ['label' => '7-Day: Min Shares',            'group' => 'activity', 'min' => 0,   'max' => 20,    'step' => '1'],
    'req_30day_min_active'       => ['label' => '30-Day: Min Active Days',      'group' => 'activity', 'min' => 5,   'max' => 30,    'step' => '1'],
    'req_30day_min_articles'     => ['label' => '30-Day: Min Articles',         'group' => 'activity', 'min' => 5,   'max' => 300,   'step' => '1'],
    'req_90day_min_active'       => ['label' => '90-Day: Min Active Days',      'group' => 'activity', 'min' => 10,  'max' => 90,    'step' => '1'],
    // Budget
    'daily_reward_budget'        => ['label' => 'Daily Budget (₹)',             'group' => 'budget',   'min' => 100, 'max' => 100000,'step' => '100'],
    'monthly_reward_budget'      => ['label' => 'Monthly Budget (₹)',           'group' => 'budget',   'min' => 1000,'max' => 1000000,'step' => '1000'],
    'per_user_monthly_cap'       => ['label' => 'Per User Monthly Cap (₹)',     'group' => 'budget',   'min' => 10,  'max' => 1000,  'step' => '5'],
    'min_withdrawal_inr'         => ['label' => 'Min Withdrawal (₹)',           'group' => 'budget',   'min' => 10,  'max' => 1000,  'step' => '10'],
    'min_withdrawal_delay_days'  => ['label' => 'Withdrawal Delay (days)',      'group' => 'budget',   'min' => 0,   'max' => 90,    'step' => '1'],
    'large_withdrawal_threshold' => ['label' => 'Large Withdrawal Threshold (₹)','group' => 'budget', 'min' => 100, 'max' => 10000, 'step' => '50'],
    // Fraud
    'fraud_block_threshold'      => ['label' => 'Block Threshold (score)',      'group' => 'fraud',    'min' => 10,  'max' => 100,   'step' => '5'],
    'fraud_flag_threshold'       => ['label' => 'Flag Threshold (score)',       'group' => 'fraud',    'min' => 5,   'max' => 90,    'step' => '5'],
    'max_installs_per_ip_daily'  => ['label' => 'Max Installs / IP / Day',      'group' => 'fraud',    'min' => 1,   'max' => 20,    'step' => '1'],
    'rapid_install_threshold'    => ['label' => 'Rapid Install / Hour',         'group' => 'fraud',    'min' => 1,   'max' => 50,    'step' => '1'],
    // Features
    'referral_system_active'     => ['label' => 'Referral System',              'group' => 'features', 'min' => 0,   'max' => 1,     'step' => '1'],
    'milestone_rewards_active'   => ['label' => 'Milestone Rewards',            'group' => 'features', 'min' => 0,   'max' => 1,     'step' => '1'],
    'article_rewards_active'     => ['label' => 'Article Rewards',              'group' => 'features', 'min' => 0,   'max' => 1,     'step' => '1'],
];

$flash = '';
$flashType = 'success';

// ── POST: save config ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $configs = $_POST['configs'] ?? [];
        $reason  = trim($_POST['reason'] ?? 'Admin config update');
        $saved   = 0;
        $errors  = [];

        foreach ($configs as $key => $rawVal) {
            if (!isset($configMeta[$key])) {
                continue;
            }
            $meta  = $configMeta[$key];
            $value = (float)$rawVal;

            // Range validation
            if ($value < $meta['min'] || $value > $meta['max']) {
                $errors[] = "{$meta['label']}: value {$value} out of range [{$meta['min']}, {$meta['max']}]";
                continue;
            }

            // Features tab: integer
            if ($meta['group'] === 'features') {
                $value = (int)$value;
            }

            $ok = RewardConfig::set($key, (string)$value, $adminUid, $reason);
            if ($ok) {
                $saved++;
            } else {
                $errors[] = "{$meta['label']}: save failed";
            }
        }

        if (empty($errors)) {
            $flash = "✅ {$saved} config(s) saved successfully.";
        } else {
            $flash     = "⚠️ {$saved} saved. Errors: " . implode('; ', $errors);
            $flashType = 'warning';
        }

        header('Location: config.php?flash=' . urlencode($flash) . '&type=' . $flashType);
        exit;
    }

    // ── AJAX: simulate impact ─────────────────────────────────────────────────
    if ($action === 'simulate') {
        header('Content-Type: application/json');
        echo json_encode(calculateConfigImpact($_POST['configs'] ?? [], $pdo));
        exit;
    }
}

// ── Flash from redirect ───────────────────────────────────────────────────────
if (isset($_GET['flash'])) {
    $flash     = htmlspecialchars($_GET['flash'], ENT_QUOTES, 'UTF-8');
    $flashType = htmlspecialchars($_GET['type'] ?? 'success', ENT_QUOTES, 'UTF-8');
}

// ── Load all current config values ───────────────────────────────────────────
$currentValues = [];
foreach (array_keys($configMeta) as $key) {
    $currentValues[$key] = RewardConfig::get($key);
}

// ── Budget status for bar ─────────────────────────────────────────────────────
$today = date('Y-m-d');
$month = date('Y-m');
$dailySpent   = 0.0;
$monthlySpent = 0.0;

if ($redis) {
    try {
        $dailySpent   = (float)($redis->get("reward_budget:daily:{$today}") ?: 0);
        $monthlySpent = (float)($redis->get("reward_budget:monthly:{$month}") ?: 0);
    } catch (Throwable) {}
}
if ($dailySpent == 0.0 || $monthlySpent == 0.0) {
    $row = $pdo->prepare(
        'SELECT period_type, SUM(amount_spent) AS total
         FROM reward_budget_tracking
         WHERE (period_type=\'daily\' AND period_key=?)
            OR (period_type=\'monthly\' AND period_key=?)
         GROUP BY period_type'
    );
    $row->execute([$today, $month]);
    while ($r = $row->fetch()) {
        if ($r['period_type'] === 'daily')   $dailySpent   = (float)$r['total'];
        if ($r['period_type'] === 'monthly') $monthlySpent = (float)$r['total'];
    }
}

$dailyBudget   = (float)RewardConfig::get('daily_reward_budget', 2000);
$monthlyBudget = (float)RewardConfig::get('monthly_reward_budget', 20000);

$dailyPct   = $dailyBudget > 0   ? min(100, round($dailySpent   / $dailyBudget   * 100, 1)) : 0;
$monthlyPct = $monthlyBudget > 0 ? min(100, round($monthlySpent / $monthlyBudget * 100, 1)) : 0;

$budgetStatus = 'ok';
if ($dailyPct >= 95 || $monthlyPct >= 95) {
    $budgetStatus = 'critical';
} elseif ($dailyPct >= 80 || $monthlyPct >= 80) {
    $budgetStatus = 'warning';
}

// ─────────────────────────────────────────────────────────────────────────────
// calculateConfigImpact() — AJAX simulate endpoint
// ─────────────────────────────────────────────────────────────────────────────
function calculateConfigImpact(array $newConfigs, PDO $pdo): array
{
    try {
        $activeUsers = (int)$pdo->query(
            "SELECT COUNT(DISTINCT user_id)
             FROM user_reward_progress
             WHERE last_active_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $monthlyTx = (int)$pdo->query(
            "SELECT COUNT(*) FROM reward_transactions
             WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
        )->fetchColumn();

        // Old monthly cost
        $oldMonthly = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount_spent),0)
             FROM reward_budget_tracking
             WHERE period_type='monthly' AND period_key='" . date('Y-m') . "'"
        )->fetchColumn();

        // Estimate new cost based on changed rewards
        $costDelta = 0.0;
        foreach ($newConfigs as $key => $val) {
            $oldVal = (float)RewardConfig::get($key, 0);
            $newVal = (float)$val;
            $diff   = $newVal - $oldVal;

            if (str_contains($key, '_inr') || $key === 'lifetime_share_percent') {
                // Each reward type: rough estimate of monthly impact
                $multiplier = match (true) {
                    str_contains($key, '7day')   => max(1, (int)($activeUsers * 0.05)),
                    str_contains($key, '30day')  => max(1, (int)($activeUsers * 0.02)),
                    str_contains($key, '90day')  => max(1, (int)($activeUsers * 0.005)),
                    str_contains($key, 'article')=> max(1, (int)($activeUsers * 0.1)),
                    str_contains($key, 'referral')=> max(1, (int)($activeUsers * 0.03)),
                    default                      => 1,
                };
                $costDelta += $diff * $multiplier;
            }
        }

        $newMonthly = max(0, $oldMonthly + $costDelta);
        $recommendation = 'No significant change.';
        if ($costDelta > 1000) {
            $recommendation = '⚠️ High cost increase — review budget limits.';
        } elseif ($costDelta > 500) {
            $recommendation = '💡 Moderate increase — monitor daily budget.';
        } elseif ($costDelta < -100) {
            $recommendation = '✅ Cost reduction — rewards will be less attractive.';
        }

        return [
            'active_users'          => $activeUsers,
            'monthly_transactions'  => $monthlyTx,
            'cost_change_monthly'   => round($costDelta, 2),
            'old_monthly_cost'      => round($oldMonthly, 2),
            'new_monthly_cost'      => round($newMonthly, 2),
            'recommendation'        => $recommendation,
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.budget-bar-wrap { background:#fff; border-radius:8px; padding:20px; margin-bottom:20px; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.budget-bar-wrap h3 { margin:0 0 15px; font-size:16px; }
.budget-row { display:flex; align-items:center; margin-bottom:10px; gap:12px; }
.budget-row label { width:200px; font-size:13px; font-weight:500; }
.budget-progress { flex:1; height:18px; background:#e0e0e0; border-radius:9px; overflow:hidden; }
.budget-progress-fill { height:100%; border-radius:9px; transition:width .4s; }
.fill-ok       { background:#4CAF50; }
.fill-warning  { background:#FF9800; }
.fill-critical { background:#f44336; }
.budget-pct { width:50px; text-align:right; font-weight:600; font-size:13px; }
.budget-val { font-size:12px; color:#888; }

.tabs-header { display:flex; gap:0; border-bottom:2px solid #e0e0e0; margin-bottom:20px; }
.tab-btn { padding:10px 18px; cursor:pointer; border:none; background:none; font-size:14px; color:#555; border-bottom:3px solid transparent; margin-bottom:-2px; }
.tab-btn.active { color:#E50914; border-bottom-color:#E50914; font-weight:600; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

.config-table { width:100%; border-collapse:collapse; }
.config-table th, .config-table td { padding:10px 12px; text-align:left; border-bottom:1px solid #f0f0f0; }
.config-table th { background:#f9f9f9; font-size:13px; color:#666; }
.config-table td.label-col { font-weight:500; font-size:14px; }
.config-table td.current-col { color:#888; font-size:13px; }
.config-table td.preview-col { color:#4CAF50; font-size:13px; font-weight:500; }
input[type=number].config-input { width:120px; padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; }
input[type=number].config-input:focus { border-color:#E50914; outline:none; }

.toggle-wrap { display:flex; align-items:center; gap:10px; }
.toggle-switch { position:relative; width:46px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:#ccc; border-radius:24px; cursor:pointer; transition:.3s; }
.toggle-slider:before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
.toggle-switch input:checked + .toggle-slider { background:#E50914; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(22px); }

.btn-save { background:#E50914; color:#fff; border:none; padding:10px 24px; border-radius:5px; font-size:14px; font-weight:600; cursor:pointer; margin-top:16px; }
.btn-save:hover { background:#c5000f; }
.btn-simulate { background:#1976D2; color:#fff; border:none; padding:8px 18px; border-radius:5px; font-size:13px; cursor:pointer; }
.btn-simulate:hover { background:#1565C0; }

.impact-box { background:#e8f5e9; border:1px solid #81C784; border-radius:6px; padding:14px; margin-top:14px; display:none; }
.impact-box.show { display:block; }
.impact-box h4 { margin:0 0 8px; color:#2E7D32; }
.impact-row { display:flex; justify-content:space-between; font-size:13px; padding:3px 0; }

.flash-msg { padding:12px 18px; border-radius:5px; margin-bottom:18px; font-size:14px; }
.flash-success { background:#e8f5e9; color:#2E7D32; border:1px solid #81C784; }
.flash-warning  { background:#fff8e1; color:#E65100; border:1px solid #FFB300; }

.reason-field { width:100%; max-width:500px; padding:8px 12px; border:1px solid #ccc; border-radius:4px; font-size:13px; margin-top:10px; }
.section-save { border-top:1px solid #f0f0f0; padding-top:14px; margin-top:10px; display:flex; align-items:center; gap:12px; }
</style>

<div style="padding:20px">
  <h1 style="margin:0 0 20px; font-size:22px;">⚙️ Reward System Config</h1>

  <?php if ($flash): ?>
  <div class="flash-msg flash-<?= $flashType ?>">
    <?= $flash ?>
  </div>
  <?php endif; ?>

  <!-- Budget Status Bar -->
  <div class="budget-bar-wrap">
    <h3>📊 Budget Status
      <?php if ($budgetStatus === 'critical'): ?>
        <span style="color:#f44336;font-size:12px;margin-left:8px">🔴 CRITICAL</span>
      <?php elseif ($budgetStatus === 'warning'): ?>
        <span style="color:#FF9800;font-size:12px;margin-left:8px">🟡 WARNING</span>
      <?php else: ?>
        <span style="color:#4CAF50;font-size:12px;margin-left:8px">🟢 Within Budget</span>
      <?php endif; ?>
    </h3>

    <div class="budget-row">
      <label>Today Spent</label>
      <div class="budget-progress">
        <div class="budget-progress-fill fill-<?= $budgetStatus === 'critical' && $dailyPct >= 95 ? 'critical' : ($dailyPct >= 80 ? 'warning' : 'ok') ?>"
             style="width:<?= $dailyPct ?>%"></div>
      </div>
      <span class="budget-pct"><?= $dailyPct ?>%</span>
      <span class="budget-val">₹<?= number_format($dailySpent, 2) ?> / ₹<?= number_format($dailyBudget, 0) ?></span>
    </div>

    <div class="budget-row">
      <label>This Month Spent</label>
      <div class="budget-progress">
        <div class="budget-progress-fill fill-<?= $budgetStatus === 'critical' && $monthlyPct >= 95 ? 'critical' : ($monthlyPct >= 80 ? 'warning' : 'ok') ?>"
             style="width:<?= $monthlyPct ?>%"></div>
      </div>
      <span class="budget-pct"><?= $monthlyPct ?>%</span>
      <span class="budget-val">₹<?= number_format($monthlySpent, 2) ?> / ₹<?= number_format($monthlyBudget, 0) ?></span>
    </div>
  </div>

  <!-- Tabs -->
  <div class="budget-bar-wrap" style="padding:24px">

    <div class="tabs-header">
      <button class="tab-btn active" onclick="switchTab('india',this)">🇮🇳 India Rewards</button>
      <button class="tab-btn" onclick="switchTab('global',this)">🌍 Global Rewards</button>
      <button class="tab-btn" onclick="switchTab('activity',this)">📅 Activity Rules</button>
      <button class="tab-btn" onclick="switchTab('budget',this)">💰 Budget &amp; Limits</button>
      <button class="tab-btn" onclick="switchTab('fraud',this)">🛡️ Fraud Control</button>
      <button class="tab-btn" onclick="switchTab('features',this)">🔧 Features</button>
    </div>

    <form method="POST" action="config.php" id="configForm">
      <input type="hidden" name="action" value="save_config">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <?php
      $tabs = [
          'india'    => 'India Rewards',
          'global'   => 'Global Rewards',
          'activity' => 'Activity Rules',
          'budget'   => 'Budget &amp; Limits',
          'fraud'    => 'Fraud Control',
          'features' => 'Features',
      ];
      foreach ($tabs as $tabId => $tabTitle):
          $keys = array_filter($configMeta, fn($m) => $m['group'] === $tabId);
      ?>
      <div class="tab-pane <?= $tabId === 'india' ? 'active' : '' ?>" id="tab-<?= $tabId ?>">

        <?php if ($tabId === 'features'): ?>
          <table class="config-table">
            <thead><tr><th>Feature</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $key => $meta): ?>
            <tr>
              <td class="label-col"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="toggle-wrap">
                  <label class="toggle-switch">
                    <input type="hidden" name="configs[<?= $key ?>]" value="0">
                    <input type="checkbox" name="configs[<?= $key ?>]" value="1"
                           <?= (int)($currentValues[$key] ?? 1) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                  </label>
                  <span style="font-size:13px;color:#555"><?= (int)($currentValues[$key] ?? 1) ? 'Enabled' : 'Disabled' ?></span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

        <?php else: ?>
          <table class="config-table">
            <thead>
              <tr>
                <th>Milestone / Setting</th>
                <th>Current Value</th>
                <th>New Value</th>
                <th>Preview</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($keys as $key => $meta): ?>
            <?php $cur = $currentValues[$key] ?? 0; ?>
            <tr>
              <td class="label-col"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="current-col"><?= number_format((float)$cur, str_contains($key,'_inr') || $key==='lifetime_share_percent' || $key==='coin_inr_value' ? 2 : 0) ?></td>
              <td>
                <input type="number"
                       name="configs[<?= $key ?>]"
                       class="config-input"
                       value="<?= htmlspecialchars((string)$cur, ENT_QUOTES, 'UTF-8') ?>"
                       min="<?= $meta['min'] ?>"
                       max="<?= $meta['max'] ?>"
                       step="<?= $meta['step'] ?>"
                       oninput="updatePreview('<?= $key ?>', this.value)">
              </td>
              <td class="preview-col" id="preview-<?= $key ?>">
                <?= number_format((float)$cur, str_contains($key,'_inr') || $key==='lifetime_share_percent' || $key==='coin_inr_value' ? 2 : 0) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <div class="section-save">
          <input type="text" name="reason" class="reason-field" placeholder="Reason for change (optional)...">
          <button type="submit" class="btn-save">💾 Save <?= $tabTitle ?></button>
          <?php if (in_array($tabId, ['india', 'global'])): ?>
          <button type="button" class="btn-simulate" onclick="simulateImpact()">📊 Simulate Change</button>
          <?php endif; ?>
        </div>

        <?php if (in_array($tabId, ['india', 'global'])): ?>
        <div class="impact-box" id="impactBox">
          <h4>📈 Budget Impact Estimate</h4>
          <div id="impactContent"></div>
        </div>
        <?php endif; ?>

      </div>
      <?php endforeach; ?>

    </form>
  </div>

</div>

<script>
function switchTab(tabId, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tabId).classList.add('active');
  btn.classList.add('active');
}

function updatePreview(key, val) {
  const el = document.getElementById('preview-' + key);
  if (el) el.textContent = parseFloat(val || 0).toFixed(2);
}

function simulateImpact() {
  const form = document.getElementById('configForm');
  const inputs = form.querySelectorAll('input[name^="configs["]');
  const data = new URLSearchParams();
  data.append('action', 'simulate');
  data.append('csrf_token', '<?= csrf_token() ?>');
  inputs.forEach(inp => {
    if (inp.type !== 'hidden' || !inp.name.includes('configs[')) {
      data.append(inp.name, inp.value);
    }
  });

  fetch('config.php', {
    method: 'POST',
    body: data,
    headers: {'Content-Type': 'application/x-www-form-urlencoded'}
  })
  .then(r => r.json())
  .then(d => {
    if (d.error) { alert('Simulate error: ' + d.error); return; }
    const box  = document.getElementById('impactBox');
    const cont = document.getElementById('impactContent');
    const sign = d.cost_change_monthly >= 0 ? '+' : '';
    cont.innerHTML = `
      <div class="impact-row"><span>Active Users</span><strong>${d.active_users.toLocaleString()}</strong></div>
      <div class="impact-row"><span>Monthly Transactions</span><strong>${d.monthly_transactions.toLocaleString()}</strong></div>
      <div class="impact-row"><span>Old Monthly Cost</span><strong>₹${d.old_monthly_cost.toLocaleString()}</strong></div>
      <div class="impact-row"><span>New Monthly Cost</span><strong>₹${d.new_monthly_cost.toLocaleString()}</strong></div>
      <div class="impact-row" style="color:${d.cost_change_monthly>500?'#f44336':'#2E7D32'}">
        <span>Budget Impact</span>
        <strong>${sign}₹${Math.abs(d.cost_change_monthly).toLocaleString()}/month</strong>
      </div>
      <div style="margin-top:8px;padding-top:8px;border-top:1px solid #c8e6c9;font-style:italic;font-size:12px">${d.recommendation}</div>
    `;
    box.classList.add('show');
  })
  .catch(e => alert('Request failed: ' + e.message));
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
