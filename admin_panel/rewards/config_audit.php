<?php
/**
 * admin_panel/rewards/config_audit.php
 *
 * Reward Config Audit Log — admin + super_admin.
 * Date range filter, admin UID filter, config key dropdown, CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!can(['admin', 'super_admin'])) {
    http_response_code(403);
    die('<h2>Access Denied.</h2>');
}

const PER_PAGE = 50;

// ── Sanitize filters ──────────────────────────────────────────────────────────
$fromDate  = '';
$toDate    = '';
$filterKey = trim($_GET['config_key'] ?? '');
$filterAdm = trim($_GET['admin_uid'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) {
    $fromDate = $_GET['from'];
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) {
    $toDate = $_GET['to'];
}

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];

if ($fromDate !== '') {
    $where   .= ' AND DATE(changed_at) >= ?';
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where   .= ' AND DATE(changed_at) <= ?';
    $params[] = $toDate;
}
if ($filterKey !== '') {
    $where   .= ' AND config_key = ?';
    $params[] = $filterKey;
}
if ($filterAdm !== '') {
    $where   .= ' AND changed_by = ?';
    $params[] = $filterAdm;
}

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare(
        "SELECT changed_at, config_key, old_value, new_value, changed_by, reason
         FROM reward_config_audit
         WHERE {$where}
         ORDER BY changed_at DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reward_config_audit_' . date('Ymd_Hi') . '.csv"');
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Config Key', 'Old Value', 'New Value', 'Changed By', 'Reason']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['changed_at'],
            $r['config_key'],
            $r['old_value'],
            $r['new_value'],
            $r['changed_by'],
            $r['reason'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── Count total ───────────────────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM reward_config_audit WHERE {$where}");
$countStmt->execute($params);
$total     = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / PER_PAGE));
$offset     = ($page - 1) * PER_PAGE;

// ── Fetch rows ────────────────────────────────────────────────────────────────
$dataStmt = $pdo->prepare(
    "SELECT a.id, a.changed_at, a.config_key, a.old_value, a.new_value,
            a.changed_by, a.reason,
            COALESCE(u.name, a.changed_by) AS admin_name
     FROM reward_config_audit a
     LEFT JOIN admin_users u ON u.id = a.changed_by
     WHERE {$where}
     ORDER BY a.changed_at DESC
     LIMIT {$offset}, " . PER_PAGE
);
$dataStmt->execute($params);
$auditRows = $dataStmt->fetchAll();

// ── All distinct config keys for dropdown ─────────────────────────────────────
$allKeys = $pdo->query(
    "SELECT DISTINCT config_key FROM reward_config_audit ORDER BY config_key"
)->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.filter-bar { background:#fff; border-radius:8px; padding:16px 20px; margin-bottom:18px; box-shadow:0 1px 4px rgba(0,0,0,.1); display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:12px; color:#666; font-weight:500; }
.filter-group input, .filter-group select { padding:7px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; min-width:150px; }
.btn-filter { background:#E50914; color:#fff; border:none; padding:8px 18px; border-radius:4px; font-size:13px; cursor:pointer; align-self:flex-end; }
.btn-filter:hover { background:#c5000f; }
.btn-export { background:#1976D2; color:#fff; border:none; padding:8px 16px; border-radius:4px; font-size:13px; cursor:pointer; align-self:flex-end; text-decoration:none; display:inline-block; }
.btn-export:hover { background:#1565C0; }

.audit-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.audit-table th { background:#f9f9f9; padding:11px 14px; text-align:left; font-size:13px; color:#555; border-bottom:2px solid #eee; }
.audit-table td { padding:10px 14px; font-size:13px; border-bottom:1px solid #f5f5f5; vertical-align:top; }
.audit-table tr:hover td { background:#fafafa; }
.key-badge { background:#e8eaf6; color:#3949AB; padding:3px 8px; border-radius:12px; font-size:12px; font-family:monospace; }
.old-val { color:#888; text-decoration:line-through; }
.new-val { color:#2E7D32; font-weight:600; }
.arrow { color:#aaa; padding:0 6px; }

.pagination { display:flex; gap:6px; justify-content:center; margin-top:20px; }
.pagination a, .pagination span { padding:7px 13px; border-radius:4px; font-size:13px; text-decoration:none; }
.pagination a { background:#fff; border:1px solid #ddd; color:#333; }
.pagination a:hover { background:#f5f5f5; }
.pagination .current { background:#E50914; color:#fff; font-weight:600; }

.summary-bar { font-size:13px; color:#666; margin-bottom:12px; }
</style>

<div style="padding:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
    <h1 style="margin:0;font-size:22px;">📋 Config Audit Log</h1>
    <a href="config.php" style="font-size:13px;color:#E50914;text-decoration:none;">← Back to Config</a>
  </div>

  <!-- Filters -->
  <form method="GET" action="config_audit.php">
    <div class="filter-bar">
      <div class="filter-group">
        <label>From Date</label>
        <input type="date" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES) ?>">
      </div>
      <div class="filter-group">
        <label>To Date</label>
        <input type="date" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES) ?>">
      </div>
      <div class="filter-group">
        <label>Config Key</label>
        <select name="config_key">
          <option value="">— All Keys —</option>
          <?php foreach ($allKeys as $k): ?>
          <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"
                  <?= $filterKey === $k ? 'selected' : '' ?>>
            <?= htmlspecialchars($k, ENT_QUOTES) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Admin UID</label>
        <input type="text" name="admin_uid"
               value="<?= htmlspecialchars($filterAdm, ENT_QUOTES) ?>"
               placeholder="Admin UID or ID">
      </div>
      <button type="submit" class="btn-filter">🔍 Filter</button>
      <a href="config_audit.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
         class="btn-export">⬇️ Export CSV</a>
    </div>
  </form>

  <div class="summary-bar">
    Showing <?= number_format($total) ?> record(s)
    <?= $fromDate || $toDate || $filterKey || $filterAdm ? '(filtered)' : '' ?>
    — Page <?= $page ?> of <?= $totalPages ?>
  </div>

  <!-- Table -->
  <table class="audit-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Date / Time</th>
        <th>Config Key</th>
        <th>Value Change</th>
        <th>Changed By</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($auditRows)): ?>
      <tr><td colspan="6" style="text-align:center;color:#999;padding:30px">No audit records found.</td></tr>
    <?php else: ?>
      <?php foreach ($auditRows as $i => $row): ?>
      <tr>
        <td style="color:#aaa;font-size:12px"><?= ($offset + $i + 1) ?></td>
        <td style="white-space:nowrap;font-size:12px">
          <?= htmlspecialchars(date('d M Y', strtotime($row['changed_at'])), ENT_QUOTES) ?><br>
          <span style="color:#999"><?= htmlspecialchars(date('H:i:s', strtotime($row['changed_at'])), ENT_QUOTES) ?></span>
        </td>
        <td><span class="key-badge"><?= htmlspecialchars($row['config_key'], ENT_QUOTES) ?></span></td>
        <td>
          <span class="old-val"><?= htmlspecialchars($row['old_value'], ENT_QUOTES) ?></span>
          <span class="arrow">→</span>
          <span class="new-val"><?= htmlspecialchars($row['new_value'], ENT_QUOTES) ?></span>
        </td>
        <td style="font-size:12px">
          <?= htmlspecialchars($row['admin_name'] ?? $row['changed_by'], ENT_QUOTES) ?>
          <?php if ($row['admin_name'] !== $row['changed_by'] && $row['changed_by']): ?>
            <br><span style="color:#bbb"><?= htmlspecialchars($row['changed_by'], ENT_QUOTES) ?></span>
          <?php endif; ?>
        </td>
        <td style="color:#666;font-style:italic;max-width:240px">
          <?= $row['reason'] ? htmlspecialchars($row['reason'], ENT_QUOTES) : '<span style="color:#ccc">—</span>' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Prev</a>
    <?php endif; ?>

    <?php
    $startPage = max(1, $page - 2);
    $endPage   = min($totalPages, $page + 2);
    for ($p = $startPage; $p <= $endPage; $p++):
    ?>
    <?php if ($p === $page): ?>
    <span class="current"><?= $p ?></span>
    <?php else: ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
    <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
