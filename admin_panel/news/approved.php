<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

$search_title    = trim($_GET['title'] ?? '');
$filter_reporter = trim($_GET['reporter_id'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');

$allowed_sort = ['n.title', 'n.views', 'n.created_at'];
$sort_col = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'n.created_at';
$sort_dir = (strtoupper($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = ["n.status = 'approved'"];
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

$cnt = $pdo->prepare("SELECT COUNT(*) FROM news n WHERE {$where_sql}");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

$sql = "SELECT n.id, n.title, n.views, n.created_at, n.reporter_id,
            COALESCE(r.name, 'Unknown') AS reporter_name
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approved News</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1200px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#28a745}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:13px}
        th{background:#f0f0f0}th a{color:#333;text-decoration:none}
        tr:hover{background:#fafafa}
        .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;align-items:end}
        .filters label{font-size:12px;color:#666;display:block;margin-bottom:2px}
        .filters input{padding:7px;border:1px solid #ccc;border-radius:4px;font-size:13px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}
        .btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .tab-nav{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #ddd}
        .tab-nav a{padding:10px 20px;text-decoration:none;color:#666;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px}
        .tab-nav a.active{color:#28a745;border-bottom-color:#28a745;font-weight:bold}
    </style>
</head>
<body>
<div class="container">
    <h1>Approved News (<?php echo $total; ?>)</h1>

    <div class="tab-nav">
        <a href="index.php">All News</a>
        <a href="pending.php">Pending</a>
        <a href="approved.php" class="active">Approved</a>
        <a href="rejected.php">Rejected</a>
        <a href="analytics.php">Analytics</a>
    </div>

    <form method="get" class="filters">
        <div><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($search_title); ?>" placeholder="Search..."></div>
        <div><label>Reporter ID</label><input type="number" name="reporter_id" value="<?php echo htmlspecialchars($filter_reporter); ?>" style="width:80px"></div>
        <div><label>From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
        <div><label>To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
        <div style="padding-top:18px"><button type="submit" class="btn btn-primary">Filter</button> <a href="approved.php" class="btn btn-secondary">Reset</a></div>
    </form>

    <table>
        <thead>
            <tr>
                <?php
                $cols = ['n.title' => 'Title', 'n.views' => 'Views', 'n.created_at' => 'Created'];
                foreach ($cols as $col => $label):
                    $new_dir = ($sort_col === $col && $sort_dir === 'ASC') ? 'DESC' : 'ASC';
                    $arrow = ($sort_col === $col) ? ($sort_dir === 'ASC' ? ' ▲' : ' ▼') : '';
                ?>
                    <th><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $new_dir]))); ?>"><?php echo $label . $arrow; ?></a></th>
                <?php endforeach; ?>
                <th>Reporter</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($news_list)): ?>
            <tr><td colspan="5" style="text-align:center">No approved news found.</td></tr>
        <?php else: ?>
            <?php foreach ($news_list as $n): ?>
            <tr>
                <td><?php echo htmlspecialchars(mb_strimwidth($n['title'], 0, 65, '...')); ?></td>
                <td><?php echo number_format((int)$n['views']); ?></td>
                <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                <td><a href="../reporters/view.php?id=<?php echo (int)$n['reporter_id']; ?>"><?php echo htmlspecialchars($n['reporter_name']); ?></a></td>
                <td>
                    <a href="view.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-primary">View</a>
                    <a href="edit.php?id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
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
</div>
</body>
</html>
