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

    // Single withdrawal action
    if ($action === 'withdrawal_action') {
        $wid  = intval($_POST['withdrawal_id'] ?? 0);
        $wact = trim($_POST['withdrawal_action'] ?? '');

        if ($wid > 0) {
            if ($wact === 'processing') {
                $pdo->prepare(
                    "UPDATE agency_withdrawals SET status='processing' WHERE id=:id AND status='requested'"
                )->execute([':id' => $wid]);
                $message = 'Withdrawal approved (set to processing).';

            } elseif ($wact === 'completed') {
                $txn_id = trim($_POST['transaction_id'] ?? '');
                $pdo->prepare(
                    "UPDATE agency_withdrawals SET status='completed', transaction_id=:txn, completed_at=NOW()
                     WHERE id=:id AND status='processing'"
                )->execute([':txn' => $txn_id, ':id' => $wid]);
                $message = 'Withdrawal marked as completed.';

            } elseif ($wact === 'rejected') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');
                $pdo->beginTransaction();
                try {
                    // Fetch withdrawal info
                    $wd_row = $pdo->prepare(
                        "SELECT agency_id, amount FROM agency_withdrawals WHERE id=:id AND status IN ('requested','processing')"
                    );
                    $wd_row->execute([':id' => $wid]);
                    $wd = $wd_row->fetch(PDO::FETCH_ASSOC);

                    if ($wd) {
                        $pdo->prepare(
                            "UPDATE agency_withdrawals SET status='rejected', reject_reason=:r WHERE id=:id"
                        )->execute([':r' => $reject_reason, ':id' => $wid]);

                        $bs = $pdo->prepare("SELECT wallet_balance FROM agencies WHERE id=:id FOR UPDATE");
                        $bs->execute([':id' => $wd['agency_id']]);
                        $old_bal = floatval($bs->fetchColumn());
                        $new_bal = $old_bal + floatval($wd['amount']);

                        $pdo->prepare("UPDATE agencies SET wallet_balance=:b WHERE id=:id")
                            ->execute([':b' => $new_bal, ':id' => $wd['agency_id']]);

                        $pdo->prepare(
                            "INSERT INTO agency_transactions (agency_id, type, amount, balance_after, description, created_at)
                             VALUES (:aid, 'credit', :amt, :bal, 'Withdrawal rejected – refund', NOW())"
                        )->execute([':aid' => $wd['agency_id'], ':amt' => $wd['amount'], ':bal' => $new_bal]);
                    }
                    $pdo->commit();
                    $message = 'Withdrawal rejected and amount refunded to agency wallet.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Failed to reject withdrawal.';
                }
            }
        }
    }

    // Bulk approve (set to processing)
    if ($action === 'bulk_approve') {
        $ids = $_POST['bulk_ids'] ?? [];
        if (!empty($ids)) {
            $safe = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
            if (!empty($safe)) {
                $ph = implode(',', array_fill(0, count($safe), '?'));
                $pdo->prepare(
                    "UPDATE agency_withdrawals SET status='processing' WHERE id IN ($ph) AND status='requested'"
                )->execute($safe);
                $message = count($safe) . ' withdrawal(s) approved.';
            }
        }
    }

    header('Location: withdrawals.php?msg=' . urlencode($message ?: $error));
    exit;
}

if (isset($_GET['msg'])) {
    $message = htmlspecialchars(trim($_GET['msg']), ENT_QUOTES, 'UTF-8');
}

// ── Fetch by status ───────────────────────────────────────────────────────────
$fetch_by_status = function (string $status) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT w.*, a.name AS agency_name
         FROM agency_withdrawals w
         JOIN agencies a ON a.id = w.agency_id
         WHERE w.status = :s
         ORDER BY w.requested_at DESC"
    );
    $stmt->execute([':s' => $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$pending    = $fetch_by_status('requested');
$processing = $fetch_by_status('processing');
$completed  = $fetch_by_status('completed');
$rejected   = $fetch_by_status('rejected');

require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .wd-section { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 28px; }
  .wd-section .wd-header {
    padding: 12px 16px; border-bottom: 1px solid #dee2e6;
    font-weight: 700; font-size: 15px; border-radius: 6px 6px 0 0;
    display: flex; align-items: center; gap: 10px;
  }
  .wd-header.pending    { background: #fff3cd; color: #856404; }
  .wd-header.processing { background: #cce5ff; color: #004085; }
  .wd-header.completed  { background: #d4edda; color: #155724; }
  .wd-header.rejected   { background: #f8d7da; color: #721c24; }
  .wd-count { font-size: 12px; padding: 2px 8px; border-radius: 10px; background: rgba(0,0,0,.1); }
  .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; color: #fff; font-weight: 600; display: inline-block; }
  .badge-requested  { background: #ffc107; color: #333; }
  .badge-processing { background: #17a2b8; }
  .badge-completed  { background: #28a745; }
  .badge-rejected   { background: #dc3545; }
  .alert-success { background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 4px; border: 1px solid #c3e6cb; margin-bottom: 16px; }
  .alert-danger  { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 16px; }
  .inline-form { display: inline; }
  .action-cell { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .action-cell input[type=text] {
    padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;
  }
</style>

<div style="padding: 20px;">
  <h2 style="margin-bottom:20px;">💸 Withdrawal Management</h2>

  <?php if ($message): ?>
    <div class="alert-success"><?= $message ?></div>
  <?php endif; ?>

  <?php
  // ── Helper: render a section ───────────────────────────────────────────────
  $render_section = function (
      string $title,
      string $status,
      array  $rows,
      bool   $show_bulk = false
  ) use ($pdo): void {
  ?>
  <div class="wd-section">
    <div class="wd-header <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
      <span class="wd-count"><?= count($rows) ?></span>
    </div>

    <?php if ($show_bulk && !empty($rows)): ?>
    <form method="post" style="padding:10px 16px; border-bottom:1px solid #dee2e6;"
          onsubmit="return confirm('Bulk approve all selected withdrawal requests (set to processing)?');">
      <?php csrf_token(); // ensure token exists ?>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="bulk_approve">
      <button type="submit" class="btn btn-success btn-sm" style="margin-right:8px;">✓ Bulk Approve Selected</button>
      <a href="#" onclick="document.querySelectorAll('.bulk-check-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>').forEach(c=>c.checked=true); return false;"
         style="font-size:13px;">Select All</a>
      &nbsp;|&nbsp;
      <a href="#" onclick="document.querySelectorAll('.bulk-check-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>').forEach(c=>c.checked=false); return false;"
         style="font-size:13px;">Deselect All</a>
    <?php endif; ?>

    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f8f9fa;">
          <?php if ($show_bulk): ?><th style="padding:10px; border:1px solid #dee2e6; width:36px;"></th><?php endif; ?>
          <th style="padding:10px; border:1px solid #dee2e6;">Agency</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Amount</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Method</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Account Details</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Requested</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr>
          <td colspan="<?= $show_bulk ? 7 : 6 ?>"
              style="text-align:center; padding:16px; border:1px solid #dee2e6; color:#888;">
            No <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?> withdrawals.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $wd): ?>
        <tr>
          <?php if ($show_bulk): ?>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:center;">
            <input type="checkbox" name="bulk_ids[]" value="<?= intval($wd['id']) ?>"
                   class="bulk-check-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
          </td>
          <?php endif; ?>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-weight:500;">
            <a href="detail.php?id=<?= intval($wd['agency_id']) ?>">
              <?= htmlspecialchars($wd['agency_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right; font-weight:700;">
            $<?= number_format(floatval($wd['amount']), 2) ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <?= htmlspecialchars($wd['method'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px; max-width:200px; word-break:break-word;">
            <?= htmlspecialchars($wd['account_details'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-size:13px;">
            <?= htmlspecialchars($wd['requested_at'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <div class="action-cell">
              <?php if ($wd['status'] === 'requested'): ?>
                <form class="inline-form" method="post"
                      onsubmit="return confirm('Approve this withdrawal (set to processing)?');">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="withdrawal_action">
                  <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                  <input type="hidden" name="withdrawal_action" value="processing">
                  <button type="submit" class="btn btn-sm btn-success">✓ Approve</button>
                </form>
              <?php endif; ?>

              <?php if ($wd['status'] === 'processing'): ?>
                <form class="inline-form" method="post"
                      onsubmit="return confirm('Mark this withdrawal as completed?');">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="withdrawal_action">
                  <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                  <input type="hidden" name="withdrawal_action" value="completed">
                  <input type="text" name="transaction_id" placeholder="Transaction ID" required style="width:130px;">
                  <button type="submit" class="btn btn-sm btn-primary">Complete</button>
                </form>
              <?php endif; ?>

              <?php if (in_array($wd['status'], ['requested', 'processing'])): ?>
                <form class="inline-form" method="post"
                      onsubmit="return confirm('Reject this withdrawal? The amount will be refunded to the agency wallet.');">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="withdrawal_action">
                  <input type="hidden" name="withdrawal_id" value="<?= intval($wd['id']) ?>">
                  <input type="hidden" name="withdrawal_action" value="rejected">
                  <input type="text" name="reject_reason" placeholder="Reason..." required style="width:110px;">
                  <button type="submit" class="btn btn-sm btn-danger">✗ Reject</button>
                </form>
              <?php endif; ?>

              <?php if ($wd['status'] === 'completed'): ?>
                <span style="font-size:12px; color:#28a745;">
                  ✓ Done<?= !empty($wd['transaction_id']) ? ' · ' . htmlspecialchars($wd['transaction_id'], ENT_QUOTES, 'UTF-8') : '' ?>
                </span>
              <?php endif; ?>
              <?php if ($wd['status'] === 'rejected' && !empty($wd['reject_reason'])): ?>
                <span style="font-size:12px; color:#dc3545;">
                  Reason: <?= htmlspecialchars($wd['reject_reason'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>

    <?php if ($show_bulk && !empty($rows)): ?>
    </form><!-- /bulk-form -->
    <?php endif; ?>
  </div>
  <?php
  };

  $render_section('⏳ Pending Requests',     'requested',  $pending,    true);
  $render_section('🔄 Processing',            'processing', $processing, false);
  $render_section('✅ Completed',             'completed',  $completed,  false);
  $render_section('❌ Rejected',              'rejected',   $rejected,   false);
  ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
