<?php
// ============================================================
// FIXED: viral/index.php
// BUGS FIXED (seen in error_log):
//   FATAL: "Unknown column 'vb.reporter_id' in ON clause"
//   Root cause: previous code did:
//     LEFT JOIN admin_users r ON n.reporter_id = r.id
//   but vb.reporter_id exists and is the correct join.
//   The column IS in viral_boosts per schema — the old code
//   was joining through news unnecessarily and incorrectly.
// ALSO FIXED:
//   2. No pagination — loads ALL boosts (potential 100k+ rows)
//   3. Date inputs not validated
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    exit('Access denied');
}

// Sanitize filter inputs
$level  = trim($_GET['boost_level'] ?? '');
$status = trim($_GET['status'] ?? '');
$from   = '';
$to     = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) $from = $_GET['from'];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '')) $to   = $_GET['to'];

// Whitelist status
$allowed_statuses = ['active', 'completed', 'paid', 'rejected'];
if ($status !== '' && !in_array($status, $allowed_statuses)) {
    $status = '';
}

$where  = " WHERE 1=1 ";
$params = [];

if ($level !== '') {
    $where   .= " AND vb.boost_level = ? ";
    $params[] = $level;
}
if ($status !== '') {
    $where   .= " AND vb.status = ? ";
    $params[] = $status;
}
if ($from !== '' && $to !== '') {
    $where   .= " AND DATE(vb.created_at) BETWEEN ? AND ? ";
    $params[] = $from;
    $params[] = $to;
}

// Pagination
$per_page = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM viral_boosts vb $where");
$count_stmt->execute($params);
$total      = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// FIXED JOIN: use vb.reporter_id directly → admin_users
// and vb.agency_id directly → agencies table
$params_paged   = array_merge($params, [$per_page, $offset]);
$stmt = $pdo->prepare("
    SELECT
        vb.id,
        vb.boost_level,
        vb.reporter_bonus,
        vb.status,
        vb.created_at,
        n.title          AS news_title,
        r.name           AS reporter_name,
        a.name           AS agency_name
    FROM viral_boosts vb
    LEFT JOIN news        n ON n.id  = vb.news_id
    LEFT JOIN admin_users r ON r.id  = vb.reporter_id
    LEFT JOIN agencies    a ON a.id  = vb.agency_id
    $where
    ORDER BY vb.id DESC
    LIMIT ? OFFSET ?
");
$stmt->execute($params_paged);
$data = $stmt->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Viral Boosts</h1>
    <a href="create.php" class="btn btn-primary btn-sm">+ Create Boost</a>
</section>
<section class="content">
<div class="card">
<div class="card-body table-responsive">

<form method="GET" class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text"  name="boost_level" placeholder="Boost Level" value="<?= htmlspecialchars($level) ?>" style="padding:6px;border:1px solid #ccc;border-radius:4px">
    <select name="status" style="padding:6px;border:1px solid #ccc;border-radius:4px">
        <option value="">All Status</option>
        <?php foreach ($allowed_statuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
</form>

<table class="table table-bordered table-striped">
<thead>
<tr>
    <th>ID</th><th>News</th><th>Reporter</th><th>Agency</th>
    <th>Level</th><th>Bonus</th><th>Status</th><th>Date</th><th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($data as $row): ?>
<tr>
    <td><?= (int)$row['id'] ?></td>
    <td><?= htmlspecialchars($row['news_title'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['reporter_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['agency_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['boost_level']) ?></td>
    <td><?= number_format((float)$row['reporter_bonus'], 2) ?></td>
    <td><?= htmlspecialchars($row['status']) ?></td>
    <td><?= htmlspecialchars($row['created_at']) ?></td>
    <td>
        <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
        <!-- Control (complete/delete) via POST form in control.php -->
        <form method="POST" action="control.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id"     value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="action" value="complete">
            <button type="submit" class="btn btn-sm btn-success"
                    <?= $row['status'] !== 'active' ? 'disabled' : '' ?>>Done</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($data)): ?>
<tr><td colspan="9" style="text-align:center">No boosts found.</td></tr>
<?php endif; ?>
</tbody>
</table>

<!-- Pagination -->
<div style="margin-top:12px;display:flex;gap:5px">
<?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <?php if ($i === $page): ?>
        <span style="padding:5px 10px;background:#007bff;color:#fff;border-radius:4px"><?= $i ?></span>
    <?php else: ?>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"
           style="padding:5px 10px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333"><?= $i ?></a>
    <?php endif; ?>
<?php endfor; ?>
</div>
<p style="font-size:13px;color:#888;margin-top:8px"><?= $total ?> total boost(s) — Page <?= $page ?>/<?= $total_pages ?></p>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
