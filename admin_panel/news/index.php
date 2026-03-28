<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['admin']['role'];

// Filters
$search_title    = trim($_GET['title'] ?? '');
$filter_status   = trim($_GET['status'] ?? '');
$filter_reporter = trim($_GET['reporter_id'] ?? '');
$filter_agency   = trim($_GET['agency_id'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');

// Sorting
$allowed_sort = ['n.title', 'n.status', 'n.views', 'n.created_at', 'reporter_name'];
$sort_col = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'n.created_at';
$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Build WHERE
$where = ['1=1'];
$params = [];

if ($search_title !== '') {
    $where[] = 'n.title LIKE :stitle';
    $params[':stitle'] = '%' . $search_title . '%';
}
if ($filter_status !== '') {
    $where[] = 'n.status = :fstatus';
    $params[':fstatus'] = $filter_status;
}
if ($filter_reporter !== '') {
    $where[] = 'n.reporter_id = :freporter';
    $params[':freporter'] = (int) $filter_reporter;
}
if ($filter_agency !== '') {
    $where[] = 'r.agency_id = :fagency';
    $params[':fagency'] = (int) $filter_agency;
}
if ($date_from !== '') {
    $where[] = 'n.created_at >= :dfrom';
    $params[':dfrom'] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where[] = 'n.created_at <= :dto';
    $params[':dto'] = $date_to . ' 23:59:59';
}

$where_sql = implode(' AND ', $where);

// Count
$count_sql = "SELECT COUNT(*)
    FROM news n
    LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
    WHERE {$where_sql}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch
$sql = "SELECT n.id, n.title, n.status, n.views, n.created_at, n.reporter_id,
            COALESCE(r.name, 'Unknown') AS reporter_name,
            COALESCE(r.agency_id, 0) AS agency_id
        FROM news n
        LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
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
$news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$qs = http_build_query(array_filter([
    'title' => $search_title, 'status' => $filter_status,
    'reporter_id' => $filter_reporter, 'agency_id' => $filter_agency,
    'date_from' => $date_from, 'date_to' => $date_to,
    'sort' => $sort_col, 'dir' => $sort_dir,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>News Management</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1300px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:13px}
        th{background:#f0f0f0;cursor:pointer;white-space:nowrap}
        th a{color:#333;text-decoration:none}
        tr:hover{background:#fafafa}
        .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;align-items:end}
        .filters label{font-size:12px;color:#666;display:block;margin-bottom:2px}
        .filters input,.filters select{padding:7px;border:1px solid #ccc;border-radius:4px;font-size:13px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-danger{background:#dc3545}.btn-success{background:#28a745}
        .btn-secondary{background:#6c757d}.btn-warning{background:#ffc107;color:#333}.btn-info{background:#17a2b8}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px;flex-wrap:wrap}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}
        .badge{padding:3px 8px;border-radius:10px;font-size:11px;color:#fff}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-draft{background:#6c757d}.badge-published{background:#17a2b8}
        .bulk-bar{background:#f8f9fa;padding:10px;border-radius:4px;margin-bottom:15px;display:flex;gap:10px;align-items:center}
        .tab-nav{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #ddd}
        .tab-nav a{padding:10px 20px;text-decoration:none;color:#666;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px}
        .tab-nav a.active{color:#007bff;border-bottom-color:#007bff;font-weight:bold}
    </style>
</head>
<body>
<div class="container">
    <h1>News Management</h1>

    <div class="tab-nav">
        <a href="index.php" class="active">All News</a>
        <a href="pending.php">Pending</a>
        <a href="approved.php">Approved</a>
        <a href="rejected.php">Rejected</a>
        <a href="analytics.php">Analytics</a>
    </div>

    <div class="actions">
        <a href="create.php" class="btn btn-primary">+ Add News</a>
        <a href="bulk_actions.php" class="btn btn-warning">Bulk Actions</a>
        <a href="export.php?<?php echo htmlspecialchars($qs); ?>" class="btn btn-secondary">Export CSV</a>
    </div>

    <form method="get" class="filters">
        <div>
            <label>Title</label>
            <input type="text" name="title" placeholder="Search title..." value="<?php echo htmlspecialchars($search_title); ?>">
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="">All</option>
                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="published" <?php echo $filter_status === 'published' ? 'selected' : ''; ?>>Published</option>
            </select>
        </div>
        <div>
            <label>Reporter ID</label>
            <input type="number" name="reporter_id" value="<?php echo htmlspecialchars($filter_reporter); ?>" placeholder="ID" style="width:80px">
        </div>
        <div>
            <label>Agency ID</label>
            <input type="number" name="agency_id" value="<?php echo htmlspecialchars($filter_agency); ?>" placeholder="ID" style="width:80px">
        </div>
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div style="padding-top:18px">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="index.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <form method="post" action="bulk_actions.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all"></th>
                    <?php
                    $cols = ['n.title' => 'Title', 'reporter_name' => 'Reporter', 'n.status' => 'Status', 'n.views' => 'Views', 'n.created_at' => 'Created'];
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
            <?php if (empty($news_list)): ?>
                <tr><td colspan="8" style="text-align:center">No news found.</td></tr>
            <?php else: ?>
                <?php foreach ($news_list as $n): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>"></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($n['title'], 0, 60, '...')); ?></td>
                    <td>
                        <a href="../reporters/view.php?id=<?php echo (int)$n['reporter_id']; ?>"><?php echo htmlspecialchars($n['reporter_name']); ?></a>
                    </td>
                    <td><span class="badge badge-<?php echo htmlspecialchars($n['status']); ?>"><?php echo htmlspecialchars(ucfirst($n['status'])); ?></span></td>
                    <td><?php echo number_format((int)$n['views']); ?></td>
                    <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                    <td><?php echo (int)$n['agency_id']; ?></td>
                    <td style="white-space:nowrap">
                        <a href="view.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-primary">View</a>
                        <a href="edit.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-success">Edit</a>
                        <?php if ($n['status'] === 'pending'): ?>
                            <a href="approve.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-success">✓</a>
                            <a href="reject.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-danger">✗</a>
                        <?php endif; ?>
                        <a href="delete.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-danger">Del</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="bulk-bar">
            <select name="bulk_action">
                <option value="">Bulk Action...</option>
                <option value="approve">Approve Selected</option>
                <option value="reject">Reject Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Apply bulk action to selected items?')">Apply</button>
        </div>
    </form>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">« Prev</a>
        <?php endif; ?>
        <?php
        $sp = max(1, $page - 3);
        $ep = min($total_pages, $page + 3);
        for ($i = $sp; $i <= $ep; $i++):
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
    <p style="margin-top:10px;color:#666;font-size:13px">Showing <?php echo count($news_list); ?> of <?php echo $total; ?> articles (Page <?php echo $page; ?>/<?php echo $total_pages; ?>)</p>
</div>

<script>
document.getElementById('check-all')?.addEventListener('change', function(){
    document.querySelectorAll('input[name="ids[]"]').forEach(c => c.checked = this.checked);
});
</script>
</body>
</html>
