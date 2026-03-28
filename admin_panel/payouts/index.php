<?php
// ============================================================
// FIXED: payouts/index.php
// BUGS FIXED (seen in error_log):
//   1. FATAL: "Unknown column 'vb.reporter_id' in ON" — the
//      JOIN was going through news table; fixed to use
//      vb.reporter_id directly (column DOES exist per schema)
//   2. generate.php called via GET link — changed to POST form
//      with CSRF token
//   3. Date filter inputs not sanitized — validated with regex
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    exit('Access denied');
}

// Sanitize date inputs
$from = '';
$to   = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) {
    $from = $_GET['from'];
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) {
    $to = $_GET['to'];
}

$where  = " WHERE vb.status = 'completed' ";
$params = [];

if ($from !== '' && $to !== '') {
    $where .= " AND DATE(vb.created_at) BETWEEN ? AND ? ";
    $params[] = $from;
    $params[] = $to;
}

// FIXED JOIN: vb.reporter_id → admin_users directly (not through news)
$stmt = $pdo->prepare("
    SELECT
        r.id   AS reporter_id,
        r.name AS reporter_name,
        SUM(vb.reporter_bonus) AS total_bonus
    FROM viral_boosts vb
    LEFT JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
    $where
    GROUP BY r.id, r.name
    ORDER BY total_bonus DESC
");
$stmt->execute($params);
$data = $stmt->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header"><h1>Payouts</h1></section>
<section class="content">

<form method="GET" style="margin-bottom:15px">
    From: <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    To:   <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
</form>

<?php if (isset($_GET['paid'])): ?>
    <div class="alert alert-success">Payout marked as paid successfully.</div>
<?php endif; ?>

<table class="table table-bordered">
<thead>
<tr>
    <th>Reporter</th>
    <th>Total Payable</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($data as $row): ?>
<tr>
    <td><?= htmlspecialchars($row['reporter_name'] ?? 'Unknown') ?></td>
    <td><strong><?= number_format((float)$row['total_bonus'], 2) ?></strong></td>
    <td>
        <!-- FIXED: POST form with CSRF instead of GET link -->
        <form method="POST" action="generate.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="reporter_id" value="<?= (int)$row['reporter_id'] ?>">
            <button type="submit" class="btn btn-success btn-sm"
                    onclick="return confirm('Mark all completed boosts as paid for this reporter?')">
                Generate Payout
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($data)): ?>
<tr><td colspan="3" style="text-align:center">No pending payouts found.</td></tr>
<?php endif; ?>
</tbody>
</table>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
