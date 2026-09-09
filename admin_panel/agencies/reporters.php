<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$agency_id = (int) ($_GET['agency_id'] ?? 0);
if ($agency_id <= 0) {
    header('Location: index.php');
    exit;
}

// Verify agency exists
$ag_stmt = $pdo->prepare("SELECT id, name FROM admin_users WHERE id = :id AND role = 'agency'");
$ag_stmt->execute([':id' => $agency_id]);
$agency = $ag_stmt->fetch(PDO::FETCH_ASSOC);

if (!$agency) {
    header('Location: index.php');
    exit;
}

// Filters
$search_name   = trim($_GET['name'] ?? '');
$search_status = trim($_GET['status'] ?? '');

// Sorting
$allowed_sort = ['r.name', 'r.email', 'r.status', 'r.created_at', 'total_views'];
$sort_col = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'r.created_at';
$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = ["r.agency_id = :agency_id", "r.role = 'reporter'"];
$params = [':agency_id' => $agency_id];

if ($search_name !== '') {
    $where[] = 'r.name LIKE :sname';
    $params[':sname'] = '%' . $search_name . '%';
}
if ($search_status !== '') {
    $where[] = 'r.status = :sstatus';
    $params[':sstatus'] = $search_status;
}

$where_sql = implode(' AND ', $where);

// Count
$cnt = $pdo->prepare("SELECT COUNT(*) FROM admin_users r WHERE {$where_sql}");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch with stats
$sql = "SELECT r.id, r.name, r.email, r.status, r.created_at,
            COUNT(n.id) AS news_count,
            COALESCE(SUM(n.views), 0) AS total_views
        FROM admin_users r
        LEFT JOIN news n ON n.reporter_id = r.id
        WHERE {$where_sql}
        GROUP BY r.id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agency Reporters</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1100px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:20px}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}th a{color:#333;text-decoration:none}
        tr:hover{background:#fafafa}
        .filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px}
        .filters input,.filters select{padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-success{background:#28a745}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
    </style>
</head>
<body>
<div class="container">
    <h1>Reporters for: <?php echo htmlspecialchars($agency['name']); ?></h1>
    <p class="sub">Agency ID: <?php echo (int)$agency['id']; ?> &middot; <?php echo $total; ?> reporter(s)</p>

    <a href="view.php?id=<?php echo $agency_id; ?>" class="btn btn-secondary">← Back to Agency</a>
    <a href="reporters_stats.php?agency_id=<?php echo $agency_id; ?>" class="btn btn-primary">Reporter Stats</a>
    <br><br>

    <form method="get" class="filters">
        <input type="hidden" name="agency_id" value="<?php echo $agency_id; ?>">
        <input type="text" name="name" placeholder="Search name..." value="<?php echo htmlspecialchars($search_name); ?>">
        <select name="status">
            <option value="">All Status</option>
            <option value="active" <?php echo $search_status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="blocked" <?php echo $search_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
            <option value="pending" <?php echo $search_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="reporters.php?agency_id=<?php echo $agency_id; ?>" class="btn btn-secondary">Reset</a>
    </form>

    <table>
        <thead>
            <tr>
                <?php
                $cols = [
                    'r.name' => 'Name', 'r.email' => 'Email', 'r.status' => 'Status',
                    'total_views' => 'Views', 'r.created_at' => 'Joined',
                ];
                foreach ($cols as $col => $label):
                    $new_dir = ($sort_col === $col && $sort_dir === 'ASC') ? 'DESC' : 'ASC';
                    $arrow = ($sort_col === $col) ? ($sort_dir === 'ASC' ? ' ▲' : ' ▼') : '';
                ?>
                    <th><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $new_dir]))); ?>"><?php echo $label . $arrow; ?></a></th>
                <?php endforeach; ?>
                <th>Articles</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($reporters)): ?>
            <tr><td colspan="7" style="text-align:center">No reporters found for this agency.</td></tr>
        <?php else: ?>
            <?php foreach ($reporters as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['email']); ?></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                <td><?php echo number_format((int)$r['total_views']); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo (int)$r['news_count']; ?></td>
                <td>
                    <a href="../reporters/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-primary">View</a>
                    <a href="../reporters/performance.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-success">Stats</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <p style="margin-top:10px;color:#666;font-size:13px">Page <?php echo $page; ?>/<?php echo $total_pages; ?></p>
</div>
</body>
</html>
