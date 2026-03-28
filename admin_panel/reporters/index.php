<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'agency'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['admin']['role'];
$admin_id = (int) $_SESSION['admin']['id'];

// Search filters
$search_name   = trim($_GET['name'] ?? '');
$search_email  = trim($_GET['email'] ?? '');
$search_status = trim($_GET['status'] ?? '');
$search_agency = trim($_GET['agency_id'] ?? '');

// Sorting
$allowed_sort = ['name', 'email', 'status', 'created_at'];
$sort_col = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'created_at';
$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Build WHERE
$where = ["role = 'reporter'"];
$params = [];

if ($role === 'agency') {
    $where[] = 'agency_id = :agency_filter';
    $params[':agency_filter'] = $admin_id;
}

if ($search_name !== '') {
    $where[] = 'name LIKE :sname';
    $params[':sname'] = '%' . $search_name . '%';
}
if ($search_email !== '') {
    $where[] = 'email LIKE :semail';
    $params[':semail'] = '%' . $search_email . '%';
}
if ($search_status !== '') {
    $where[] = 'status = :sstatus';
    $params[':sstatus'] = $search_status;
}
if ($search_agency !== '' && $role !== 'agency') {
    $where[] = 'agency_id = :sagency';
    $params[':sagency'] = (int) $search_agency;
}

$where_sql = implode(' AND ', $where);

// Count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch
$sql = "SELECT id, name, email, status, agency_id, created_at
        FROM admin_users
        WHERE {$where_sql}
        ORDER BY {$sort_col} {$sort_dir}
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$reporters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$qs = http_build_query(array_filter([
    'name' => $search_name, 'email' => $search_email,
    'status' => $search_status, 'agency_id' => $search_agency,
    'sort' => $sort_col, 'dir' => $sort_dir,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reporters Management</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1200px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0;cursor:pointer}
        th a{color:#333;text-decoration:none}
        tr:hover{background:#fafafa}
        .filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px}
        .filters input,.filters select{padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-danger{background:#dc3545}.btn-success{background:#28a745}
        .btn-secondary{background:#6c757d}.btn-warning{background:#ffc107;color:#333}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .bulk-bar{background:#f8f9fa;padding:10px;border-radius:4px;margin-bottom:15px;display:flex;gap:10px;align-items:center}
    </style>
</head>
<body>
<div class="container">
    <h1>Reporters Management</h1>

    <div class="actions">
        <?php if (in_array($role, ['admin', 'super_admin'])): ?>
            <a href="create.php" class="btn btn-primary">+ Add Reporter</a>
            <a href="bulk_actions.php" class="btn btn-warning">Bulk Actions</a>
        <?php endif; ?>
        <a href="export.php?<?php echo htmlspecialchars($qs); ?>" class="btn btn-secondary">Export CSV</a>
    </div>

    <form method="get" class="filters">
        <input type="text" name="name" placeholder="Search name..." value="<?php echo htmlspecialchars($search_name); ?>">
        <input type="text" name="email" placeholder="Search email..." value="<?php echo htmlspecialchars($search_email); ?>">
        <select name="status">
            <option value="">All Status</option>
            <option value="active" <?php echo $search_status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="blocked" <?php echo $search_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
            <option value="pending" <?php echo $search_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
        </select>
        <?php if ($role !== 'agency'): ?>
            <input type="text" name="agency_id" placeholder="Agency ID" value="<?php echo htmlspecialchars($search_agency); ?>">
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_col); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sort_dir); ?>">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>

    <form method="post" action="bulk_actions.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <table>
            <thead>
                <tr>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                        <th><input type="checkbox" id="check-all"></th>
                    <?php endif; ?>
                    <?php
                    $cols = ['name' => 'Name', 'email' => 'Email', 'status' => 'Status', 'created_at' => 'Created'];
                    foreach ($cols as $col => $label):
                        $new_dir = ($sort_col === $col && $sort_dir === 'ASC') ? 'DESC' : 'ASC';
                        $arrow = ($sort_col === $col) ? ($sort_dir === 'ASC' ? ' ▲' : ' ▼') : '';
                    ?>
                        <th><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $new_dir]))); ?>"><?php echo $label . $arrow; ?></a></th>
                    <?php endforeach; ?>
                    <th>Agency</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($reporters)): ?>
                <tr><td colspan="<?php echo in_array($role, ['admin', 'super_admin']) ? 7 : 6; ?>" style="text-align:center">No reporters found.</td></tr>
            <?php else: ?>
                <?php foreach ($reporters as $r): ?>
                <tr>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                        <td><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>"></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><?php echo htmlspecialchars($r['email']); ?></td>
                    <td>
                        <?php
                        $badge = 'badge-' . $r['status'];
                        echo '<span class="badge ' . $badge . '">' . htmlspecialchars(ucfirst($r['status'])) . '</span>';
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                    <td><?php echo (int) $r['agency_id']; ?></td>
                    <td>
                        <a href="view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-primary">View</a>
                        <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                            <a href="edit.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-success">Edit</a>
                            <a href="block.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-warning"><?php echo $r['status'] === 'blocked' ? 'Unblock' : 'Block'; ?></a>
                            <a href="delete.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this reporter?')">Delete</a>
                        <?php endif; ?>
                        <a href="performance.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-secondary">Stats</a>
                        <a href="earnings.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-secondary">Earnings</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if (in_array($role, ['admin', 'super_admin'])): ?>
        <div class="bulk-bar">
            <select name="bulk_action">
                <option value="">Bulk Action...</option>
                <option value="block">Block Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Apply bulk action?')">Apply</button>
        </div>
        <?php endif; ?>
    </form>

    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <p style="margin-top:10px;color:#666;font-size:13px">Showing <?php echo count($reporters); ?> of <?php echo $total; ?> reporters (Page <?php echo $page; ?>/<?php echo $total_pages; ?>)</p>
</div>

<script>
document.getElementById('check-all')?.addEventListener('change', function(){
    document.querySelectorAll('input[name="ids[]"]').forEach(c => c.checked = this.checked);
});
</script>
</body>
</html>
