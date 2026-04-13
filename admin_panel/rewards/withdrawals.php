<?php
/**
 * admin_panel/rewards/withdrawals.php
 *
 * Reward Withdrawal Management — admin + super_admin.
 * Status filter tabs, bulk action, Razorpay payout API integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!can(['admin', 'super_admin'])) {
    http_response_code(403);
    die('<h2>Access Denied.</h2>');
}

$adminUid = (string)$_SESSION['admin']['id'];

// ── Razorpay payout ───────────────────────────────────────────────────────────
function razorpayPayout(int $withdrawalId, string $upiId, float $amount, PDO $pdo): array
{
    $keyId     = getenv('RAZORPAY_KEY_ID')     ?: '';
    $keySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';

    if (!$keyId || !$keySecret) {
        return ['success' => false, 'error' => 'Razorpay credentials not configured'];
    }

    $payload = json_encode([
        'account_number' => getenv('RAZORPAY_PAYOUT_ACCOUNT') ?: '',
        'fund_account'   => [
            'account_type' => 'vpa',
            'vpa'          => ['address' => $upiId],
            'contact'      => [
                'name'         => 'NewsXpressLive User',
                'email'        => 'rewards@newsxpresslive.com',
                'contact'      => '9999999999',
                'type'         => 'customer',
            ],
        ],
        'amount'         => (int)round($amount * 100), // paise
        'currency'       => 'INR',
        'mode'           => 'UPI',
        'purpose'        => 'payout',
        'queue_if_low_balance' => false,
        'reference_id'   => 'NXL_WD_' . $withdrawalId,
        'narration'      => 'NewsXpressLive Reward Withdrawal',
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/payouts');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => "{$keyId}:{$keySecret}",
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => "cURL error: {$curlErr}"];
    }

    $data = json_decode($response ?: '{}', true);

    if ($httpCode === 200 || $httpCode === 201) {
        $payoutId = $data['id'] ?? '';
        $utr      = $data['utr'] ?? $data['id'] ?? '';

        // Update withdrawal record (user_uid is the app user's Firebase UID)
        $pdo->prepare(
            "UPDATE reward_withdrawals
             SET status='completed', transaction_ref=?, processed_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([$utr, $withdrawalId]);

        // Debit inr_wallet total_withdrawn (user_id in inr_wallets = Firebase UID)
        $wStmt = $pdo->prepare("SELECT user_uid FROM reward_withdrawals WHERE id=?");
        $wStmt->execute([$withdrawalId]);
        $uid = $wStmt->fetchColumn();
        if ($uid) {
            $pdo->prepare(
                "UPDATE inr_wallets SET total_withdrawn=total_withdrawn+?, balance=balance-?, updated_at=NOW() WHERE user_id=?"
            )->execute([(float)$amount, (float)$amount, $uid]);

            $pdo->prepare(
                "INSERT INTO reward_transactions (user_id, wallet_type, transaction_type, amount, reference_id, status, created_at)
                 VALUES (?, 'inr', 'withdrawal', ?, ?, 'completed', NOW())"
            )->execute([$uid, $amount, $withdrawalId]);
        }

        return ['success' => true, 'payout_id' => $payoutId, 'utr' => $utr];
    }

    $errorMsg = $data['error']['description'] ?? $data['error']['code'] ?? 'Unknown Razorpay error';
    return ['success' => false, 'error' => $errorMsg, 'code' => $httpCode];
}

$flash     = '';
$flashType = 'success';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';
    $wId    = (int)($_POST['withdrawal_id'] ?? 0);

    if ($action === 'mark_processing' && $wId > 0) {
        $pdo->prepare(
            "UPDATE reward_withdrawals SET status='processing', admin_note=?, updated_at=NOW() WHERE id=? AND status='pending'"
        )->execute(["Marked processing by admin {$adminUid}", $wId]);
        $flash = '✅ Withdrawal marked as Processing.';

    } elseif ($action === 'mark_completed' && $wId > 0) {
        $utr = trim($_POST['utr'] ?? '');
        if (!$utr) {
            $flash = '⚠️ UTR number required.';
            $flashType = 'warning';
        } else {
            $pdo->prepare(
                "UPDATE reward_withdrawals SET status='completed', transaction_ref=?, processed_at=NOW(), updated_at=NOW() WHERE id=?"
            )->execute([$utr, $wId]);

            $wRow = $pdo->prepare("SELECT user_uid, amount_inr FROM reward_withdrawals WHERE id=?");
            $wRow->execute([$wId]);
            $wData = $wRow->fetch();
            if ($wData) {
                $pdo->prepare(
                    "UPDATE inr_wallets SET total_withdrawn=total_withdrawn+?, balance=balance-?, updated_at=NOW() WHERE user_id=?"
                )->execute([(float)$wData['amount_inr'], (float)$wData['amount_inr'], $wData['user_uid']]);
                $pdo->prepare(
                    "INSERT INTO reward_transactions (user_id, wallet_type, transaction_type, amount, reference_id, status, created_at)
                     VALUES (?, 'inr', 'withdrawal', ?, ?, 'completed', NOW())"
                )->execute([$wData['user_uid'], $wData['amount_inr'], $wId]);
            }
            $flash = "✅ Withdrawal #{$wId} marked Completed. UTR: {$utr}";
        }

    } elseif ($action === 'reject' && $wId > 0) {
        $note = trim($_POST['admin_note'] ?? 'Rejected by admin');
        $pdo->prepare(
            "UPDATE reward_withdrawals SET status='rejected', admin_note=?, processed_at=NOW(), updated_at=NOW() WHERE id=?"
        )->execute([$note, $wId]);

        // Refund balance
        $wRow = $pdo->prepare("SELECT user_uid, amount_inr FROM reward_withdrawals WHERE id=?");
        $wRow->execute([$wId]);
        $wData = $wRow->fetch();
        if ($wData) {
            $pdo->prepare(
                "UPDATE inr_wallets SET balance=balance+?, updated_at=NOW() WHERE user_id=?"
            )->execute([(float)$wData['amount_inr'], $wData['user_uid']]);
        }
        $flash = "✅ Withdrawal #{$wId} rejected.";

    } elseif ($action === 'mark_failed' && $wId > 0) {
        $note = trim($_POST['admin_note'] ?? 'Payment failed');
        $pdo->prepare(
            "UPDATE reward_withdrawals SET status='failed', admin_note=?, updated_at=NOW() WHERE id=?"
        )->execute([$note, $wId]);

        $wRow = $pdo->prepare("SELECT user_uid, amount_inr FROM reward_withdrawals WHERE id=?");
        $wRow->execute([$wId]);
        $wData = $wRow->fetch();
        if ($wData) {
            $pdo->prepare(
                "UPDATE inr_wallets SET balance=balance+?, updated_at=NOW() WHERE user_id=?"
            )->execute([(float)$wData['amount_inr'], $wData['user_uid']]);
        }
        $flash = "✅ Withdrawal #{$wId} marked as Failed. Balance refunded.";

    } elseif ($action === 'razorpay_payout' && $wId > 0) {
        $wRow = $pdo->prepare("SELECT * FROM reward_withdrawals WHERE id=? AND status='processing'");
        $wRow->execute([$wId]);
        $wData = $wRow->fetch();
        if ($wData) {
            $result = razorpayPayout($wId, $wData['upi_id'], (float)$wData['amount_inr'], $pdo);
            if ($result['success']) {
                $flash = "✅ Razorpay payout successful! UTR: " . ($result['utr'] ?? $result['payout_id']);
            } else {
                $flash     = "❌ Razorpay payout failed: " . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8');
                $flashType = 'error';
                $pdo->prepare(
                    "UPDATE reward_withdrawals SET admin_note=? WHERE id=?"
                )->execute([$result['error'], $wId]);
            }
        } else {
            $flash     = "⚠️ Withdrawal not found or not in Processing status.";
            $flashType = 'warning';
        }

    } elseif ($action === 'razorpay_payout' && $wId > 0) {
        $wRow = $pdo->prepare("SELECT * FROM reward_withdrawals WHERE id=? AND status='processing'");
        $wRow->execute([$wId]);
        $wData = $wRow->fetch();
        if ($wData) {
            $result = razorpayPayout($wId, $wData['upi_id'], (float)$wData['amount_inr'], $pdo);
            if ($result['success']) {
                $flash = "✅ Razorpay payout successful! UTR: " . ($result['utr'] ?? $result['payout_id']);
            } else {
                $flash     = "❌ Razorpay payout failed: " . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8');
                $flashType = 'error';
                $pdo->prepare(
                    "UPDATE reward_withdrawals SET admin_note=? WHERE id=?"
                )->execute([$result['error'], $wId]);
            }
        } else {
            $flash     = "⚠️ Withdrawal not found or not in Processing status.";
            $flashType = 'warning';
        }

    } elseif ($action === 'bulk_process') {
        $ids = array_map('intval', $_POST['bulk_ids'] ?? []);
        $ok  = 0;
        $fail = 0;
        foreach ($ids as $id) {
            if ($id <= 0) continue;
            $wRow = $pdo->prepare("SELECT * FROM reward_withdrawals WHERE id=? AND status='processing'");
            $wRow->execute([$id]);
            $wData = $wRow->fetch();
            if ($wData) {
                $result = razorpayPayout($id, $wData['upi_id'], (float)$wData['amount_inr'], $pdo);
                $result['success'] ? $ok++ : $fail++;
            }
        }
        $flash = "✅ Bulk payout: {$ok} succeeded, {$fail} failed.";
        $flashType = $fail > 0 ? 'warning' : 'success';
    }

    header('Location: withdrawals.php?flash=' . urlencode($flash) . '&type=' . $flashType);
    exit;
}

if (isset($_GET['flash'])) {
    $flash     = htmlspecialchars($_GET['flash'], ENT_QUOTES, 'UTF-8');
    $flashType = htmlspecialchars($_GET['type'] ?? 'success', ENT_QUOTES, 'UTF-8');
}

// ── Status tab filter ─────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'pending', 'processing', 'completed', 'failed', 'rejected'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'all';

$where  = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where   .= ' AND rw.status = ?';
    $params[] = $statusFilter;
}

// ── Counts per status ─────────────────────────────────────────────────────────
$countsStmt = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM reward_withdrawals GROUP BY status"
);
$statusCounts = [];
foreach ($countsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}
$statusCounts['all'] = array_sum($statusCounts);

// ── Fetch withdrawals ─────────────────────────────────────────────────────────
$pageNum  = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;
$offset   = ($pageNum - 1) * $perPage;

$total    = (int)$pdo->prepare("SELECT COUNT(*) FROM reward_withdrawals rw WHERE {$where}")
                     ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM reward_withdrawals rw WHERE {$where}")->execute($params) : 0;
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM reward_withdrawals rw WHERE {$where}");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$dataStmt = $pdo->prepare(
    "SELECT rw.*,
            iw.balance AS current_balance,
            rk.kyc_status
     FROM reward_withdrawals rw
     LEFT JOIN inr_wallets iw ON iw.user_id = rw.user_uid
     LEFT JOIN reporter_kyc rk ON rk.user_uid = rw.user_uid
     WHERE {$where}
     ORDER BY rw.requested_at DESC
     LIMIT {$offset}, {$perPage}"
);
$dataStmt->execute($params);
$withdrawals = $dataStmt->fetchAll();

$highlight = (int)($_GET['highlight'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.status-tabs { display:flex; gap:0; border-bottom:2px solid #e0e0e0; margin-bottom:18px; }
.st-tab { padding:9px 16px; font-size:13px; text-decoration:none; color:#666; border-bottom:3px solid transparent; margin-bottom:-2px; white-space:nowrap; }
.st-tab:hover { color:#E50914; }
.st-tab.active { color:#E50914; border-bottom-color:#E50914; font-weight:600; }
.st-count { background:#e0e0e0; border-radius:10px; padding:1px 7px; font-size:11px; margin-left:5px; }
.st-tab.active .st-count { background:#E50914; color:#fff; }

.wd-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.wd-table th { background:#f9f9f9; padding:10px 14px; text-align:left; font-size:12px; color:#666; border-bottom:2px solid #eee; }
.wd-table td { padding:10px 14px; font-size:13px; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
.wd-table tr.highlighted td { background:#fff8e1; }
.wd-table tr:hover td { background:#fafafa; }

.badge-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge-pending    { background:#fff8e1; color:#E65100; }
.badge-processing { background:#e3f2fd; color:#1565C0; }
.badge-completed  { background:#e8f5e9; color:#2E7D32; }
.badge-failed     { background:#ffebee; color:#c62828; }
.badge-rejected   { background:#f3e5f5; color:#6A1B9A; }

.btn-sm { padding:4px 10px; border-radius:4px; font-size:12px; border:none; cursor:pointer; margin-right:4px; text-decoration:none; display:inline-block; }
.btn-process  { background:#1976D2; color:#fff; }
.btn-success  { background:#4CAF50; color:#fff; }
.btn-danger   { background:#f44336; color:#fff; }
.btn-warning  { background:#FF9800; color:#fff; }
.btn-razorpay { background:#3395FF; color:#fff; }

.flash-msg { padding:12px 18px; border-radius:5px; margin-bottom:14px; font-size:14px; }
.flash-success { background:#e8f5e9; color:#2E7D32; border:1px solid #81C784; }
.flash-warning  { background:#fff8e1; color:#E65100; border:1px solid #FFB300; }
.flash-error    { background:#ffebee; color:#c62828; border:1px solid #e57373; }

.bulk-bar { background:#f5f5f5; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:flex; align-items:center; gap:12px; }
.utr-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; justify-content:center; align-items:center; }
.utr-modal.open { display:flex; }
.utr-box { background:#fff; border-radius:8px; padding:24px; min-width:340px; box-shadow:0 4px 20px rgba(0,0,0,.2); }
.utr-box h3 { margin:0 0 16px; }
.utr-input { width:100%; padding:9px 12px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; }
.note-input { width:100%; padding:9px 12px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; margin-top:8px; }
</style>

<!-- UTR Entry Modal -->
<div class="utr-modal" id="utrModal">
  <div class="utr-box">
    <h3>💳 Enter UTR Number</h3>
    <form method="POST" action="withdrawals.php">
      <input type="hidden" name="action" value="mark_completed">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="withdrawal_id" id="utrWithdrawalId" value="">
      <input class="utr-input" type="text" name="utr" placeholder="UTR / Transaction Reference" required>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button type="submit" class="btn-sm btn-success" style="padding:8px 16px;font-size:13px">✅ Confirm</button>
        <button type="button" class="btn-sm btn-danger" style="padding:8px 16px;font-size:13px" onclick="closeUtrModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Modal -->
<div class="utr-modal" id="rejectModal">
  <div class="utr-box">
    <h3>❌ Reject Withdrawal</h3>
    <form method="POST" action="withdrawals.php">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="withdrawal_id" id="rejectWithdrawalId" value="">
      <textarea class="note-input" name="admin_note" rows="3" placeholder="Reason for rejection (shown to user)..." required></textarea>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button type="submit" class="btn-sm btn-danger" style="padding:8px 16px;font-size:13px">Reject</button>
        <button type="button" class="btn-sm" style="padding:8px 16px;font-size:13px;background:#ccc" onclick="closeRejectModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div style="padding:20px">
  <h1 style="margin:0 0 18px;font-size:22px;">💳 Withdrawal Requests</h1>

  <?php if ($flash): ?>
  <div class="flash-msg flash-<?= $flashType ?>">
    <?= $flash ?>
  </div>
  <?php endif; ?>

  <!-- Status Tabs -->
  <div class="status-tabs">
    <?php
    $tabs = [
        'all'        => 'All',
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'completed'  => 'Completed',
        'failed'     => 'Failed',
        'rejected'   => 'Rejected',
    ];
    foreach ($tabs as $tabKey => $tabLabel):
        $cnt = $statusCounts[$tabKey] ?? 0;
    ?>
    <a href="withdrawals.php?status=<?= $tabKey ?>"
       class="st-tab <?= $statusFilter === $tabKey ? 'active' : '' ?>">
      <?= $tabLabel ?>
      <span class="st-count"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Bulk Action Bar (for processing tab) -->
  <?php if ($statusFilter === 'processing' && !empty($withdrawals)): ?>
  <form method="POST" action="withdrawals.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_process">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="bulk-bar">
      <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"> <label for="selectAll" style="font-size:13px">Select All</label>
      <button type="submit" class="btn-sm btn-razorpay" style="padding:6px 14px;font-size:13px"
              onclick="return confirm('Process all selected via Razorpay?')">
        🔵 Process All via Razorpay
      </button>
      <span style="font-size:12px;color:#888"><span id="selectedCount">0</span> selected</span>
    </div>
  <?php endif; ?>

  <!-- Table -->
  <table class="wd-table">
    <thead>
      <tr>
        <?php if ($statusFilter === 'processing'): ?>
        <th><input type="checkbox" id="selectAllHead" onchange="toggleSelectAll(this)"></th>
        <?php else: ?>
        <th>#</th>
        <?php endif; ?>
        <th>User</th>
        <th>Amount</th>
        <th>UPI ID</th>
        <th>Aadhaar</th>
        <th>Requested</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($withdrawals)): ?>
      <tr><td colspan="9" style="text-align:center;color:#bbb;padding:30px">No withdrawals found.</td></tr>
    <?php else: ?>
      <?php foreach ($withdrawals as $i => $w): ?>
      <tr class="<?= $highlight === (int)$w['id'] ? 'highlighted' : '' ?>">
        <?php if ($statusFilter === 'processing'): ?>
        <td>
          <input type="checkbox" name="bulk_ids[]" value="<?= (int)$w['id'] ?>"
                 class="bulk-check" onchange="updateCount()">
        </td>
        <?php else: ?>
        <td style="color:#aaa;font-size:12px"><?= ($offset + $i + 1) ?></td>
        <?php endif; ?>

        <td>
          <strong style="font-size:13px"><?= htmlspecialchars(substr($w['user_uid'], 0, 16), ENT_QUOTES) ?></strong><br>
          <span style="font-size:11px;color:#aaa"><?= htmlspecialchars(substr($w['user_uid'], 0, 20), ENT_QUOTES) ?>…</span>
        </td>
        <td>
          <strong style="color:#2E7D32;font-size:14px">₹<?= number_format((float)$w['amount_inr'], 2) ?></strong>
          <?php if (!empty($w['current_balance'])): ?>
          <br><span style="font-size:11px;color:#aaa">Bal: ₹<?= number_format((float)$w['current_balance'], 2) ?></span>
          <?php endif; ?>
        </td>
        <td style="font-family:monospace;font-size:13px"><?= htmlspecialchars($w['upi_id'], ENT_QUOTES) ?></td>
        <td style="text-align:center">
          <?php $kycDone = !empty($w['kyc_status']) && $w['kyc_status'] === 'approved'; ?>
          <?php if ($kycDone): ?>
            <span style="color:#4CAF50;font-size:16px" title="Aadhaar Verified">✔</span>
          <?php else: ?>
            <span style="color:#ccc;font-size:16px" title="Not Verified">✖</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;white-space:nowrap">
          <?= date('d M Y', strtotime($w['requested_at'])) ?><br>
          <span style="color:#aaa"><?= date('H:i', strtotime($w['requested_at'])) ?></span>
        </td>
        <td>
          <span class="badge-pill badge-<?= $w['status'] ?>"><?= ucfirst($w['status']) ?></span>
          <?php if (!empty($w['admin_note'])): ?>
          <br><span style="font-size:11px;color:#999"><?= htmlspecialchars(substr($w['admin_note'], 0, 30), ENT_QUOTES) ?>…</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($w['status'] === 'pending'): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="mark_processing">
              <input type="hidden" name="withdrawal_id" value="<?= (int)$w['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <button type="submit" class="btn-sm btn-process">▶ Mark Processing</button>
            </form>
            <button class="btn-sm btn-danger" onclick="openRejectModal(<?= (int)$w['id'] ?>)">✖ Reject</button>

          <?php elseif ($w['status'] === 'processing'): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="razorpay_payout">
              <input type="hidden" name="withdrawal_id" value="<?= (int)$w['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <button type="submit" class="btn-sm btn-razorpay"
                      onclick="return confirm('Initiate Razorpay payout for ₹<?= number_format((float)$w['amount_inr'], 2) ?>?')">
                💳 Razorpay Pay
              </button>
            </form>
            <button class="btn-sm btn-success" onclick="openUtrModal(<?= (int)$w['id'] ?>)">✔ Manual UTR</button>
            <button class="btn-sm btn-warning" onclick="openRejectModal(<?= (int)$w['id'] ?>)">✖ Reject</button>

          <?php else: ?>
            <span style="color:#bbb;font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($statusFilter === 'processing' && !empty($withdrawals)): ?>
  </form>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:18px">
    <?php if ($pageNum > 1): ?>
    <a href="?status=<?= $statusFilter ?>&page=<?= $pageNum-1 ?>" style="padding:7px 13px;border:1px solid #ddd;border-radius:4px;text-decoration:none;font-size:13px">‹ Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1, $pageNum-2); $p <= min($totalPages, $pageNum+2); $p++): ?>
    <a href="?status=<?= $statusFilter ?>&page=<?= $p ?>"
       style="padding:7px 13px;border:1px solid #ddd;border-radius:4px;text-decoration:none;font-size:13px;<?= $p===$pageNum ? 'background:#E50914;color:#fff;border-color:#E50914' : '' ?>">
      <?= $p ?>
    </a>
    <?php endfor; ?>
    <?php if ($pageNum < $totalPages): ?>
    <a href="?status=<?= $statusFilter ?>&page=<?= $pageNum+1 ?>" style="padding:7px 13px;border:1px solid #ddd;border-radius:4px;text-decoration:none;font-size:13px">Next ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<script>
function openUtrModal(id) {
  document.getElementById('utrWithdrawalId').value = id;
  document.getElementById('utrModal').classList.add('open');
}
function closeUtrModal() {
  document.getElementById('utrModal').classList.remove('open');
}
function openRejectModal(id) {
  document.getElementById('rejectWithdrawalId').value = id;
  document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
  document.getElementById('rejectModal').classList.remove('open');
}
function toggleSelectAll(masterCb) {
  document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = masterCb.checked);
  updateCount();
}
function updateCount() {
  const n = document.querySelectorAll('.bulk-check:checked').length;
  const el = document.getElementById('selectedCount');
  if (el) el.textContent = n;
}
<?php if ($highlight): ?>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.querySelector('tr.highlighted');
  if (el) el.scrollIntoView({behavior:'smooth', block:'center'});
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
