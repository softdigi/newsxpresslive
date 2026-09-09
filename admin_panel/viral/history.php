<?php
// ============================================================
// FIXED: viral/history.php
// BUG: JOIN to 'agencies' table — this table doesn't exist
//      in the standard schema. Agencies are stored in
//      admin_users WHERE role = 'agency'
// ALSO FIXED: date inputs not validated
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    exit('Access denied');
}

// Validate date inputs
$from = '';
$to   = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) $from = $_GET['from'];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '')) $to   = $_GET['to'];

$where  = " WHERE vb.status = 'completed' ";
$params = [];

if ($from !== '' && $to !== '') {
    $where   .= " AND DATE(vb.created_at) BETWEEN ? AND ? ";
    $params[] = $from;
    $params[] = $to;
}

// FIXED: JOIN agencies via admin_users (role='agency')
//        instead of non-existent 'agencies' table
$stmt = $pdo->prepare("
    SELECT
        vb.id,
        vb.boost_level,
        vb.reporter_bonus,
        vb.status,
        vb.created_at,
        n.title                     AS news_title,
        r.name                      AS reporter_name,
        COALESCE(ag.name, '-')      AS agency_name
    FROM viral_boosts vb
    LEFT JOIN news        n  ON n.id  = vb.news_id
    LEFT JOIN admin_users r  ON r.id  = vb.reporter_id
    LEFT JOIN admin_users ag ON ag.id = vb.agency_id AND ag.role = 'agency'
    $where
    ORDER BY vb.created_at DESC
");
$stmt->execute($params);
$data = $stmt->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header"><h1>Viral Boost History</h1></section>
<section class="content">

<form method="GET" class="mb-3" style="display:flex;gap:8px;align-items:center">
    From: <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    To:   <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="history.php" class="btn btn-secondary btn-sm">Reset</a>
</form>

<table class="table table-bordered table-striped">
<thead>
<tr>
    <th>ID</th><th>News</th><th>Reporter</th>
    <th>Agency</th><th>Level</th><th>Bonus</th><th>Date</th>
</tr>
</thead>
<tbody>
<?php foreach ($data as $row): ?>
<tr>
    <td><?= (int)$row['id'] ?></td>
    <td><?= htmlspecialchars($row['news_title'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['reporter_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['agency_name']) ?></td>
    <td><?= htmlspecialchars($row['boost_level']) ?></td>
    <td><?= number_format((float)$row['reporter_bonus'], 2) ?></td>
    <td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($data)): ?>
<tr><td colspan="7" style="text-align:center">No history found.</td></tr>
<?php endif; ?>
</tbody>
</table>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
