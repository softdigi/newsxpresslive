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

$sql = "SELECT u.*,
            COUNT(n.id) AS total_news,
            SUM(CASE WHEN n.status = 'approved' THEN 1 ELSE 0 END) AS approved_news,
            SUM(CASE WHEN n.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_news,
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

// Earnings
$earn_stmt = $pdo->prepare("SELECT COALESCE(SUM(reporter_bonus), 0) AS total_earnings FROM viral_boosts WHERE reporter_id = :id");
$earn_stmt->execute([':id' => $id]);
$earnings = $earn_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Reporter</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:800px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:25px}
        .info-item label{font-weight:bold;font-size:13px;color:#888;display:block;margin-bottom:3px}
        .info-item span{font-size:15px;color:#333}
        .stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:25px}
        .stat-card{background:#f8f9fa;padding:15px;border-radius:6px;text-align:center}
        .stat-card .num{font-size:24px;font-weight:bold;color:#007bff}
        .stat-card .lbl{font-size:12px;color:#666;margin-top:5px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-success{background:#28a745}.btn-danger{background:#dc3545}.btn-warning{background:#ffc107;color:#333}
        .badge{padding:4px 10px;border-radius:12px;font-size:13px;color:#fff}
        .badge-active{background:#28a745}.badge-blocked{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
    </style>
</head>
<body>
<div class="container">
    <h1>Reporter Profile</h1>
    <a href="index.php" class="btn btn-secondary">← Back to List</a>
    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
        <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-success">Edit</a>
        <a href="block.php?id=<?php echo $id; ?>" class="btn btn-warning"><?php echo $reporter['status'] === 'blocked' ? 'Unblock' : 'Block'; ?></a>
        <a href="transfer_agency.php?id=<?php echo $id; ?>" class="btn btn-primary">Transfer Agency</a>
        <a href="verify.php?id=<?php echo $id; ?>" class="btn btn-success">Verify</a>
    <?php endif; ?>
    <br><br>

    <div class="info-grid">
        <div class="info-item"><label>ID</label><span><?php echo (int)$reporter['id']; ?></span></div>
        <div class="info-item"><label>Name</label><span><?php echo htmlspecialchars($reporter['name']); ?></span></div>
        <div class="info-item"><label>Email</label><span><?php echo htmlspecialchars($reporter['email']); ?></span></div>
        <div class="info-item"><label>Status</label><span class="badge badge-<?php echo htmlspecialchars($reporter['status']); ?>"><?php echo htmlspecialchars(ucfirst($reporter['status'])); ?></span></div>
        <div class="info-item"><label>Agency ID</label><span><?php echo (int)$reporter['agency_id']; ?></span></div>
        <div class="info-item"><label>Joined</label><span><?php echo htmlspecialchars($reporter['created_at']); ?></span></div>
    </div>

    <h2 style="font-size:18px;color:#555;margin-bottom:10px">Performance Overview</h2>
    <div class="stats-grid">
        <div class="stat-card"><div class="num"><?php echo (int)$reporter['total_news']; ?></div><div class="lbl">Total News</div></div>
        <div class="stat-card"><div class="num"><?php echo (int)$reporter['approved_news']; ?></div><div class="lbl">Approved</div></div>
        <div class="stat-card"><div class="num"><?php echo (int)$reporter['rejected_news']; ?></div><div class="lbl">Rejected</div></div>
        <div class="stat-card"><div class="num"><?php echo number_format((int)$reporter['total_views']); ?></div><div class="lbl">Total Views</div></div>
        <div class="stat-card"><div class="num"><?php echo number_format((float)$earnings['total_earnings'], 2); ?></div><div class="lbl">Earnings</div></div>
    </div>

    <a href="performance.php?id=<?php echo $id; ?>" class="btn btn-primary">Full Performance</a>
    <a href="earnings.php?id=<?php echo $id; ?>" class="btn btn-success">Earnings Detail</a>
</div>
</body>
</html>
