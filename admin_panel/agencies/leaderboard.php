<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

// Date filter
$date_filter = $_GET['period'] ?? 'all';
$date_where_news = '';
$date_where_boost = '';
if ($date_filter === '7') {
    $date_where_news = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $date_where_boost = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($date_filter === '30') {
    $date_where_news = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    $date_where_boost = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

// Sort metric
$allowed_metrics = ['total_views', 'total_news', 'total_earnings', 'reporter_count'];
$sort_by = in_array($_GET['sort'] ?? '', $allowed_metrics) ? $_GET['sort'] : 'total_views';

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count agencies
$cnt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'agency'");
$cnt->execute();
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Leaderboard query
$sql = "SELECT a.id, a.name, a.email, a.status,
            COUNT(DISTINCT r.id) AS reporter_count,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            COALESCE(SUM(n.views), 0) AS total_views,
            COALESCE(earn.total_earnings, 0) AS total_earnings
        FROM admin_users a
        LEFT JOIN admin_users r ON r.agency_id = a.id AND r.role = 'reporter'
        LEFT JOIN news n ON n.reporter_id = r.id{$date_where_news}
        LEFT JOIN (
            SELECT r2.agency_id, SUM(vb.reporter_bonus) AS total_earnings
            FROM viral_boosts vb
            INNER JOIN admin_users r2 ON r2.id = vb.reporter_id AND r2.role = 'reporter'
            WHERE 1=1{$date_where_boost}
            GROUP BY r2.agency_id
        ) earn ON earn.agency_id = a.id
        WHERE a.role = 'agency'
        GROUP BY a.id
        ORDER BY {$sort_by} DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agency Leaderboard</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1200px;margin:0 auto;background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        .date-filter{display:flex;gap:8px;margin-bottom:15px;align-items:center}
        .date-filter a,.date-filter span{padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:14px}
        .date-filter .active{background:#007bff;color:#fff;border-color:#007bff}
        .metric-filter{display:flex;gap:8px;margin-bottom:20px;align-items:center}
        .metric-filter a,.metric-filter span{padding:6px 14px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px}
        .metric-filter .active{background:#28a745;color:#fff;border-color:#28a745}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        tr:hover{background:#fafafa}
        .rank{font-size:18px;font-weight:bold;color:#007bff;text-align:center}
        .rank.gold{color:#FFD700}.rank.silver{color:#C0C0C0}.rank.bronze{color:#CD7F32}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-secondary{background:#6c757d}.btn-sm{padding:4px 10px;font-size:12px}.btn-primary{background:#007bff}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}
    </style>
</head>
<body>
<div class="container">
    <h1>Agency Leaderboard</h1>

    <a href="index.php" class="btn btn-secondary">← Back to List</a><br><br>

    <div class="date-filter">
        <strong>Period:</strong>
        <?php
        $periods = ['all' => 'All Time', '7' => 'Last 7 Days', '30' => 'Last 30 Days'];
        foreach ($periods as $pval => $plabel):
        ?>
            <?php if ($date_filter === $pval): ?>
                <span class="active"><?php echo $plabel; ?></span>
            <?php else: ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['period' => $pval, 'page' => 1]))); ?>"><?php echo $plabel; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="metric-filter">
        <strong>Rank by:</strong>
        <?php
        $metrics = ['total_views' => 'Views', 'total_news' => 'News', 'total_earnings' => 'Earnings', 'reporter_count' => 'Reporters'];
        foreach ($metrics as $mval => $mlabel):
        ?>
            <?php if ($sort_by === $mval): ?>
                <span class="active"><?php echo $mlabel; ?></span>
            <?php else: ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $mval, 'page' => 1]))); ?>"><?php echo $mlabel; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:60px;text-align:center">#</th>
                <th>Agency</th>
                <th>Status</th>
                <th>Reporters</th>
                <th>Total News</th>
                <th>Approved</th>
                <th>Total Views</th>
                <th>Earnings</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($agencies)): ?>
            <tr><td colspan="9" style="text-align:center">No agencies found.</td></tr>
        <?php else: ?>
            <?php foreach ($agencies as $idx => $a):
                $rank_num = $offset + $idx + 1;
                $rank_class = '';
                if ($rank_num === 1) $rank_class = 'gold';
                elseif ($rank_num === 2) $rank_class = 'silver';
                elseif ($rank_num === 3) $rank_class = 'bronze';
            ?>
            <tr>
                <td class="rank <?php echo $rank_class; ?>"><?php echo $rank_num; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($a['name']); ?></strong><br>
                    <small style="color:#888"><?php echo htmlspecialchars($a['email']); ?></small>
                </td>
                <td><span class="badge badge-<?php echo htmlspecialchars($a['status']); ?>"><?php echo htmlspecialchars(ucfirst($a['status'])); ?></span></td>
                <td><?php echo (int)$a['reporter_count']; ?></td>
                <td><?php echo (int)$a['total_news']; ?></td>
                <td><?php echo (int)$a['approved_news']; ?></td>
                <td><?php echo number_format((int)$a['total_views']); ?></td>
                <td style="font-weight:bold;color:#28a745"><?php echo number_format((float)$a['total_earnings'], 2); ?></td>
                <td><a href="view.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-primary">View</a></td>
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
