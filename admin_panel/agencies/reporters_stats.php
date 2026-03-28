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

// Verify agency
$ag_stmt = $pdo->prepare("SELECT id, name FROM admin_users WHERE id = :id AND role = 'agency'");
$ag_stmt->execute([':id' => $agency_id]);
$agency = $ag_stmt->fetch(PDO::FETCH_ASSOC);

if (!$agency) {
    header('Location: index.php');
    exit;
}

// Date filter
$date_filter = $_GET['period'] ?? 'all';
$date_where_news = '';
$date_where_boost = '';
$date_params = [];

if ($date_filter === '7') {
    $date_where_news = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $date_where_boost = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($date_filter === '30') {
    $date_where_news = ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    $date_where_boost = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count reporters
$cnt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE agency_id = :agency_id AND role = 'reporter'");
$cnt->execute([':agency_id' => $agency_id]);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Per-reporter stats with date filter
$sql = "SELECT r.id, r.name, r.email, r.status,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
            COALESCE(SUM(n.views), 0) AS total_views
        FROM admin_users r
        LEFT JOIN news n ON n.reporter_id = r.id{$date_where_news}
        WHERE r.agency_id = :agency_id AND r.role = 'reporter'
        GROUP BY r.id
        ORDER BY total_views DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':agency_id', $agency_id, PDO::PARAM_INT);
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$reporters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Earnings per reporter with date filter
$reporter_ids = array_column($reporters, 'id');
$earnings_map = [];
if (!empty($reporter_ids)) {
    $placeholders = implode(',', array_fill(0, count($reporter_ids), '?'));
    $e_sql = "SELECT reporter_id, COALESCE(SUM(reporter_bonus), 0) AS total_earnings
              FROM viral_boosts vb
              WHERE reporter_id IN ({$placeholders}){$date_where_boost}
              GROUP BY reporter_id";
    $e_stmt = $pdo->prepare($e_sql);
    $e_stmt->execute(array_values($reporter_ids));
    while ($row = $e_stmt->fetch(PDO::FETCH_ASSOC)) {
        $earnings_map[(int)$row['reporter_id']] = (float)$row['total_earnings'];
    }
}

// Aggregate totals for the agency
$agg_sql = "SELECT
        COUNT(DISTINCT r.id) AS agg_reporters,
        COUNT(n.id) AS agg_news,
        COALESCE(SUM(n.views), 0) AS agg_views
    FROM admin_users r
    LEFT JOIN news n ON n.reporter_id = r.id{$date_where_news}
    WHERE r.agency_id = :agency_id AND r.role = 'reporter'";
$agg_stmt = $pdo->prepare($agg_sql);
$agg_stmt->execute([':agency_id' => $agency_id]);
$agg = $agg_stmt->fetch(PDO::FETCH_ASSOC);

$agg_earn_sql = "SELECT COALESCE(SUM(vb.reporter_bonus), 0) AS agg_earnings
    FROM viral_boosts vb
    INNER JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
    WHERE r.agency_id = :agency_id{$date_where_boost}";
$agg_earn_stmt = $pdo->prepare($agg_earn_sql);
$agg_earn_stmt->execute([':agency_id' => $agency_id]);
$agg_earnings = (float) $agg_earn_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reporter Stats - <?php echo htmlspecialchars($agency['name']); ?></title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1100px;margin:0 auto;background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:20px}
        .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:25px}
        .sum-card{background:#f8f9fa;padding:18px;border-radius:8px;text-align:center;border-top:4px solid #007bff}
        .sum-card.green{border-top-color:#28a745}.sum-card.purple{border-top-color:#6f42c1}.sum-card.orange{border-top-color:#fd7e14}
        .sum-card .num{font-size:28px;font-weight:bold;color:#333}
        .sum-card .lbl{font-size:12px;color:#666;margin-top:5px}
        .date-filter{display:flex;gap:8px;margin-bottom:20px;align-items:center}
        .date-filter a,.date-filter span{padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:14px}
        .date-filter .active{background:#007bff;color:#fff;border-color:#007bff}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        tr:hover{background:#fafafa}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-sm{padding:4px 10px;font-size:12px}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .progress-bar{background:#e9ecef;border-radius:8px;height:14px;overflow:hidden;width:100px;display:inline-block;vertical-align:middle}
        .progress-fill{height:100%;border-radius:8px;background:#28a745}
    </style>
</head>
<body>
<div class="container">
    <h1>Reporter Stats: <?php echo htmlspecialchars($agency['name']); ?></h1>
    <p class="sub">Agency ID: <?php echo (int)$agency['id']; ?></p>

    <a href="view.php?id=<?php echo $agency_id; ?>" class="btn btn-secondary">← Back to Agency</a>
    <a href="reporters.php?agency_id=<?php echo $agency_id; ?>" class="btn btn-primary">Reporter List</a>
    <br><br>

    <div class="date-filter">
        <strong>Period:</strong>
        <?php
        $periods = ['all' => 'All Time', '7' => 'Last 7 Days', '30' => 'Last 30 Days'];
        foreach ($periods as $pval => $plabel):
        ?>
            <?php if ($date_filter === $pval): ?>
                <span class="active"><?php echo $plabel; ?></span>
            <?php else: ?>
                <a href="?agency_id=<?php echo $agency_id; ?>&period=<?php echo $pval; ?>"><?php echo $plabel; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="summary-grid">
        <div class="sum-card">
            <div class="num"><?php echo (int)$agg['agg_reporters']; ?></div>
            <div class="lbl">Total Reporters</div>
        </div>
        <div class="sum-card green">
            <div class="num"><?php echo (int)$agg['agg_news']; ?></div>
            <div class="lbl">Total News</div>
        </div>
        <div class="sum-card purple">
            <div class="num"><?php echo number_format((int)$agg['agg_views']); ?></div>
            <div class="lbl">Total Views</div>
        </div>
        <div class="sum-card orange">
            <div class="num"><?php echo number_format($agg_earnings, 2); ?></div>
            <div class="lbl">Total Earnings</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Reporter</th>
                <th>Status</th>
                <th>Total News</th>
                <th>Approved</th>
                <th>Rejected</th>
                <th>Approval Rate</th>
                <th>Views</th>
                <th>Earnings</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($reporters)): ?>
            <tr><td colspan="9" style="text-align:center">No reporter data found.</td></tr>
        <?php else: ?>
            <?php foreach ($reporters as $r):
                $r_total = (int)$r['total_news'];
                $r_approved = (int)$r['approved_news'];
                $r_rate = $r_total > 0 ? round(($r_approved / $r_total) * 100, 1) : 0;
                $r_earnings = $earnings_map[(int)$r['id']] ?? 0;
            ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
                    <small style="color:#888"><?php echo htmlspecialchars($r['email']); ?></small>
                </td>
                <td><span class="badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                <td><?php echo $r_total; ?></td>
                <td><?php echo $r_approved; ?></td>
                <td><?php echo (int)$r['rejected_news']; ?></td>
                <td>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $r_rate; ?>%"></div></div>
                    <?php echo $r_rate; ?>%
                </td>
                <td><?php echo number_format((int)$r['total_views']); ?></td>
                <td style="font-weight:bold;color:#28a745"><?php echo number_format($r_earnings, 2); ?></td>
                <td><a href="../reporters/performance.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-primary">Detail</a></td>
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
    <p style="margin-top:10px;color:#666;font-size:13px">Page <?php echo $page; ?>/<?php echo $total_pages; ?> &middot; <?php echo $total; ?> reporter(s)</p>
</div>
</body>
</html>
