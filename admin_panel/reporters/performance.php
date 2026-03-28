<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'agency'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['admin']['role'];
$admin_id = (int) $_SESSION['admin']['id'];
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// Fetch reporter with LEFT JOIN stats
$sql = "SELECT u.id, u.name, u.email, u.status, u.agency_id, u.created_at,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
            SUM(CASE WHEN n.status = 'pending' THEN 1 ELSE 0 END) AS pending_news,
            COALESCE(SUM(n.views), 0) AS total_views
        FROM admin_users u
        LEFT JOIN news n ON n.reporter_id = u.id
        WHERE u.id = :id AND u.role = 'reporter'
        GROUP BY u.id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$reporter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporter) {
    header('Location: index.php');
    exit;
}

if ($role === 'agency' && (int) $reporter['agency_id'] !== $admin_id) {
    header('Location: index.php');
    exit;
}

// Total earnings from viral_boosts
$earn_stmt = $pdo->prepare("SELECT COALESCE(SUM(reporter_bonus), 0) AS total_earnings FROM viral_boosts WHERE reporter_id = :id");
$earn_stmt->execute([':id' => $id]);
$total_earnings = (float) $earn_stmt->fetchColumn();

// Approval rate
$total = (int) $reporter['total_news'];
$approved = (int) $reporter['approved_news'];
$approval_rate = $total > 0 ? round(($approved / $total) * 100, 1) : 0;

// Avg views per approved article
$avg_views = $approved > 0 ? round((int)$reporter['total_views'] / $approved) : 0;

// Recent news
$recent_stmt = $pdo->prepare("SELECT id, status, views, created_at FROM news WHERE reporter_id = :id ORDER BY created_at DESC LIMIT 10");
$recent_stmt->execute([':id' => $id]);
$recent_news = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reporter Performance</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:900px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:25px}
        .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:30px}
        .stat-card{background:#f8f9fa;padding:20px;border-radius:8px;text-align:center;border-left:4px solid #007bff}
        .stat-card.green{border-left-color:#28a745}
        .stat-card.red{border-left-color:#dc3545}
        .stat-card.yellow{border-left-color:#ffc107}
        .stat-card.purple{border-left-color:#6f42c1}
        .stat-card .num{font-size:28px;font-weight:bold;color:#333}
        .stat-card .lbl{font-size:13px;color:#666;margin-top:5px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-secondary{background:#6c757d}.btn-primary{background:#007bff}
        .badge{padding:3px 8px;border-radius:10px;font-size:12px;color:#fff}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .progress-bar{background:#e9ecef;border-radius:10px;height:20px;overflow:hidden;margin-top:10px}
        .progress-fill{height:100%;border-radius:10px;background:#28a745;transition:width .3s}
    </style>
</head>
<body>
<div class="container">
    <h1>Performance: <?php echo htmlspecialchars($reporter['name']); ?></h1>
    <p class="sub"><?php echo htmlspecialchars($reporter['email']); ?> &middot; Agency #<?php echo (int)$reporter['agency_id']; ?> &middot; Joined <?php echo htmlspecialchars($reporter['created_at']); ?></p>

    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">← Back to Profile</a>
    <a href="earnings.php?id=<?php echo $id; ?>" class="btn btn-primary">View Earnings</a>
    <br><br>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="num"><?php echo $total; ?></div>
            <div class="lbl">Total News Articles</div>
        </div>
        <div class="stat-card green">
            <div class="num"><?php echo $approved; ?></div>
            <div class="lbl">Approved Articles</div>
        </div>
        <div class="stat-card red">
            <div class="num"><?php echo (int)$reporter['rejected_news']; ?></div>
            <div class="lbl">Rejected Articles</div>
        </div>
        <div class="stat-card yellow">
            <div class="num"><?php echo (int)$reporter['pending_news']; ?></div>
            <div class="lbl">Pending Articles</div>
        </div>
        <div class="stat-card purple">
            <div class="num"><?php echo number_format((int)$reporter['total_views']); ?></div>
            <div class="lbl">Total Views</div>
        </div>
        <div class="stat-card green">
            <div class="num"><?php echo number_format($total_earnings, 2); ?></div>
            <div class="lbl">Total Earnings</div>
        </div>
    </div>

    <h3 style="margin-bottom:5px">Approval Rate: <?php echo $approval_rate; ?>%</h3>
    <div class="progress-bar">
        <div class="progress-fill" style="width:<?php echo $approval_rate; ?>%"></div>
    </div>
    <p style="font-size:13px;color:#888;margin-top:8px">Average views per approved article: <?php echo number_format($avg_views); ?></p>

    <h3 style="margin-top:30px">Recent Articles (Last 10)</h3>
    <table>
        <thead>
            <tr><th>News ID</th><th>Status</th><th>Views</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php if (empty($recent_news)): ?>
            <tr><td colspan="4" style="text-align:center">No articles found.</td></tr>
        <?php else: ?>
            <?php foreach ($recent_news as $n): ?>
            <tr>
                <td><?php echo (int)$n['id']; ?></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($n['status']); ?>"><?php echo htmlspecialchars(ucfirst($n['status'])); ?></span></td>
                <td><?php echo number_format((int)$n['views']); ?></td>
                <td><?php echo htmlspecialchars($n['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
