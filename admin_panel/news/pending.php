<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

// Search
$search_title    = trim($_GET['title'] ?? '');
$filter_reporter = trim($_GET['reporter_id'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');

$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = ["n.status = 'pending'"];
$params = [];

if ($search_title !== '') {
    $where[] = 'n.title LIKE :stitle';
    $params[':stitle'] = '%' . $search_title . '%';
}
if ($filter_reporter !== '') {
    $where[] = 'n.reporter_id = :freporter';
    $params[':freporter'] = (int) $filter_reporter;
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
$cnt = $pdo->prepare("SELECT COUNT(*) FROM news n WHERE {$where_sql}");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch
$sql = "SELECT n.id, n.title, n.views, n.created_at, n.reporter_id,
            COALESCE(r.name, 'Unknown') AS reporter_name,
            COALESCE(r.agency_id, 0) AS agency_id
        FROM news n
        LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
        WHERE {$where_sql}
        ORDER BY n.created_at {$sort_dir}
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pending News</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1200px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:13px}
        th{background:#f0f0f0}tr:hover{background:#fafafa}
        .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;align-items:end}
        .filters label{font-size:12px;color:#666;display:block;margin-bottom:2px}
        .filters input{padding:7px;border:1px solid #ccc;border-radius:4px;font-size:13px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-danger{background:#dc3545}.btn-success{background:#28a745}
        .btn-secondary{background:#6c757d}.btn-warning{background:#ffc107;color:#333}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .tab-nav{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #ddd}
        .tab-nav a{padding:10px 20px;text-decoration:none;color:#666;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px}
        .tab-nav a.active{color:#ffc107;border-bottom-color:#ffc107;font-weight:bold}
        .pending-count{background:#ffc107;color:#333;padding:2px 8px;border-radius:10px;font-size:12px;margin-left:5px}
    </style>
</head>
<body>
<div class="container">
    <h1>Pending News <span class="pending-count"><?php echo $total; ?></span></h1>

    <div class="tab-nav">
        <a href="index.php">All News</a>
        <a href="pending.php" class="active">Pending</a>
        <a href="approved.php">Approved</a>
        <a href="rejected.php">Rejected</a>
        <a href="analytics.php">Analytics</a>
    </div>

    <form method="get" class="filters">
        <div><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($search_title); ?>" placeholder="Search..."></div>
        <div><label>Reporter ID</label><input type="number" name="reporter_id" value="<?php echo htmlspecialchars($filter_reporter); ?>" style="width:80px"></div>
        <div><label>From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
        <div><label>To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
        <div style="padding-top:18px"><button type="submit" class="btn btn-primary">Filter</button> <a href="pending.php" class="btn btn-secondary">Reset</a></div>
    </form>

    <form method="post" action="bulk_actions.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="bulk_action" value="approve">

        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all"></th>
                    <th>Title</th>
                    <th>Reporter</th>
                    <th>Agency</th>
                    <th>Views</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($news_list)): ?>
                <tr><td colspan="7" style="text-align:center">No pending news found.</td></tr>
            <?php else: ?>
                <?php foreach ($news_list as $n): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>"></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($n['title'], 0, 60, '...')); ?></td>
                    <td><a href="../reporters/view.php?id=<?php echo (int)$n['reporter_id']; ?>"><?php echo htmlspecialchars($n['reporter_name']); ?></a></td>
                    <td><?php echo (int)$n['agency_id']; ?></td>
                    <td><?php echo number_format((int)$n['views']); ?></td>
                    <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                    <td style="white-space:nowrap">
                        <a href="view.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-primary">View</a>
                        <a href="approve.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-success">Approve</a>
                        <a href="reject.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:10px;display:flex;gap:8px">
            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve all selected?')">Bulk Approve</button>
            <button type="submit" name="bulk_action" value="reject" class="btn btn-danger btn-sm" onclick="return confirm('Reject all selected?')">Bulk Reject</button>
        </div>
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
    <p style="margin-top:10px;color:#666;font-size:13px"><?php echo $total; ?> pending article(s) — Page <?php echo $page; ?>/<?php echo $total_pages; ?></p>
</div>
<script>document.getElementById('check-all')?.addEventListener('change',function(){document.querySelectorAll('input[name="ids[]"]').forEach(c=>c.checked=this.checked)});</script>
</body>
</html>
