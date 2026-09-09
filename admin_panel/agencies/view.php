<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// Agency details
$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id AND role = 'agency'");
$stmt->execute([':id' => $id]);
$agency = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agency) {
    header('Location: index.php');
    exit;
}

// Aggregated stats via LEFT JOIN
$stats_sql = "SELECT
        COUNT(DISTINCT r.id) AS total_reporters,
        COUNT(n.id) AS total_news,
        SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
        SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
        COALESCE(SUM(n.views), 0) AS total_views
    FROM admin_users r
    LEFT JOIN news n ON n.reporter_id = r.id
    WHERE r.agency_id = :agency_id AND r.role = 'reporter'";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([':agency_id' => $id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Total earnings
$earn_sql = "SELECT COALESCE(SUM(vb.reporter_bonus), 0) AS total_earnings
    FROM viral_boosts vb
    INNER JOIN admin_users r ON r.id = vb.reporter_id AND r.role = 'reporter'
    WHERE r.agency_id = :agency_id";
$earn_stmt = $pdo->prepare($earn_sql);
$earn_stmt->execute([':agency_id' => $id]);
$total_earnings = (float) $earn_stmt->fetchColumn();

// Top 5 reporters
$top_sql = "SELECT r.id, r.name, r.email, r.status,
        COUNT(n.id) AS news_count,
        COALESCE(SUM(n.views), 0) AS total_views
    FROM admin_users r
    LEFT JOIN news n ON n.reporter_id = r.id
    WHERE r.agency_id = :agency_id AND r.role = 'reporter'
    GROUP BY r.id
    ORDER BY total_views DESC
    LIMIT 5";
$top_stmt = $pdo->prepare($top_sql);
$top_stmt->execute([':agency_id' => $id]);
$top_reporters = $top_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Agency</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:900px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:25px}
        .info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;margin-bottom:25px}
        .info-item label{font-weight:bold;font-size:13px;color:#888;display:block;margin-bottom:3px}
        .info-item span{font-size:15px;color:#333}
        .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:25px}
        .stat-card{background:#f8f9fa;padding:18px;border-radius:8px;text-align:center;border-left:4px solid #007bff}
        .stat-card.green{border-left-color:#28a745}.stat-card.red{border-left-color:#dc3545}
        .stat-card.purple{border-left-color:#6f42c1}.stat-card.orange{border-left-color:#fd7e14}
        .stat-card .num{font-size:26px;font-weight:bold;color:#333}
        .stat-card .lbl{font-size:13px;color:#666;margin-top:5px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px;margin-bottom:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-success{background:#28a745}
        .btn-info{background:#17a2b8}.btn-warning{background:#ffc107;color:#333}.btn-danger{background:#dc3545}
        .btn-sm{padding:4px 10px;font-size:12px}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}
    </style>
</head>
<body>
<div class="container">
    <h1>Agency: <?php echo htmlspecialchars($agency['name']); ?></h1>
    <p class="sub">
        <span class="badge badge-<?php echo htmlspecialchars($agency['status']); ?>"><?php echo htmlspecialchars(ucfirst($agency['status'])); ?></span>
        &middot; Joined <?php echo htmlspecialchars($agency['created_at']); ?>
    </p>

    <div>
        <a href="index.php" class="btn btn-secondary">← Back to List</a>
        <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-success">Edit</a>
        <a href="reporters.php?agency_id=<?php echo $id; ?>" class="btn btn-info">All Reporters</a>
        <a href="reporters_stats.php?agency_id=<?php echo $id; ?>" class="btn btn-primary">Reporter Stats</a>
        <a href="earnings.php?agency_id=<?php echo $id; ?>" class="btn btn-warning">Earnings</a>
        <?php if ($agency['status'] === 'pending'): ?>
            <a href="approve.php?id=<?php echo $id; ?>" class="btn btn-success">Approve</a>
            <a href="reject.php?id=<?php echo $id; ?>" class="btn btn-danger">Reject</a>
        <?php endif; ?>
    </div>

    <br>
    <div class="info-grid">
        <div class="info-item"><label>ID</label><span><?php echo (int)$agency['id']; ?></span></div>
        <div class="info-item"><label>Email</label><span><?php echo htmlspecialchars($agency['email']); ?></span></div>
        <div class="info-item"><label>Status</label><span><?php echo htmlspecialchars(ucfirst($agency['status'])); ?></span></div>
    </div>

    <h2 style="font-size:18px;color:#555;margin-bottom:10px">Agency Performance</h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="num"><?php echo (int)$stats['total_reporters']; ?></div>
            <div class="lbl">Total Reporters</div>
        </div>
        <div class="stat-card green">
            <div class="num"><?php echo (int)$stats['total_news']; ?></div>
            <div class="lbl">Total News</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo (int)$stats['approved_news']; ?></div>
            <div class="lbl">Approved</div>
        </div>
        <div class="stat-card red">
            <div class="num"><?php echo (int)$stats['rejected_news']; ?></div>
            <div class="lbl">Rejected</div>
        </div>
        <div class="stat-card purple">
            <div class="num"><?php echo number_format((int)$stats['total_views']); ?></div>
            <div class="lbl">Total Views</div>
        </div>
        <div class="stat-card orange">
            <div class="num"><?php echo number_format($total_earnings, 2); ?></div>
            <div class="lbl">Total Earnings</div>
        </div>
    </div>

    <h2 style="font-size:18px;color:#555;margin-bottom:10px">Top 5 Reporters by Views</h2>
    <table>
        <thead>
            <tr><th>Name</th><th>Email</th><th>Status</th><th>Articles</th><th>Views</th></tr>
        </thead>
        <tbody>
        <?php if (empty($top_reporters)): ?>
            <tr><td colspan="5" style="text-align:center">No reporters found.</td></tr>
        <?php else: ?>
            <?php foreach ($top_reporters as $r): ?>
            <tr>
                <td><a href="../reporters/view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a></td>
                <td><?php echo htmlspecialchars($r['email']); ?></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                <td><?php echo (int)$r['news_count']; ?></td>
                <td><?php echo number_format((int)$r['total_views']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
