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
$date_where = '';
if ($date_filter === '7') {
    $date_where = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($date_filter === '30') {
    $date_where = ' AND vb.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count
$cnt_sql = "SELECT COUNT(*)
    FROM viral_boosts vb
    INNER JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
    WHERE r.agency_id = :agency_id{$date_where}";
$cnt = $pdo->prepare($cnt_sql);
$cnt->execute([':agency_id' => $agency_id]);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Fetch records
$sql = "SELECT vb.id AS boost_id, vb.reporter_bonus, vb.created_at AS boost_date,
            r.id AS reporter_id, r.name AS reporter_name
        FROM viral_boosts vb
        INNER JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
        WHERE r.agency_id = :agency_id{$date_where}
        ORDER BY vb.created_at DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':agency_id', $agency_id, PDO::PARAM_INT);
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary
$sum_sql = "SELECT COALESCE(SUM(vb.reporter_bonus), 0) AS total_earnings,
                COUNT(vb.id) AS total_boosts,
                COUNT(DISTINCT vb.reporter_id) AS unique_reporters
            FROM viral_boosts vb
            INNER JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
            WHERE r.agency_id = :agency_id{$date_where}";
$sum_stmt = $pdo->prepare($sum_sql);
$sum_stmt->execute([':agency_id' => $agency_id]);
$summary = $sum_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agency Earnings</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1000px;margin:0 auto;background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:20px}
        .summary{display:flex;gap:15px;margin-bottom:25px}
        .sum-card{background:#f8f9fa;padding:18px 20px;border-radius:8px;text-align:center;flex:1;border-top:4px solid #28a745}
        .sum-card:nth-child(2){border-top-color:#007bff}
        .sum-card:nth-child(3){border-top-color:#6f42c1}
        .sum-card .num{font-size:26px;font-weight:bold;color:#333}
        .sum-card .lbl{font-size:12px;color:#666;margin-top:5px}
        .date-filter{display:flex;gap:8px;margin-bottom:20px;align-items:center}
        .date-filter a,.date-filter span{padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:14px}
        .date-filter .active{background:#007bff;color:#fff;border-color:#007bff}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-secondary{background:#6c757d}.btn-primary{background:#007bff}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
    </style>
</head>
<body>
<div class="container">
    <h1>Earnings: <?php echo htmlspecialchars($agency['name']); ?></h1>
    <p class="sub">Agency ID: <?php echo (int)$agency['id']; ?></p>

    <a href="view.php?id=<?php echo $agency_id; ?>" class="btn btn-secondary">← Back to Agency</a>
    <a href="reporters_stats.php?agency_id=<?php echo $agency_id; ?>" class="btn btn-primary">Reporter Stats</a>
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

    <div class="summary">
        <div class="sum-card">
            <div class="num"><?php echo number_format((float)$summary['total_earnings'], 2); ?></div>
            <div class="lbl">Total Earnings</div>
        </div>
        <div class="sum-card">
            <div class="num"><?php echo (int)$summary['total_boosts']; ?></div>
            <div class="lbl">Total Viral Boosts</div>
        </div>
        <div class="sum-card">
            <div class="num"><?php echo (int)$summary['unique_reporters']; ?></div>
            <div class="lbl">Reporters with Earnings</div>
        </div>
    </div>

    <table>
        <thead>
            <tr><th>Boost ID</th><th>Reporter</th><th>Bonus Amount</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="4" style="text-align:center">No earnings records found.</td></tr>
        <?php else: ?>
            <?php foreach ($records as $rec): ?>
            <tr>
                <td><?php echo (int)$rec['boost_id']; ?></td>
                <td>
                    <a href="../reporters/view.php?id=<?php echo (int)$rec['reporter_id']; ?>"><?php echo htmlspecialchars($rec['reporter_name']); ?></a>
                </td>
                <td style="font-weight:bold;color:#28a745"><?php echo number_format((float)$rec['reporter_bonus'], 2); ?></td>
                <td><?php echo htmlspecialchars($rec['boost_date']); ?></td>
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
    <p style="margin-top:10px;color:#666;font-size:13px">Page <?php echo $page; ?>/<?php echo $total_pages; ?> &middot; <?php echo $total; ?> record(s)</p>
</div>
</body>
</html>
