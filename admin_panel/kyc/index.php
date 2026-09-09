<?php
/**
 * admin_panel/kyc/index.php
 * KYC Management — list, approve, reject
 */
declare(strict_types=1);
require_once __DIR__ . '/../../web/includes/config.php';
session_start();

// Basic admin auth check
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: /admin_panel/login.php');
    exit;
}

$action = $_POST['action'] ?? '';

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['approve', 'reject', 'bulk_approve'])) {
    if ($action === 'bulk_approve') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE reporter_kyc SET kyc_status='approved', approved_by=?, approved_at=NOW(), withdrawal_limit=500000.00, kyc_verified_limit=500000.00 WHERE id IN ($placeholders) AND kyc_status='pending'");
            $stmt->execute(array_merge([$_SESSION['admin_user'] ?? 'admin'], array_map('intval', $ids)));
            $_SESSION['kyc_msg'] = 'Bulk approved ' . $stmt->rowCount() . ' KYC records';
        }
    } elseif ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE reporter_kyc SET kyc_status='approved', approved_by=?, approved_at=NOW(), withdrawal_limit=500000.00, kyc_verified_limit=500000.00 WHERE id=? AND kyc_status='pending'");
        $stmt->execute([$_SESSION['admin_user'] ?? 'admin', $id]);
        $_SESSION['kyc_msg'] = 'KYC approved';
    } elseif ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Documents unclear or invalid');
        $stmt = $pdo->prepare("UPDATE reporter_kyc SET kyc_status='rejected', rejection_reason=?, approved_by=? WHERE id=? AND kyc_status='pending'");
        $stmt->execute([$reason, $_SESSION['admin_user'] ?? 'admin', $id]);
        $_SESSION['kyc_msg'] = 'KYC rejected';
    }
    header('Location: /admin_panel/kyc/index.php');
    exit;
}

$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status_filter, $valid_statuses)) $status_filter = 'pending';

$where = $status_filter !== 'all' ? 'WHERE kyc_status = ?' : 'WHERE 1';
$params = $status_filter !== 'all' ? [$status_filter] : [];
$stmt = $pdo->prepare("SELECT * FROM reporter_kyc $where ORDER BY submitted_at DESC LIMIT 100");
$stmt->execute($params);
$kyc_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$msg = $_SESSION['kyc_msg'] ?? '';
unset($_SESSION['kyc_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>KYC Management — NewsXpressLive Admin</title>
<style>
body{font-family:sans-serif;margin:20px;background:#f5f5f5}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:10px;border:1px solid #ddd;text-align:left;font-size:13px}
th{background:#333;color:#fff}
.badge{padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold}
.pending{background:#fff3cd;color:#856404}
.approved{background:#d4edda;color:#155724}
.rejected{background:#f8d7da;color:#721c24}
.btn{padding:5px 12px;border:none;border-radius:4px;cursor:pointer;font-size:12px}
.btn-approve{background:#28a745;color:#fff}
.btn-reject{background:#dc3545;color:#fff}
.msg{background:#d4edda;border:1px solid #c3e6cb;padding:10px;border-radius:4px;margin-bottom:15px}
</style>
</head>
<body>
<h2>KYC Management</h2>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="margin-bottom:15px">
Filter:
<?php foreach (['pending','approved','rejected','all'] as $s): ?>
<a href="?status=<?= $s ?>" style="margin:0 5px;<?= $status_filter===$s?'font-weight:bold':'' ?>"><?= ucfirst($s) ?></a>
<?php endforeach; ?>
</div>

<form method="POST" id="bulk_form">
<input type="hidden" name="action" value="bulk_approve">
<button type="submit" class="btn btn-approve" onclick="return confirm('Bulk approve selected?')" style="margin-bottom:10px">Bulk Approve Selected</button>

<table>
<thead><tr>
  <th><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
  <th>ID</th><th>Reporter UID</th><th>PAN (masked)</th><th>Bank</th><th>Account</th>
  <th>IFSC</th><th>Status</th><th>Submitted</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($kyc_records as $r):
  $pan = $r['pan_number'];
  $pan_masked = substr($pan,0,5).'****'.substr($pan,-1);
?>
<tr>
  <td><?php if($r['kyc_status']==='pending'): ?><input type="checkbox" class="chk" name="ids[]" value="<?= $r['id'] ?>"><?php endif; ?></td>
  <td><?= $r['id'] ?></td>
  <td><?= htmlspecialchars(substr($r['reporter_uid'],0,20)).'...' ?></td>
  <td><strong><?= htmlspecialchars($pan_masked) ?></strong><br><small><?= htmlspecialchars($r['pan_name']) ?></small></td>
  <td><?= htmlspecialchars($r['bank_name']) ?></td>
  <td><?= htmlspecialchars($r['account_number_masked']) ?><br><small><?= htmlspecialchars($r['account_holder']) ?></small></td>
  <td><?= htmlspecialchars($r['ifsc_code']) ?></td>
  <td><span class="badge <?= $r['kyc_status'] ?>"><?= ucfirst($r['kyc_status']) ?></span></td>
  <td><?= $r['submitted_at'] ?></td>
  <td>
    <?php if ($r['kyc_status']==='pending'): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="btn btn-approve" onclick="return confirm('Approve KYC?')">✓ Approve</button>
    </form>
    <form method="POST" style="display:inline;margin-left:5px">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="id" value="<?= $r['id'] ?>">
      <input type="text" name="reason" placeholder="Rejection reason" size="20" required>
      <button class="btn btn-reject" onclick="return confirm('Reject KYC?')">✗ Reject</button>
    </form>
    <?php elseif($r['kyc_status']==='rejected'): ?>
    <small style="color:#dc3545"><?= htmlspecialchars($r['rejection_reason'] ?? '') ?></small>
    <?php else: ?>
    <small style="color:#28a745">Approved by <?= htmlspecialchars($r['approved_by'] ?? '') ?></small>
    <?php endif; ?>
    <?php if ($r['pan_doc_url']): ?>
    <br><a href="/admin_panel/kyc/doc_view.php?id=<?= $r['id'] ?>" target="_blank" style="font-size:11px">📄 View Doc</a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</form>
</body>
</html>
