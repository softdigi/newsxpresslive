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

$stmt = $pdo->prepare("SELECT id, name, email, agency_id FROM admin_users WHERE id = :id AND role = 'reporter'");
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

// Pagination
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Total count
$cnt = $pdo->prepare("SELECT COUNT(*) FROM viral_boosts WHERE reporter_id = :id");
$cnt->execute([':id' => $id]);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

// Earnings records
$sql = "SELECT id, reporter_bonus, created_at
        FROM viral_boosts
        WHERE reporter_id = :id
        ORDER BY created_at DESC
        LIMIT :lim OFFSET :off";
$e_stmt = $pdo->prepare($sql);
$e_stmt->bindValue(':id', $id, PDO::PARAM_INT);
$e_stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$e_stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$e_stmt->execute();
$records = $e_stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary
$sum_stmt = $pdo->prepare("SELECT COALESCE(SUM(reporter_bonus), 0) AS total_earnings, COUNT(*) AS total_boosts FROM viral_boosts WHERE reporter_id = :id");
$sum_stmt->execute([':id' => $id]);
$summary = $sum_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reporter Earnings</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:800px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333}
        .sub{color:#888;font-size:14px;margin-bottom:25px}
        .summary{display:flex;gap:20px;margin-bottom:25px}
        .sum-card{background:#f8f9fa;padding:20px 25px;border-radius:8px;text-align:center;flex:1}
        .sum-card .num{font-size:26px;font-weight:bold;color:#28a745}
        .sum-card .lbl{font-size:13px;color:#666;margin-top:5px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:14px}
        th{background:#f0f0f0}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-secondary{background:#6c757d}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
    </style>
</head>
<body>
<div class="container">
    <h1>Earnings: <?php echo htmlspecialchars($reporter['name']); ?></h1>
    <p class="sub"><?php echo htmlspecialchars($reporter['email']); ?></p>

    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">← Back to Profile</a>
    <a href="performance.php?id=<?php echo $id; ?>" class="btn btn-secondary">Performance</a>
    <br><br>

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
            <div class="num"><?php echo (int)$summary['total_boosts'] > 0 ? number_format((float)$summary['total_earnings'] / (int)$summary['total_boosts'], 2) : '0.00'; ?></div>
            <div class="lbl">Avg Bonus per Boost</div>
        </div>
    </div>

    <table>
        <thead>
            <tr><th>Boost ID</th><th>Bonus Amount</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="3" style="text-align:center">No earnings records found.</td></tr>
        <?php else: ?>
            <?php foreach ($records as $rec): ?>
            <tr>
                <td><?php echo (int)$rec['id']; ?></td>
                <td style="font-weight:bold;color:#28a745"><?php echo number_format((float)$rec['reporter_bonus'], 2); ?></td>
                <td><?php echo htmlspecialchars($rec['created_at']); ?></td>
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
                <a href="?id=<?php echo $id; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
</div>
</body>
</html>
