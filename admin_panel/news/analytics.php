<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

// Date filter
$date_filter = $_GET['period'] ?? 'all';
$date_where = '';
if ($date_filter === '7') {
    $date_where = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($date_filter === '30') {
    $date_where = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($date_filter === '90') {
    $date_where = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
}

$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$custom_params = [];
if ($date_from !== '' && $date_to !== '') {
    $date_where = ' AND n.created_at >= :dfrom AND n.created_at <= :dto';
    $custom_params[':dfrom'] = $date_from . ' 00:00:00';
    $custom_params[':dto'] = $date_to . ' 23:59:59';
    $date_filter = 'custom';
}

// Main aggregates
$agg_sql = "SELECT
        COUNT(*) AS total_articles,
        COALESCE(SUM(n.views), 0) AS total_views,
        SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN n.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN n.is_breaking = 1 THEN 1 ELSE 0 END) AS breaking_count,
        COALESCE(AVG(n.views), 0) AS avg_views
    FROM news n
    WHERE 1=1{$date_where}";
$agg_stmt = $pdo->prepare($agg_sql);
$agg_stmt->execute($custom_params);
$agg = $agg_stmt->fetch(PDO::FETCH_ASSOC);

// Top 10 articles by views
$top_sql = "SELECT n.id, n.title, n.views, n.status, n.created_at,
                COALESCE(r.name, 'Unknown') AS reporter_name
            FROM news n
            LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
            WHERE 1=1{$date_where}
            ORDER BY n.views DESC
            LIMIT 10";
$top_stmt = $pdo->prepare($top_sql);
$top_stmt->execute($custom_params);
$top_articles = $top_stmt->fetchAll(PDO::FETCH_ASSOC);

// Top 10 reporters by article count
$top_rep_sql = "SELECT r.id, r.name, r.email,
                    COUNT(n.id) AS article_count,
                    COALESCE(SUM(n.views), 0) AS total_views,
                    SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved
                FROM admin_users r
                LEFT JOIN news n ON n.reporter_id = r.id{$date_where}
                WHERE r.role = 'reporter'
                GROUP BY r.id
                HAVING article_count > 0
                ORDER BY article_count DESC
                LIMIT 10";
$top_rep_stmt = $pdo->prepare($top_rep_sql);
$top_rep_stmt->execute($custom_params);
$top_reporters = $top_rep_stmt->fetchAll(PDO::FETCH_ASSOC);

// Status distribution for chart data
$total_articles = max(1, (int)$agg['total_articles']);
$approved_pct = round(((int)$agg['approved_count'] / $total_articles) * 100, 1);
$rejected_pct = round(((int)$agg['rejected_count'] / $total_articles) * 100, 1);
$pending_pct = round(((int)$agg['pending_count'] / $total_articles) * 100, 1);
$other_pct = round(100 - $approved_pct - $rejected_pct - $pending_pct, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>News Analytics</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1200px;margin:0 auto;background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}h2{font-size:18px;color:#555;margin:25px 0 10px}
        .tab-nav{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #ddd}
        .tab-nav a{padding:10px 20px;text-decoration:none;color:#666;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px}
        .tab-nav a.active{color:#6f42c1;border-bottom-color:#6f42c1;font-weight:bold}
        .date-filter{display:flex;gap:8px;margin-bottom:20px;align-items:center;flex-wrap:wrap}
        .date-filter a,.date-filter span{padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:14px}
        .date-filter .active{background:#6f42c1;color:#fff;border-color:#6f42c1}
        .date-filter input{padding:7px;border:1px solid #ccc;border-radius:4px;font-size:13px}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:30px}
        .stat-card{padding:20px;border-radius:8px;text-align:center;color:#fff}
        .stat-card .num{font-size:30px;font-weight:bold}
        .stat-card .lbl{font-size:13px;margin-top:5px;opacity:.9}
        .bg-blue{background:linear-gradient(135deg,#007bff,#0056b3)}
        .bg-green{background:linear-gradient(135deg,#28a745,#1e7e34)}
        .bg-red{background:linear-gradient(135deg,#dc3545,#bd2130)}
        .bg-orange{background:linear-gradient(135deg,#fd7e14,#e8590c)}
        .bg-purple{background:linear-gradient(135deg,#6f42c1,#5a32a3)}
        .bg-teal{background:linear-gradient(135deg,#17a2b8,#117a8b)}
        .bg-yellow{background:linear-gradient(135deg,#ffc107,#d39e00);color:#333}
        .dist-bar{display:flex;height:30px;border-radius:6px;overflow:hidden;margin-bottom:10px}
        .dist-bar div{display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;color:#fff}
        .dist-legend{display:flex;gap:20px;font-size:13px;margin-bottom:25px}
        .dist-legend span{display:flex;align-items:center;gap:5px}
        .dist-legend .dot{width:12px;height:12px;border-radius:50%;display:inline-block}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:9px 12px;border:1px solid #ddd;text-align:left;font-size:13px}
        th{background:#f0f0f0}tr:hover{background:#fafafa}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-secondary{background:#6c757d}.btn-primary{background:#007bff}
        .btn-sm{padding:4px 10px;font-size:12px}
        .badge{padding:3px 8px;border-radius:10px;font-size:11px;color:#fff}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-draft{background:#6c757d}.badge-published{background:#17a2b8}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:25px}
    </style>
</head>
<body>
<div class="container">
    <h1>News Analytics</h1>

    <div class="tab-nav">
        <a href="index.php">All News</a>
        <a href="pending.php">Pending</a>
        <a href="approved.php">Approved</a>
        <a href="rejected.php">Rejected</a>
        <a href="analytics.php" class="active">Analytics</a>
    </div>

    <div class="date-filter">
        <strong>Period:</strong>
        <?php
        $periods = ['all' => 'All Time', '7' => '7 Days', '30' => '30 Days', '90' => '90 Days'];
        foreach ($periods as $pv => $pl):
        ?>
            <?php if ($date_filter === $pv): ?>
                <span class="active"><?php echo $pl; ?></span>
            <?php else: ?>
                <a href="?period=<?php echo $pv; ?>"><?php echo $pl; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <span style="color:#ccc">|</span>
        <form method="get" style="display:flex;gap:5px;align-items:center">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <span>to</span>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <button type="submit" class="btn btn-primary btn-sm">Go</button>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card bg-blue">
            <div class="num"><?php echo number_format((int)$agg['total_articles']); ?></div>
            <div class="lbl">Total Articles</div>
        </div>
        <div class="stat-card bg-purple">
            <div class="num"><?php echo number_format((int)$agg['total_views']); ?></div>
            <div class="lbl">Total Views</div>
        </div>
        <div class="stat-card bg-green">
            <div class="num"><?php echo number_format((int)$agg['approved_count']); ?></div>
            <div class="lbl">Approved</div>
        </div>
        <div class="stat-card bg-red">
            <div class="num"><?php echo number_format((int)$agg['rejected_count']); ?></div>
            <div class="lbl">Rejected</div>
        </div>
        <div class="stat-card bg-yellow">
            <div class="num"><?php echo number_format((int)$agg['pending_count']); ?></div>
            <div class="lbl">Pending</div>
        </div>
        <div class="stat-card bg-orange">
            <div class="num"><?php echo number_format((int)$agg['breaking_count']); ?></div>
            <div class="lbl">Breaking News</div>
        </div>
        <div class="stat-card bg-teal">
            <div class="num"><?php echo number_format((float)$agg['avg_views'], 0); ?></div>
            <div class="lbl">Avg Views/Article</div>
        </div>
        <div class="stat-card bg-blue">
            <div class="num"><?php echo $approved_pct; ?>%</div>
            <div class="lbl">Approval Rate</div>
        </div>
    </div>

    <h2>Status Distribution</h2>
    <div class="dist-bar">
        <div style="width:<?php echo $approved_pct; ?>%;background:#28a745"><?php echo $approved_pct > 5 ? $approved_pct . '%' : ''; ?></div>
        <div style="width:<?php echo $pending_pct; ?>%;background:#ffc107;color:#333"><?php echo $pending_pct > 5 ? $pending_pct . '%' : ''; ?></div>
        <div style="width:<?php echo $rejected_pct; ?>%;background:#dc3545"><?php echo $rejected_pct > 5 ? $rejected_pct . '%' : ''; ?></div>
        <div style="width:<?php echo max(0, $other_pct); ?>%;background:#6c757d"><?php echo $other_pct > 5 ? $other_pct . '%' : ''; ?></div>
    </div>
    <div class="dist-legend">
        <span><span class="dot" style="background:#28a745"></span> Approved (<?php echo (int)$agg['approved_count']; ?>)</span>
        <span><span class="dot" style="background:#ffc107"></span> Pending (<?php echo (int)$agg['pending_count']; ?>)</span>
        <span><span class="dot" style="background:#dc3545"></span> Rejected (<?php echo (int)$agg['rejected_count']; ?>)</span>
        <span><span class="dot" style="background:#6c757d"></span> Other</span>
    </div>

    <div class="two-col">
        <div>
            <h2>Top 10 Articles by Views</h2>
            <table>
                <thead><tr><th>#</th><th>Title</th><th>Views</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($top_articles as $idx => $a): ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($a['title'], 0, 40, '...')); ?></td>
                    <td><strong><?php echo number_format((int)$a['views']); ?></strong></td>
                    <td><span class="badge badge-<?php echo htmlspecialchars($a['status']); ?>"><?php echo htmlspecialchars(ucfirst($a['status'])); ?></span></td>
                    <td><a href="view.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-primary">View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div>
            <h2>Top 10 Reporters by Output</h2>
            <table>
                <thead><tr><th>#</th><th>Reporter</th><th>Articles</th><th>Approved</th><th>Views</th></tr></thead>
                <tbody>
                <?php foreach ($top_reporters as $idx => $r): ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td>
                        <a href="../reporters/view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a>
                    </td>
                    <td><?php echo (int)$r['article_count']; ?></td>
                    <td><?php echo (int)$r['approved']; ?></td>
                    <td><?php echo number_format((int)$r['total_views']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
