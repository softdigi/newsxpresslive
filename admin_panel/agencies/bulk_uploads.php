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

// ── Error log CSV download ────────────────────────────────────────────────────
if (isset($_GET['download']) && intval($_GET['download']) === 1) {
    $dl_id = intval($_GET['id'] ?? 0);
    if ($dl_id > 0) {
        $dl_stmt = $pdo->prepare("SELECT filename, error_log FROM agency_bulk_uploads WHERE id = :id");
        $dl_stmt->execute([':id' => $dl_id]);
        $dl_row = $dl_stmt->fetch(PDO::FETCH_ASSOC);

        if ($dl_row && !empty($dl_row['error_log'])) {
            $errors = json_decode($dl_row['error_log'], true);
            $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dl_row['filename'] ?? 'upload');

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="errors_' . $safe_filename . '.csv"');
            $out = fopen('php://output', 'w');

            if (is_array($errors)) {
                if (!empty($errors)) {
                    $first = reset($errors);
                    if (is_array($first)) {
                        fputcsv($out, array_keys($first));
                        foreach ($errors as $err_row) {
                            fputcsv($out, array_map('strval', $err_row));
                        }
                    } else {
                        fputcsv($out, ['Row', 'Error']);
                        foreach ($errors as $idx => $err_msg) {
                            fputcsv($out, [$idx + 1, strval($err_msg)]);
                        }
                    }
                } else {
                    fputcsv($out, ['No errors recorded.']);
                }
            } else {
                fputcsv($out, ['Error log']);
                fputcsv($out, [$dl_row['error_log']]);
            }

            fclose($out);
            exit;
        }
    }
    header('Location: bulk_uploads.php?msg=' . urlencode('Error log not available.') . '&err=1');
    exit;
}

// ── POST: re-process ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'reprocess') {
        $reprocess_id = intval($_POST['upload_id'] ?? 0);
        if ($reprocess_id > 0) {
            $pdo->prepare(
                "UPDATE agency_bulk_uploads SET status='queued', error_log=NULL
                 WHERE id=:id AND status='failed'"
            )->execute([':id' => $reprocess_id]);
            $message = 'Upload queued for reprocessing.';
        }
    }

    header('Location: bulk_uploads.php?msg=' . urlencode($message ?: 'Action completed.'));
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

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status    = trim($_GET['status']    ?? '');
$filter_agency_id = intval($_GET['agency_id'] ?? 0);
$per_page         = 20;
$page             = max(1, intval($_GET['page'] ?? 1));
$offset           = ($page - 1) * $per_page;

$allowed_statuses = ['queued', 'processing', 'completed', 'failed'];
if (!in_array($filter_status, $allowed_statuses)) {
    $filter_status = '';
}

// Build WHERE
$where  = [];
$params = [];
if ($filter_status !== '') {
    $where[]              = 'bu.status = :status';
    $params[':status']    = $filter_status;
}
if ($filter_agency_id > 0) {
    $where[]              = 'bu.agency_id = :ag_id';
    $params[':ag_id']     = $filter_agency_id;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM agency_bulk_uploads bu $where_sql");
$cnt_stmt->execute($params);
$total       = (int) $cnt_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch uploads
$sql = "SELECT bu.*, a.name AS agency_name
        FROM agency_bulk_uploads bu
        JOIN agencies a ON a.id = bu.agency_id
        $where_sql
        ORDER BY bu.created_at DESC
        LIMIT :lim OFFSET :off";
$list_stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $list_stmt->bindValue($k, $v);
}
$list_stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$list_stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$list_stmt->execute();
$uploads = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Agency list for filter dropdown
$ag_list = $pdo->query("SELECT id, name FROM agencies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .filter-bar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
                background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;
                padding: 14px 16px; margin-bottom: 20px; }
  .filter-bar .field { display: flex; flex-direction: column; gap: 4px; }
  .filter-bar label { font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; }
  .filter-bar input, .filter-bar select {
    padding: 7px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
  }
  .section-box { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 20px; }
  .section-box .section-header { padding: 12px 16px; background: #f8f9fa;
    border-bottom: 1px solid #dee2e6; font-weight: 700; border-radius: 6px 6px 0 0; }
  .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; color: #fff; font-weight: 600; display: inline-block; }
  .badge-queued     { background: #ffc107; color: #333; }
  .badge-processing { background: #17a2b8; }
  .badge-completed  { background: #28a745; }
  .badge-failed     { background: #dc3545; }
  .pagination { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 16px; }
  .pagination a, .pagination span {
    padding: 6px 12px; border: 1px solid #dee2e6; border-radius: 4px;
    text-decoration: none; color: #333; font-size: 14px;
  }
  .pagination .active { background: #007bff; color: #fff; border-color: #007bff; }
  .alert-success { background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 4px; border: 1px solid #c3e6cb; margin-bottom: 16px; }
  .alert-danger  { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 16px; }
  .progress-cell { display: flex; gap: 8px; align-items: center; font-size: 13px; }
  .progress-bar-wrap { width: 80px; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
  .progress-bar-fill { height: 100%; background: #28a745; border-radius: 4px; }
</style>

<div style="padding: 20px;">
  <h2 style="margin-bottom:20px;">📦 Bulk Upload Monitor</h2>

  <?php if ($message): ?>
    <div class="alert-success"><?= $message ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <!-- Filters -->
  <form method="get" class="filter-bar">
    <div class="field">
      <label>Status</label>
      <select name="status">
        <option value="">All Statuses</option>
        <?php foreach (['queued' => 'Queued', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'] as $val => $lbl): ?>
        <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
          <?= $filter_status === $val ? 'selected' : '' ?>>
          <?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Agency</label>
      <select name="agency_id" style="min-width:160px;">
        <option value="">All Agencies</option>
        <?php foreach ($ag_list as $ag): ?>
        <option value="<?= intval($ag['id']) ?>" <?= $filter_agency_id === intval($ag['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($ag['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <button type="submit" class="btn btn-primary">Filter</button>
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <a href="bulk_uploads.php" class="btn btn-secondary">Reset</a>
    </div>
  </form>

  <!-- Uploads Table -->
  <div class="section-box">
    <div class="section-header">
      Bulk Uploads
      <span style="font-size:13px; color:#888; font-weight:400; margin-left:8px;">
        Showing <?= count($uploads) ?> of <?= $total ?> (Page <?= $page ?>/<?= $total_pages ?>)
      </span>
    </div>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f8f9fa;">
          <th style="padding:10px; border:1px solid #dee2e6;">ID</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Agency</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Filename</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Total</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Success</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Failed</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Progress</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Status</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Date</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($uploads)): ?>
        <tr><td colspan="10" style="text-align:center; padding:20px; border:1px solid #dee2e6; color:#888;">No uploads found.</td></tr>
      <?php else: ?>
        <?php foreach ($uploads as $up): ?>
        <?php
          $total_rows   = intval($up['total_rows']);
          $success_cnt  = intval($up['success_count']);
          $pct          = $total_rows > 0 ? min(100, round(($success_cnt / $total_rows) * 100)) : 0;
          $has_errors   = !empty($up['error_log']) && $up['error_log'] !== '[]' && $up['error_log'] !== 'null';
          $qs_current   = http_build_query(array_filter([
              'status'    => $filter_status,
              'agency_id' => $filter_agency_id ?: null,
              'page'      => $page > 1 ? $page : null,
          ]));
        ?>
        <tr>
          <td style="padding:9px 10px; border:1px solid #dee2e6; color:#888; font-size:13px;"><?= intval($up['id']) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-weight:500;">
            <a href="detail.php?id=<?= intval($up['agency_id']) ?>">
              <?= htmlspecialchars($up['agency_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px; max-width:200px;
                     overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
              title="<?= htmlspecialchars($up['filename'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($up['filename'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format($total_rows) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right; color:#28a745; font-weight:600;">
            <?= number_format($success_cnt) ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;
              color:<?= intval($up['failed_count']) > 0 ? '#dc3545' : '#28a745' ?>; font-weight:600;">
            <?= number_format(intval($up['failed_count'])) ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <div class="progress-cell">
              <div class="progress-bar-wrap">
                <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
              </div>
              <span><?= $pct ?>%</span>
            </div>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <span class="badge badge-<?= htmlspecialchars($up['status'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars(ucfirst($up['status']), ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
            <?= htmlspecialchars($up['created_at'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
              <?php if ($has_errors): ?>
              <a href="bulk_uploads.php?download=1&id=<?= intval($up['id']) ?>"
                 class="btn btn-sm btn-secondary" title="Download error log as CSV">
                ⬇ Errors
              </a>
              <?php endif; ?>
              <?php if ($up['status'] === 'failed'): ?>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Re-queue this upload for reprocessing?');">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reprocess">
                <input type="hidden" name="upload_id" value="<?= intval($up['id']) ?>">
                <button type="submit" class="btn btn-sm btn-warning">🔄 Re-process</button>
              </form>
              <?php endif; ?>
              <?php if (!$has_errors && $up['status'] !== 'failed'): ?>
                <span style="font-size:12px; color:#888;">—</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php
    $base_qs = http_build_query(array_filter([
        'status'    => $filter_status,
        'agency_id' => $filter_agency_id ?: null,
    ]));
    ?>
    <?php if ($page > 1): ?>
      <a href="?<?= htmlspecialchars($base_qs . '&page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">« Prev</a>
    <?php endif; ?>
    <?php
    $start_p = max(1, $page - 3);
    $end_p   = min($total_pages, $page + 3);
    for ($i = $start_p; $i <= $end_p; $i++):
    ?>
      <?php if ($i === $page): ?>
        <span class="active"><?= $i ?></span>
      <?php else: ?>
        <a href="?<?= htmlspecialchars($base_qs . '&page=' . $i, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
      <a href="?<?= htmlspecialchars($base_qs . '&page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next »</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
