<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$error   = '';
$message = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action     = trim($_POST['action'] ?? '');
    $redirect_tab = 'overview';

    switch ($action) {

        case 'update_info':
            $name    = trim($_POST['name'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $rev_pct = floatval($_POST['revenue_share_percent'] ?? 0);
            if ($name === '' || $email === '') {
                $error = 'Name and email are required.';
                break;
            }
            if ($rev_pct < 0 || $rev_pct > 100) {
                $error = 'Revenue share must be between 0 and 100.';
                break;
            }
            $pdo->prepare("UPDATE agencies SET name=:n, email=:e, revenue_share_percent=:r WHERE id=:id")
                ->execute([':n' => $name, ':e' => $email, ':r' => $rev_pct, ':id' => $id]);
            header("Location: detail.php?id=$id&tab=overview&msg=" . urlencode('Agency info updated.'));
            exit;

        case 'toggle_status':
            $reason = trim($_POST['reason'] ?? '');
            $row    = $pdo->prepare("SELECT status FROM agencies WHERE id=:id");
            $row->execute([':id' => $id]);
            $cur     = $row->fetchColumn();
            $new_st  = ($cur === 'active') ? 'suspended' : 'active';
            $pdo->prepare("UPDATE agencies SET status=:s WHERE id=:id")
                ->execute([':s' => $new_st, ':id' => $id]);
            header("Location: detail.php?id=$id&tab=overview&msg=" . urlencode("Status changed to $new_st."));
            exit;

        case 'regen_key':
            $new_key    = bin2hex(random_bytes(16));
            $new_secret = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE agencies SET api_key=:k, api_secret=:s WHERE id=:id")
                ->execute([':k' => $new_key, ':s' => $new_secret, ':id' => $id]);
            header("Location: detail.php?id=$id&tab=overview&msg=" . urlencode('API key and secret regenerated.'));
            exit;

        case 'article_action':
            $article_id  = intval($_POST['article_id'] ?? 0);
            $art_action  = trim($_POST['article_action'] ?? '');
            if ($article_id > 0 && in_array($art_action, ['approved', 'rejected'])) {
                $pdo->prepare("UPDATE agency_articles SET status=:s WHERE id=:id AND agency_id=:aid")
                    ->execute([':s' => $art_action, ':id' => $article_id, ':aid' => $id]);
            }
            header("Location: detail.php?id=$id&tab=articles&msg=" . urlencode("Article $art_action."));
            exit;

        case 'bulk_article':
            $article_ids = $_POST['article_ids'] ?? [];
            $bulk_action = trim($_POST['bulk_action'] ?? '');
            if (!empty($article_ids) && in_array($bulk_action, ['approved', 'rejected'])) {
                $safe_ids    = array_map('intval', $article_ids);
                $safe_ids    = array_filter($safe_ids, fn($v) => $v > 0);
                if (!empty($safe_ids)) {
                    $ph   = implode(',', array_fill(0, count($safe_ids), '?'));
                    $stmt = $pdo->prepare("UPDATE agency_articles SET status=? WHERE id IN ($ph) AND agency_id=?");
                    $stmt->execute(array_merge([$bulk_action], $safe_ids, [$id]));
                }
            }
            header("Location: detail.php?id=$id&tab=articles&msg=" . urlencode('Bulk action applied.'));
            exit;

        case 'revenue_adjust':
            $amount = floatval($_POST['amount'] ?? 0);
            $note   = trim($_POST['note'] ?? '');
            if ($note === '') {
                $error       = 'Note is required for adjustments.';
                $redirect_tab = 'revenue';
                break;
            }
            $pdo->beginTransaction();
            try {
                $bs = $pdo->prepare("SELECT wallet_balance FROM agencies WHERE id=:id FOR UPDATE");
                $bs->execute([':id' => $id]);
                $old_bal = floatval($bs->fetchColumn());
                $new_bal = $old_bal + $amount;
                $pdo->prepare("UPDATE agencies SET wallet_balance=:b WHERE id=:id")
                    ->execute([':b' => $new_bal, ':id' => $id]);
                $pdo->prepare("INSERT INTO agency_transactions (agency_id, type, amount, balance_after, description, created_at)
                               VALUES (:aid, 'adjustment', :amt, :bal, :desc, NOW())")
                    ->execute([':aid' => $id, ':amt' => $amount, ':bal' => $new_bal, ':desc' => $note]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error        = 'Failed to apply adjustment.';
                $redirect_tab = 'revenue';
                break;
            }
            header("Location: detail.php?id=$id&tab=revenue&msg=" . urlencode('Adjustment applied.'));
            exit;

        case 'withdrawal_action':
            $wid   = intval($_POST['withdrawal_id'] ?? 0);
            $wact  = trim($_POST['withdrawal_action'] ?? '');
            if ($wid <= 0) break;

            if ($wact === 'processing') {
                $pdo->prepare("UPDATE agency_withdrawals SET status='processing' WHERE id=:id AND agency_id=:aid")
                    ->execute([':id' => $wid, ':aid' => $id]);
                header("Location: detail.php?id=$id&tab=transactions&msg=" . urlencode('Withdrawal set to processing.'));
                exit;
            }
            if ($wact === 'completed') {
                $txn_id = trim($_POST['transaction_id'] ?? '');
                $pdo->prepare("UPDATE agency_withdrawals SET status='completed', transaction_id=:txn, completed_at=NOW() WHERE id=:id AND agency_id=:aid")
                    ->execute([':txn' => $txn_id, ':id' => $wid, ':aid' => $id]);
                header("Location: detail.php?id=$id&tab=transactions&msg=" . urlencode('Withdrawal completed.'));
                exit;
            }
            if ($wact === 'rejected') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');
                $pdo->beginTransaction();
                try {
                    $wd_row = $pdo->prepare("SELECT amount FROM agency_withdrawals WHERE id=:id AND agency_id=:aid");
                    $wd_row->execute([':id' => $wid, ':aid' => $id]);
                    $wd_amount = floatval($wd_row->fetchColumn());
                    $pdo->prepare("UPDATE agency_withdrawals SET status='rejected', reject_reason=:r WHERE id=:id AND agency_id=:aid")
                        ->execute([':r' => $reject_reason, ':id' => $wid, ':aid' => $id]);
                    $bs = $pdo->prepare("SELECT wallet_balance FROM agencies WHERE id=:id FOR UPDATE");
                    $bs->execute([':id' => $id]);
                    $old_bal = floatval($bs->fetchColumn());
                    $new_bal = $old_bal + $wd_amount;
                    $pdo->prepare("UPDATE agencies SET wallet_balance=:b WHERE id=:id")
                        ->execute([':b' => $new_bal, ':id' => $id]);
                    $pdo->prepare("INSERT INTO agency_transactions (agency_id, type, amount, balance_after, description, created_at)
                                   VALUES (:aid, 'credit', :amt, :bal, 'Withdrawal refund', NOW())")
                        ->execute([':aid' => $id, ':amt' => $wd_amount, ':bal' => $new_bal]);
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Failed to reject withdrawal.';
                    break;
                }
                header("Location: detail.php?id=$id&tab=transactions&msg=" . urlencode('Withdrawal rejected and amount refunded.'));
                exit;
            }
            break;
    }
}

// ── GET message ───────────────────────────────────────────────────────────────
if (isset($_GET['msg'])) {
    $message = htmlspecialchars(trim($_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$active_tab = trim($_GET['tab'] ?? 'overview');
$valid_tabs = ['overview', 'articles', 'revenue', 'transactions'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'overview';
}

// ── Data queries ──────────────────────────────────────────────────────────────
$ag_stmt = $pdo->prepare("SELECT * FROM agencies WHERE id=:id");
$ag_stmt->execute([':id' => $id]);
$agency = $ag_stmt->fetch(PDO::FETCH_ASSOC);
if (!$agency) {
    header('Location: index.php');
    exit;
}

// Articles
$art_stmt = $pdo->prepare(
    "SELECT aa.id, aa.status, aa.created_at, aa.external_id,
            n.title, n.total_impressions, n.total_clicks
     FROM agency_articles aa
     JOIN news n ON n.id = aa.news_id
     WHERE aa.agency_id = :id
     ORDER BY aa.created_at DESC
     LIMIT 100"
);
$art_stmt->execute([':id' => $id]);
$articles = $art_stmt->fetchAll(PDO::FETCH_ASSOC);

// Revenue rows
$rev_stmt = $pdo->prepare(
    "SELECT revenue_date, impressions, clicks, gross_revenue, agency_share, platform_share
     FROM agency_revenue
     WHERE agency_id = :id
     ORDER BY revenue_date DESC
     LIMIT 24"
);
$rev_stmt->execute([':id' => $id]);
$revenues = $rev_stmt->fetchAll(PDO::FETCH_ASSOC);

// Revenue totals
$rtot_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(impressions),0), COALESCE(SUM(clicks),0),
            COALESCE(SUM(gross_revenue),0), COALESCE(SUM(agency_share),0),
            COALESCE(SUM(platform_share),0)
     FROM agency_revenue WHERE agency_id = :id"
);
$rtot_stmt->execute([':id' => $id]);
$rev_totals = $rtot_stmt->fetch(PDO::FETCH_NUM);

// Transactions
$txn_stmt = $pdo->prepare(
    "SELECT * FROM agency_transactions WHERE agency_id = :id ORDER BY created_at DESC LIMIT 50"
);
$txn_stmt->execute([':id' => $id]);
$transactions = $txn_stmt->fetchAll(PDO::FETCH_ASSOC);

// Withdrawals
$wd_stmt = $pdo->prepare(
    "SELECT * FROM agency_withdrawals WHERE agency_id = :id ORDER BY requested_at DESC"
);
$wd_stmt->execute([':id' => $id]);
$withdrawals = $wd_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .tab-input { display: none; }
  .tab-labels { display: flex; gap: 0; border-bottom: 2px solid #dee2e6; margin-bottom: 20px; flex-wrap: wrap; }
  .tab-label {
    padding: 10px 20px; cursor: pointer; background: #f8f9fa;
    border: 1px solid #dee2e6; border-bottom: none; margin-right: 4px;
    border-radius: 4px 4px 0 0; color: #495057; font-weight: 500;
    transition: background .15s;
  }
  .tab-label:hover { background: #e9ecef; }
  .tab-panel { display: none; }

  #tab-overview:checked   ~ .tab-labels label[for="tab-overview"],
  #tab-articles:checked   ~ .tab-labels label[for="tab-articles"],
  #tab-revenue:checked    ~ .tab-labels label[for="tab-revenue"],
  #tab-transactions:checked ~ .tab-labels label[for="tab-transactions"] {
    background: #fff; border-bottom-color: #fff; color: #007bff; margin-bottom: -2px;
  }
  #tab-overview:checked   ~ .tab-panels #panel-overview     { display: block; }
  #tab-articles:checked   ~ .tab-panels #panel-articles     { display: block; }
  #tab-revenue:checked    ~ .tab-panels #panel-revenue      { display: block; }
  #tab-transactions:checked ~ .tab-panels #panel-transactions { display: block; }

  .masked { letter-spacing: 2px; color: #999; }
  .form-inline-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 10px; }
  .form-inline-row .field { display: flex; flex-direction: column; gap: 4px; }
  .form-inline-row label { font-size: 13px; font-weight: 600; color: #555; }
  .form-inline-row input, .form-inline-row select, .form-inline-row textarea {
    padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
  }
  .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; color: #fff; font-weight: 600; display: inline-block; }
  .badge-active    { background: #28a745; }
  .badge-suspended { background: #dc3545; }
  .badge-pending   { background: #ffc107; color: #333; }
  .badge-approved  { background: #28a745; }
  .badge-rejected  { background: #dc3545; }
  .badge-processing { background: #17a2b8; }
  .badge-completed  { background: #6c757d; }
  .badge-requested  { background: #ffc107; color: #333; }
  .badge-credit     { background: #28a745; }
  .badge-withdrawal { background: #dc3545; }
  .badge-adjustment { background: #17a2b8; }
  .section-box { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 20px; }
  .section-box .section-header { padding: 12px 16px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 600; border-radius: 6px 6px 0 0; }
  .section-box .section-body { padding: 16px; }
  .alert-success { background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 4px; border: 1px solid #c3e6cb; margin-bottom: 14px; }
  .alert-danger  { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 14px; }
</style>

<div style="padding: 20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
    <h2 style="margin:0;">Agency Detail — <?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <a href="index.php" class="btn btn-secondary">← Back to List</a>
  </div>

  <?php if ($message): ?>
    <div class="alert-success"><?= $message ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- TAB SYSTEM (CSS radio/label hack) -->
  <input type="radio" name="detail-tabs" id="tab-overview"
         class="tab-input" <?= $active_tab === 'overview'      ? 'checked' : '' ?>>
  <input type="radio" name="detail-tabs" id="tab-articles"
         class="tab-input" <?= $active_tab === 'articles'      ? 'checked' : '' ?>>
  <input type="radio" name="detail-tabs" id="tab-revenue"
         class="tab-input" <?= $active_tab === 'revenue'       ? 'checked' : '' ?>>
  <input type="radio" name="detail-tabs" id="tab-transactions"
         class="tab-input" <?= $active_tab === 'transactions'   ? 'checked' : '' ?>>

  <div class="tab-labels">
    <label for="tab-overview"      class="tab-label">📋 Overview</label>
    <label for="tab-articles"      class="tab-label">📰 Articles</label>
    <label for="tab-revenue"       class="tab-label">💰 Revenue</label>
    <label for="tab-transactions"  class="tab-label">📊 Transactions</label>
  </div>

  <div class="tab-panels">

    <!-- ═══════════════════ TAB 1 — OVERVIEW ═══════════════════ -->
    <div id="panel-overview" class="tab-panel">

      <!-- Agency Info Form -->
      <div class="section-box">
        <div class="section-header">Agency Information</div>
        <div class="section-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="update_info">
            <div class="form-inline-row">
              <div class="field">
                <label>Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($agency['name'], ENT_QUOTES, 'UTF-8') ?>" required style="min-width:220px;">
              </div>
              <div class="field">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($agency['email'], ENT_QUOTES, 'UTF-8') ?>" required style="min-width:220px;">
              </div>
              <div class="field">
                <label>Revenue Share %</label>
                <input type="number" name="revenue_share_percent" min="0" max="100" step="0.01"
                       value="<?= htmlspecialchars($agency['revenue_share_percent'], ENT_QUOTES, 'UTF-8') ?>" style="width:120px;">
              </div>
              <div class="field">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Save Changes</button>
              </div>
            </div>
          </form>

          <div style="margin-top:12px; display:flex; align-items:center; gap:12px;">
            <span>Status:&nbsp;
              <span class="badge badge-<?= htmlspecialchars($agency['status'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(ucfirst($agency['status']), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </span>
            <span>Wallet Balance: <strong>$<?= number_format(floatval($agency['wallet_balance']), 2) ?></strong></span>
            <span>Joined: <?= htmlspecialchars($agency['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>
      </div>

      <!-- Status Toggle -->
      <div class="section-box">
        <div class="section-header">Toggle Status</div>
        <div class="section-body">
          <form method="post" onsubmit="return confirm('Are you sure you want to change this agency\'s status?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_status">
            <div class="form-inline-row">
              <div class="field">
                <label>Reason (optional)</label>
                <input type="text" name="reason" placeholder="Reason for status change..." style="min-width:280px;">
              </div>
              <div class="field">
                <label>&nbsp;</label>
                <?php if ($agency['status'] === 'active'): ?>
                  <button type="submit" class="btn btn-danger">🔴 Suspend Agency</button>
                <?php else: ?>
                  <button type="submit" class="btn btn-success">🟢 Activate Agency</button>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- API Credentials -->
      <div class="section-box">
        <div class="section-header">API Credentials</div>
        <div class="section-body">
          <table style="width:100%; border-collapse:collapse;">
            <tr>
              <td style="padding:8px; font-weight:600; width:140px;">API Key</td>
              <td style="padding:8px;">
                <span class="masked" id="api-key-masked">••••••••••••••••••••••••••••••••</span>
                <span id="api-key-visible" style="display:none; font-family:monospace;">
                  <?= htmlspecialchars($agency['api_key'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <button class="btn btn-sm btn-secondary" style="margin-left:8px;"
                  onclick="document.getElementById('api-key-masked').style.display='none';
                           document.getElementById('api-key-visible').style.display='inline';
                           this.style.display='none';">Show</button>
              </td>
            </tr>
            <tr>
              <td style="padding:8px; font-weight:600;">API Secret</td>
              <td style="padding:8px;">
                <span class="masked" id="api-secret-masked">••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••</span>
                <span id="api-secret-visible" style="display:none; font-family:monospace; word-break:break-all;">
                  <?= htmlspecialchars($agency['api_secret'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <button class="btn btn-sm btn-secondary" style="margin-left:8px;"
                  onclick="document.getElementById('api-secret-masked').style.display='none';
                           document.getElementById('api-secret-visible').style.display='inline';
                           this.style.display='none';">Show</button>
              </td>
            </tr>
          </table>

          <form method="post" style="margin-top:14px;"
                onsubmit="return confirm('Regenerating the API key will immediately invalidate the current key. Continue?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="regen_key">
            <button type="submit" class="btn btn-warning">🔄 Regenerate API Key & Secret</button>
          </form>
        </div>
      </div>

    </div><!-- /panel-overview -->

    <!-- ═══════════════════ TAB 2 — ARTICLES ═══════════════════ -->
    <div id="panel-articles" class="tab-panel">

      <!-- Bulk Action Form -->
      <form method="post" id="bulk-form"
            onsubmit="return confirm('Apply bulk action to all selected articles?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="bulk_article">
        <div style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">
          <select name="bulk_action" class="btn btn-secondary" style="padding:6px 10px;">
            <option value="approved">Approve Selected</option>
            <option value="rejected">Reject Selected</option>
          </select>
          <button type="submit" class="btn btn-primary">Apply Bulk Action</button>
          <span style="font-size:13px; color:#666;">
            <a href="#" onclick="document.querySelectorAll('.art-check').forEach(c=>c.checked=true); return false;">Select All</a>
            &nbsp;|&nbsp;
            <a href="#" onclick="document.querySelectorAll('.art-check').forEach(c=>c.checked=false); return false;">Deselect All</a>
          </span>
        </div>

        <table class="admin-table" style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#f8f9fa;">
              <th style="padding:10px; border:1px solid #dee2e6; width:40px;"></th>
              <th style="padding:10px; border:1px solid #dee2e6;">Title</th>
              <th style="padding:10px; border:1px solid #dee2e6;">Status</th>
              <th style="padding:10px; border:1px solid #dee2e6;">Date</th>
              <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Impressions</th>
              <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Clicks</th>
              <th style="padding:10px; border:1px solid #dee2e6;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($articles)): ?>
            <tr><td colspan="7" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No articles found.</td></tr>
          <?php else: ?>
            <?php foreach ($articles as $art): ?>
            <tr style="border-bottom:1px solid #f0f0f0;">
              <td style="padding:8px; border:1px solid #dee2e6; text-align:center;">
                <input type="checkbox" name="article_ids[]" value="<?= intval($art['id']) ?>" class="art-check">
              </td>
              <td style="padding:8px; border:1px solid #dee2e6;">
                <?= htmlspecialchars($art['title'], ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td style="padding:8px; border:1px solid #dee2e6;">
                <span class="badge badge-<?= htmlspecialchars($art['status'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars(ucfirst($art['status']), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td style="padding:8px; border:1px solid #dee2e6; font-size:13px;">
                <?= htmlspecialchars($art['created_at'], ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td style="padding:8px; border:1px solid #dee2e6; text-align:right;">
                <?= number_format(intval($art['total_impressions'])) ?>
              </td>
              <td style="padding:8px; border:1px solid #dee2e6; text-align:right;">
                <?= number_format(intval($art['total_clicks'])) ?>
              </td>
              <td style="padding:8px; border:1px solid #dee2e6;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="article_action">
                  <input type="hidden" name="article_id" value="<?= intval($art['id']) ?>">
                  <input type="hidden" name="article_action" value="approved">
                  <button type="submit" class="btn btn-sm btn-success"
                    <?= $art['status'] === 'approved' ? 'disabled' : '' ?>>✓ Approve</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="article_action">
                  <input type="hidden" name="article_id" value="<?= intval($art['id']) ?>">
                  <input type="hidden" name="article_action" value="rejected">
                  <button type="submit" class="btn btn-sm btn-danger"
                    <?= $art['status'] === 'rejected' ? 'disabled' : '' ?>
                    onclick="return confirm('Reject this article?');">✗ Reject</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </form>

    </div><!-- /panel-articles -->

    <!-- ═══════════════════ TAB 3 — REVENUE ═══════════════════ -->
    <div id="panel-revenue" class="tab-panel">

      <!-- Monthly Revenue Table -->
      <div class="section-box">
        <div class="section-header">Monthly Revenue (last 24 months)</div>
        <div class="section-body" style="padding:0;">
          <table class="admin-table" style="width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f8f9fa;">
                <th style="padding:10px; border:1px solid #dee2e6;">Month</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Impressions</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Clicks</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Gross Revenue</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Agency Share</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Platform Share</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($revenues)): ?>
              <tr><td colspan="6" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No revenue data found.</td></tr>
            <?php else: ?>
              <?php foreach ($revenues as $rv): ?>
              <tr>
                <td style="padding:9px 10px; border:1px solid #dee2e6;">
                  <?= htmlspecialchars($rv['revenue_date'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($rv['impressions'])) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($rv['clicks'])) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rv['gross_revenue']), 2) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rv['agency_share']), 2) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rv['platform_share']), 2) ?></td>
              </tr>
              <?php endforeach; ?>
              <tr style="background:#f0f4f8; font-weight:700;">
                <td style="padding:9px 10px; border:1px solid #dee2e6;">TOTAL</td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($rev_totals[0])) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($rev_totals[1])) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rev_totals[2]), 2) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rev_totals[3]), 2) ?></td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($rev_totals[4]), 2) ?></td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Manual Adjustment Form -->
      <div class="section-box">
        <div class="section-header">Manual Wallet Adjustment</div>
        <div class="section-body">
          <p style="font-size:13px; color:#666; margin-top:0;">
            Current wallet balance: <strong>$<?= number_format(floatval($agency['wallet_balance']), 2) ?></strong>.
            Use a positive amount to credit, negative to debit.
          </p>
          <form method="post" onsubmit="return confirm('Apply this manual adjustment to the agency wallet?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="revenue_adjust">
            <div class="form-inline-row">
              <div class="field">
                <label>Amount ($)</label>
                <input type="number" name="amount" step="0.01" placeholder="e.g. 10.00 or -5.00" required style="width:160px;">
              </div>
              <div class="field" style="flex:1;">
                <label>Note / Reason</label>
                <input type="text" name="note" required placeholder="Reason for adjustment..." style="min-width:300px;">
              </div>
              <div class="field">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Apply Adjustment</button>
              </div>
            </div>
          </form>
        </div>
      </div>

    </div><!-- /panel-revenue -->

    <!-- ═══════════════════ TAB 4 — TRANSACTIONS & WITHDRAWALS ═══════════════════ -->
    <div id="panel-transactions" class="tab-panel">

      <!-- Withdrawal Requests -->
      <div class="section-box">
        <div class="section-header">Withdrawal Requests</div>
        <div class="section-body" style="padding:0;">
          <table class="admin-table" style="width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f8f9fa;">
                <th style="padding:10px; border:1px solid #dee2e6;">Amount</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Method</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Account Details</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Status</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Requested</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($withdrawals)): ?>
              <tr><td colspan="6" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No withdrawals found.</td></tr>
            <?php else: ?>
              <?php foreach ($withdrawals as $wd): ?>
              <tr>
                <td style="padding:9px 10px; border:1px solid #dee2e6; font-weight:600;">
                  $<?= number_format(floatval($wd['amount']), 2) ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6;">
                  <?= htmlspecialchars($wd['method'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
                  <?= htmlspecialchars($wd['account_details'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6;">
                  <span class="badge badge-<?= htmlspecialchars($wd['status'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(ucfirst($wd['status']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
                  <?= htmlspecialchars($wd['requested_at'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6;">
                  <?php if ($wd['status'] === 'requested'): ?>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Approve this withdrawal request (set to processing)?');">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="withdrawal_action">
                      <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                      <input type="hidden" name="withdrawal_action" value="processing">
                      <button type="submit" class="btn btn-sm btn-success">✓ Approve</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($wd['status'] === 'processing'): ?>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Mark this withdrawal as completed?');">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="withdrawal_action">
                      <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                      <input type="hidden" name="withdrawal_action" value="completed">
                      <input type="text" name="transaction_id" placeholder="Transaction ID" required
                             style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:130px;">
                      <button type="submit" class="btn btn-sm btn-primary">✓ Complete</button>
                    </form>
                  <?php endif; ?>
                  <?php if (in_array($wd['status'], ['requested', 'processing'])): ?>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Reject this withdrawal and refund the amount?');">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="withdrawal_action">
                      <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                      <input type="hidden" name="withdrawal_action" value="rejected">
                      <input type="text" name="reject_reason" placeholder="Reason..." required
                             style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:120px;">
                      <button type="submit" class="btn btn-sm btn-danger">✗ Reject</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($wd['status'] === 'rejected' && !empty($wd['reject_reason'])): ?>
                    <span style="font-size:12px; color:#888;">Reason: <?= htmlspecialchars($wd['reject_reason'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if ($wd['status'] === 'completed' && !empty($wd['transaction_id'])): ?>
                    <span style="font-size:12px; color:#28a745;">TXN: <?= htmlspecialchars($wd['transaction_id'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Transaction History -->
      <div class="section-box">
        <div class="section-header">Transaction History (last 50)</div>
        <div class="section-body" style="padding:0;">
          <table class="admin-table" style="width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f8f9fa;">
                <th style="padding:10px; border:1px solid #dee2e6;">Date</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Type</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Amount</th>
                <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Balance After</th>
                <th style="padding:10px; border:1px solid #dee2e6;">Description</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($transactions)): ?>
              <tr><td colspan="5" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No transactions found.</td></tr>
            <?php else: ?>
              <?php foreach ($transactions as $txn): ?>
              <tr>
                <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
                  <?= htmlspecialchars($txn['created_at'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6;">
                  <span class="badge badge-<?= htmlspecialchars($txn['type'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(ucfirst($txn['type']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;
                    color:<?= floatval($txn['amount']) >= 0 ? '#28a745' : '#dc3545' ?>; font-weight:600;">
                  <?= floatval($txn['amount']) >= 0 ? '+' : '' ?><?= number_format(floatval($txn['amount']), 2) ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">
                  $<?= number_format(floatval($txn['balance_after']), 2) ?>
                </td>
                <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
                  <?= htmlspecialchars($txn['description'], ENT_QUOTES, 'UTF-8') ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /panel-transactions -->

  </div><!-- /tab-panels -->
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
