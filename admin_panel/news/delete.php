<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, title, status, reporter_id FROM news WHERE id = :id");
$stmt->execute([':id' => $id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['confirm'] ?? '') === 'yes') {
        $del = $pdo->prepare("DELETE FROM news WHERE id = :id");
        $del->execute([':id' => $id]);
        header('Location: index.php?deleted=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Article</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:500px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:#dc3545;margin-bottom:20px}
        .warning{background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:6px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-danger{background:#dc3545}.btn-secondary{background:#6c757d}
    </style>
</head>
<body>
<div class="container">
    <h1>Delete Article</h1>

    <div class="warning">
        <strong>Warning:</strong> This action is permanent.<br><br>
        <strong>Title:</strong> <?php echo htmlspecialchars($article['title']); ?><br>
        <strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($article['status'])); ?><br>
        <strong>ID:</strong> <?php echo (int)$article['id']; ?>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$article['id']; ?>">
        <input type="hidden" name="confirm" value="yes">
        <button type="submit" class="btn btn-danger">Confirm Delete</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
