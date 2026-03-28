<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $ids = $_POST['ids'] ?? [];
    $action = $_POST['bulk_action'] ?? '';

    $ids = array_map('intval', array_filter($ids, 'is_numeric'));
    $ids = array_filter($ids, function ($v) { return $v > 0; });

    if (empty($ids)) {
        $error = 'No articles selected.';
    } elseif (!in_array($action, ['approve', 'reject', 'delete'])) {
        $error = 'Invalid action selected.';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $id_values = array_values($ids);

        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE news SET status = 'approved' WHERE id IN ({$placeholders})");
            $stmt->execute($id_values);
            $success = $stmt->rowCount() . ' article(s) approved.';
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("UPDATE news SET status = 'rejected' WHERE id IN ({$placeholders})");
            $stmt->execute($id_values);
            $success = $stmt->rowCount() . ' article(s) rejected.';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM news WHERE id IN ({$placeholders})");
            $stmt->execute($id_values);
            $success = $stmt->rowCount() . ' article(s) deleted.';
        }
    }
}

// Standalone form — fetch pending/all news
$filter_status = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'approved', 'rejected', 'draft', 'published', 'all'];
if (!in_array($filter_status, $valid_statuses)) $filter_status = 'pending';

$per_page = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = '1=1';
$params = [];
if ($filter_status !== 'all') {
    $where = 'n.status = :fstatus';
    $params[':fstatus'] = $filter_status;
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM news n WHERE {$where}");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

$sql = "SELECT n.id, n.title, n.status, n.created_at,
            COALESCE(r.name, 'Unknown') AS reporter_name
        FROM news n
        LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
        WHERE {$where}
        ORDER BY n.created_at DESC
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
    <title>Bulk Actions - News</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:1000px;margin:0 auto;background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{padding:9px 12px;border:1px solid #ddd;text-align:left;font-size:13px}
        th{background:#f0f0f0}tr:hover{background:#fafafa}
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;color:#fff;margin-right:5px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}.btn-success{background:#28a745}
        .btn-danger{background:#dc3545}.btn-warning{background:#ffc107;color:#333}
        .btn-sm{padding:5px 12px;font-size:12px}
        .alert-success{background:#d4edda;color:#155724;padding:12px;border-radius:4px;margin-bottom:15px}
        .alert-danger{background:#f8d7da;color:#721c24;padding:12px;border-radius:4px;margin-bottom:15px}
        .action-bar{display:flex;gap:10px;align-items:center;margin:15px 0;background:#f8f9fa;padding:12px;border-radius:4px}
        .badge{padding:3px 8px;border-radius:10px;font-size:11px;color:#fff}
        .badge-approved{background:#28a745}.badge-rejected{background:#dc3545}.badge-pending{background:#ffc107;color:#333}
        .badge-draft{background:#6c757d}.badge-published{background:#17a2b8}
        .status-tabs{display:flex;gap:5px;margin-bottom:15px}
        .status-tabs a{padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px}
        .status-tabs a.active{background:#007bff;color:#fff;border-color:#007bff}
        .pagination{margin-top:15px;display:flex;gap:5px}
        .pagination a,.pagination span{padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333}
        .pagination .active{background:#007bff;color:#fff;border-color:#007bff}
    </style>
</head>
<body>
<div class="container">
    <h1>Bulk Actions — News</h1>
    <a href="index.php" class="btn btn-secondary">← Back to List</a><br><br>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="status-tabs">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Draft', 'all' => 'All'] as $sv => $sl): ?>
            <a href="?status=<?php echo $sv; ?>" class="<?php echo $filter_status === $sv ? 'active' : ''; ?>"><?php echo $sl; ?></a>
        <?php endforeach; ?>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="action-bar">
            <select name="bulk_action" required>
                <option value="">Select Action...</option>
                <option value="approve">Approve Selected</option>
                <option value="reject">Reject Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Apply this action to all selected articles?')">Apply</button>
            <span style="font-size:13px;color:#666"><?php echo $total; ?> article(s) shown</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all"></th>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Reporter</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($news_list)): ?>
                <tr><td colspan="6" style="text-align:center">No articles found.</td></tr>
            <?php else: ?>
                <?php foreach ($news_list as $n): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>"></td>
                    <td><?php echo (int)$n['id']; ?></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($n['title'], 0, 55, '...')); ?></td>
                    <td><?php echo htmlspecialchars($n['reporter_name']); ?></td>
                    <td><span class="badge badge-<?php echo htmlspecialchars($n['status']); ?>"><?php echo htmlspecialchars(ucfirst($n['status'])); ?></span></td>
                    <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </form>

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
<script>document.getElementById('check-all')?.addEventListener('change',function(){document.querySelectorAll('input[name="ids[]"]').forEach(c=>c.checked=this.checked)});</script>
</body>
</html>
