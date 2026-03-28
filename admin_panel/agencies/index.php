<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';
require_once __DIR__.'/../includes/auth.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['admin']['role'];

// Search filters
$search_name   = trim($_GET['name'] ?? '');
$search_email  = trim($_GET['email'] ?? '');
$search_status = trim($_GET['status'] ?? '');

// Sorting
$allowed_sort = ['a.name', 'a.email', 'a.status', 'a.created_at', 'reporter_count'];
$sort_col = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'a.created_at';
$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Build WHERE
$where = ["a.role = 'agency'"];
$params = [];

if ($search_name !== '') {
    $where[] = 'a.name LIKE :sname';
    $params[':sname'] = '%' . $search_name . '%';
}
if ($search_email !== '') {
    $where[] = 'a.email LIKE :semail';
    $params[':semail'] = '%' . $search_email . '%';
}
if ($search_status !== '') {
    $where[] = 'a.status = :sstatus';
    $params[':sstatus'] = $search_status;
}

$where_sql = implode(' AND ', $where);

// Count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users a WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch with reporter count
$sql = "SELECT a.id, a.name, a.email, a.status, a.created_at,
            COUNT(r.id) AS reporter_count
        FROM admin_users a
        LEFT JOIN admin_users r ON r.agency_id = a.id AND r.role = 'reporter'
        WHERE {$where_sql}
        GROUP BY a.id
        ORDER BY {$sort_col} {$sort_dir}
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

$qs = http_build_query(array_filter([
    'name' => $search_name, 'email' => $search_email,
    'status' => $search_status,
    'sort' => $sort_col, 'dir' => $sort_dir,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agencies Management</title>
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
        .btn-info{background:#17a2b8}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}
    </style>
</head>
<body>
<div class="container">
    <h1>Agencies Management</h1>

    <div class="actions">
        <a href="create.php" class="btn btn-primary">+ Add Agency</a>
        <a href="leaderboard.php" class="btn btn-info">Leaderboard</a>
        <a href="export.php?<?php echo htmlspecialchars($qs); ?>" class="btn btn-secondary">Export CSV</a>
    </div>

    <form method="get" class="filters">
        <input type="text" name="name" placeholder="Search name..." value="<?php echo htmlspecialchars($search_name); ?>">
        <input type="text" name="email" placeholder="Search email..." value="<?php echo htmlspecialchars($search_email); ?>">
        <select name="status">
            <option value="">All Status</option>
            <option value="active" <?php echo $search_status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="pending" <?php echo $search_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="blocked" <?php echo $search_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
            <option value="approved" <?php echo $search_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="rejected" <?php echo $search_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_col); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sort_dir); ?>">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>

    <table>
        <thead>
            <tr>
                <?php
                $cols = [
                    'a.name' => 'Name', 'a.email' => 'Email', 'a.status' => 'Status',
                    'reporter_count' => 'Reporters', 'a.created_at' => 'Created',
                ];
                foreach ($cols as $col => $label):
                    $new_dir = ($sort_col === $col && $sort_dir === 'ASC') ? 'DESC' : 'ASC';
                    $arrow = ($sort_col === $col) ? ($sort_dir === 'ASC' ? ' ▲' : ' ▼') : '';
                ?>
                    <th><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $new_dir]))); ?>"><?php echo $label . $arrow; ?></a></th>
                <?php endforeach; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($agencies)): ?>
            <tr><td colspan="6" style="text-align:center">No agencies found.</td></tr>
        <?php else: ?>
            <?php foreach ($agencies as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['name']); ?></td>
                <td><?php echo htmlspecialchars($a['email']); ?></td>
                <td>
                    <?php
                    $badge_class = 'badge-' . $a['status'];
                    echo '<span class="badge ' . $badge_class . '">' . htmlspecialchars(ucfirst($a['status'])) . '</span>';
                    ?>
                </td>
                <td><?php echo (int)$a['reporter_count']; ?></td>
                <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                <td>
                    <a href="view.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-primary">View</a>
                    <a href="edit.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-success">Edit</a>
                    <a href="reporters.php?agency_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-info">Reporters</a>
                    <?php if ($a['status'] === 'pending'): ?>
                        <a href="approve.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-success">Approve</a>
                        <a href="reject.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
                    <?php endif; ?>
                    <a href="reporters_stats.php?agency_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-secondary">Stats</a>
                    <a href="earnings.php?agency_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-warning">Earnings</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">« Prev</a>
        <?php endif; ?>
        <?php
        $start_p = max(1, $page - 3);
        $end_p = min($total_pages, $page + 3);
        for ($i = $start_p; $i <= $end_p; $i++):
        ?>
            <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">Next »</a>
        <?php endif; ?>
    </div>
    <p style="margin-top:10px;color:#666;font-size:13px">Showing <?php echo count($agencies); ?> of <?php echo $total; ?> agencies (Page <?php echo $page; ?>/<?php echo $total_pages; ?>)</p>
</div>
</body>
</html>
