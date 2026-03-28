<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$sql = "SELECT n.*,
            COALESCE(r.name, 'Unknown') AS reporter_name,
            COALESCE(r.email, '') AS reporter_email,
            COALESCE(r.agency_id, 0) AS agency_id,
            COALESCE(r.status, '') AS reporter_status
        FROM news n
        LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
        WHERE n.id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    header('Location: index.php');
    exit;
}

// Viral boost info
$boost_stmt = $pdo->prepare("SELECT COALESCE(SUM(reporter_bonus), 0) AS boost_earnings, COUNT(*) AS boost_count FROM viral_boosts WHERE reporter_id = :rid");
$boost_stmt->execute([':rid' => (int)$article['reporter_id']]);
$boost = $boost_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Article</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:900px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:5px;color:#333;font-size:22px}
        .meta-bar{display:flex;gap:15px;flex-wrap:wrap;margin:10px 0 25px;font-size:13px;color:#888}
        .meta-bar span{display:flex;align-items:center;gap:4px}
        .badge{padding:3px 10px;border-radius:12px;font-size:12px;color:#fff}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-draft{background:#6c757d}.badge-published{background:#17a2b8}
        .badge-breaking{background:#ff4444;animation:pulse 1.5s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
        .content-area{background:#f8f9fa;padding:20px;border-radius:6px;margin-bottom:25px;line-height:1.7;font-size:15px;white-space:pre-wrap;word-wrap:break-word}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:25px}
        .info-item label{font-weight:bold;font-size:12px;color:#888;display:block;margin-bottom:3px}
        .info-item span{font-size:14px;color:#333}
        .stats-row{display:flex;gap:15px;margin-bottom:25px}
        .stat-box{background:#f8f9fa;padding:15px 20px;border-radius:6px;text-align:center;flex:1}
        .stat-box .num{font-size:22px;font-weight:bold;color:#007bff}
        .stat-box .lbl{font-size:12px;color:#666;margin-top:3px}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px;margin-bottom:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-success{background:#28a745}
        .btn-danger{background:#dc3545}.btn-warning{background:#ffc107;color:#333}
    </style>
</head>
<body>
<div class="container">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <h1><?php echo htmlspecialchars($article['title']); ?></h1>
        <?php if (!empty($article['is_breaking'])): ?>
            <span class="badge badge-breaking">BREAKING</span>
        <?php endif; ?>
    </div>

    <div class="meta-bar">
        <span><span class="badge badge-<?php echo htmlspecialchars($article['status']); ?>"><?php echo htmlspecialchars(ucfirst($article['status'])); ?></span></span>
        <span>By: <a href="../reporters/view.php?id=<?php echo (int)$article['reporter_id']; ?>"><?php echo htmlspecialchars($article['reporter_name']); ?></a></span>
        <span>Agency: #<?php echo (int)$article['agency_id']; ?></span>
        <span>Created: <?php echo htmlspecialchars($article['created_at']); ?></span>
        <span>ID: #<?php echo (int)$article['id']; ?></span>
    </div>

    <div>
        <a href="index.php" class="btn btn-secondary">← Back</a>
        <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-success">Edit</a>
        <?php if ($article['status'] === 'pending'): ?>
            <a href="approve.php?id=<?php echo $id; ?>" class="btn btn-success">Approve</a>
            <a href="reject.php?id=<?php echo $id; ?>" class="btn btn-danger">Reject</a>
        <?php elseif ($article['status'] === 'rejected'): ?>
            <a href="approve.php?id=<?php echo $id; ?>" class="btn btn-success">Re-Approve</a>
        <?php endif; ?>
        <a href="delete.php?id=<?php echo $id; ?>" class="btn btn-danger" onclick="return confirm('Delete this article?')">Delete</a>
    </div>

    <br>
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo number_format((int)$article['views']); ?></div><div class="lbl">Views</div></div>
        <div class="stat-box"><div class="num"><?php echo (int)$boost['boost_count']; ?></div><div class="lbl">Viral Boosts</div></div>
        <div class="stat-box"><div class="num"><?php echo number_format((float)$boost['boost_earnings'], 2); ?></div><div class="lbl">Reporter Earnings</div></div>
    </div>

    <h3 style="font-size:16px;color:#555;margin-bottom:8px">Article Content</h3>
    <div class="content-area"><?php echo htmlspecialchars($article['content'] ?? 'No content available.'); ?></div>

    <h3 style="font-size:16px;color:#555;margin-bottom:8px">Reporter Info</h3>
    <div class="info-grid">
        <div class="info-item"><label>Reporter Name</label><span><?php echo htmlspecialchars($article['reporter_name']); ?></span></div>
        <div class="info-item"><label>Reporter Email</label><span><?php echo htmlspecialchars($article['reporter_email']); ?></span></div>
        <div class="info-item"><label>Reporter Status</label><span><?php echo htmlspecialchars(ucfirst($article['reporter_status'])); ?></span></div>
        <div class="info-item"><label>Agency ID</label><span><?php echo (int)$article['agency_id']; ?></span></div>
    </div>
</div>
</body>
</html>
