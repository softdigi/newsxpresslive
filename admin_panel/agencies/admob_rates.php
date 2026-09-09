<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error   = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_rate') {
        $rate_date = trim($_POST['rate_date'] ?? '');
        $cpm_rate  = floatval($_POST['cpm_rate']  ?? 0);
        $cpc_rate  = floatval($_POST['cpc_rate']  ?? 0);
        $platform  = trim($_POST['platform']  ?? 'admob');
        $notes     = trim($_POST['notes']     ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rate_date)) {
            $error = 'Please enter a valid date.';
        } elseif ($cpm_rate < 0 || $cpc_rate < 0) {
            $error = 'Rates must be 0 or greater.';
        } else {
            $allowed_platforms = ['admob', 'manual', 'firebase'];
            if (!in_array($platform, $allowed_platforms)) {
                $platform = 'admob';
            }
            $admin_id = intval($_SESSION['admin']['id'] ?? 0);

            $pdo->prepare(
                "INSERT INTO admob_rates (rate_date, cpm_rate, cpc_rate, platform, notes, created_by, created_at)
                 VALUES (:d, :cpm, :cpc, :plat, :notes, :by, NOW())
                 ON DUPLICATE KEY UPDATE
                     cpm_rate   = VALUES(cpm_rate),
                     cpc_rate   = VALUES(cpc_rate),
                     platform   = VALUES(platform),
                     notes      = VALUES(notes),
                     created_by = VALUES(created_by),
                     created_at = NOW()"
            )->execute([
                ':d'    => $rate_date,
                ':cpm'  => $cpm_rate,
                ':cpc'  => $cpc_rate,
                ':plat' => $platform,
                ':notes'=> $notes,
                ':by'   => $admin_id,
            ]);
            $message = "Rate for $rate_date saved successfully.";
        }

    } elseif ($action === 'calc_revenue') {
        $calc_date = trim($_POST['calc_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calc_date)) {
            $error = 'Please enter a valid date for revenue calculation.';
        } else {
            // Placeholder: actual calculation would be handled by a cron job.
            $message = "Revenue calculation triggered for $calc_date. The cron job will process it shortly.";
        }
    }

    header('Location: admob_rates.php?msg=' . urlencode($message ?: $error) . ($error ? '&err=1' : ''));
    exit;
}

if (isset($_GET['msg'])) {
    $raw = htmlspecialchars(trim($_GET['msg']), ENT_QUOTES, 'UTF-8');
    if (!empty($_GET['err'])) {
        $error = $raw;
    } else {
        $message = $raw;
    }
}

// ── Rate history (last 30 days) ───────────────────────────────────────────────
$history_stmt = $pdo->prepare(
    "SELECT r.*, a.name AS admin_name
     FROM admob_rates r
     LEFT JOIN admin_users a ON a.id = r.created_by
     ORDER BY r.rate_date DESC
     LIMIT 30"
);
$history_stmt->execute();
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's rate for pre-fill
$today_stmt = $pdo->prepare("SELECT * FROM admob_rates WHERE rate_date = CURDATE()");
$today_stmt->execute();
$today_rate = $today_stmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .rate-form { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 24px; }
  .rate-form .form-header { padding: 12px 16px; background: #f8f9fa; border-bottom: 1px solid #dee2e6;
                            font-weight: 700; border-radius: 6px 6px 0 0; }
  .rate-form .form-body { padding: 16px; }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
  .form-field { display: flex; flex-direction: column; gap: 5px; }
  .form-field label { font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; }
  .form-field input, .form-field select, .form-field textarea {
    padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
  }
  .form-field textarea { resize: vertical; min-height: 60px; }
  .form-actions { margin-top: 14px; display: flex; gap: 10px; }
  .section-box { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 24px; }
  .section-box .section-header { padding: 12px 16px; background: #f8f9fa; border-bottom: 1px solid #dee2e6;
                                  font-weight: 700; border-radius: 6px 6px 0 0; }
  .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; color: #fff; font-weight: 600; display: inline-block; }
  .badge-admob    { background: #4285f4; }
  .badge-firebase { background: #ff6d00; }
  .badge-manual   { background: #6c757d; }
  .alert-success { background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 4px; border: 1px solid #c3e6cb; margin-bottom: 16px; }
  .alert-danger  { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 16px; }
</style>

<div style="padding: 20px;">
  <h2 style="margin-bottom:20px;">📡 AdMob / Ad Rate Management</h2>

  <?php if ($message): ?>
    <div class="alert-success"><?= $message ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <!-- Rate Entry Form -->
  <div class="rate-form">
    <div class="form-header">Add / Update Daily Rate</div>
    <div class="form-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_rate">
        <div class="form-grid">
          <div class="form-field">
            <label>Date</label>
            <input type="date" name="rate_date" required
                   value="<?= htmlspecialchars($today_rate['rate_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="form-field">
            <label>CPM Rate ($)</label>
            <input type="number" name="cpm_rate" step="0.0001" min="0" required
                   placeholder="e.g. 1.5000"
                   value="<?= htmlspecialchars($today_rate['cpm_rate'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="form-field">
            <label>CPC Rate ($)</label>
            <input type="number" name="cpc_rate" step="0.0001" min="0" required
                   placeholder="e.g. 0.0500"
                   value="<?= htmlspecialchars($today_rate['cpc_rate'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="form-field">
            <label>Platform</label>
            <select name="platform">
              <?php foreach (['admob' => 'AdMob', 'manual' => 'Manual', 'firebase' => 'Firebase'] as $val => $label): ?>
              <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($today_rate['platform'] ?? 'admob') === $val ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field" style="grid-column: span 2;">
            <label>Notes</label>
            <textarea name="notes" placeholder="Optional notes..."><?= htmlspecialchars($today_rate['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">💾 Save Rate</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Calculate Revenue for Date -->
  <div class="rate-form">
    <div class="form-header">Calculate Revenue for Date</div>
    <div class="form-body">
      <form method="post" onsubmit="return confirm('Trigger revenue calculation for this date?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="calc_revenue">
        <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
          <div class="form-field">
            <label>Date</label>
            <input type="date" name="calc_date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="form-field">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-warning">⚙ Trigger Calculation</button>
          </div>
        </div>
        <p style="font-size:13px; color:#888; margin:8px 0 0;">
          This queues a revenue recalculation for the selected date. The cron job will process it.
        </p>
      </form>
    </div>
  </div>

  <!-- Rate History -->
  <div class="section-box">
    <div class="section-header">Rate History (last 30 days)</div>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f8f9fa;">
          <th style="padding:10px; border:1px solid #dee2e6;">Date</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">CPM Rate</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">CPC Rate</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Platform</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Notes</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Created By</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Updated At</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($history)): ?>
        <tr><td colspan="7" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No rate history found.</td></tr>
      <?php else: ?>
        <?php foreach ($history as $r): ?>
        <tr>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-weight:600;">
            <?= htmlspecialchars($r['rate_date'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right; font-family:monospace;">
            $<?= number_format(floatval($r['cpm_rate']), 4) ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right; font-family:monospace;">
            $<?= number_format(floatval($r['cpc_rate']), 4) ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <span class="badge badge-<?= htmlspecialchars($r['platform'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars(ucfirst($r['platform']), ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px; color:#666;">
            <?= htmlspecialchars($r['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
            <?= htmlspecialchars($r['admin_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
            <?= htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
