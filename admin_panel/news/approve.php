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

$stmt = $pdo->prepare("SELECT id, title, status FROM news WHERE id = :id");
$stmt->execute([':id' => $id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    header('Location: index.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($article['status'] === 'approved') {
        $error = 'This article is already approved.';
    } else {
        $upd = $pdo->prepare("UPDATE news SET status = 'approved' WHERE id = :id");
        $upd->execute([':id' => $id]);
        $success = 'Article has been approved.';
        $article['status'] = 'approved';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approve Article</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:500px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:#28a745;margin-bottom:20px}
        .info{background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-success{background:#28a745}.btn-secondary{background:#6c757d}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>Approve Article</h1>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
        <a href="index.php" class="btn btn-secondary">← Back to List</a>
        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-success">View Article</a>
    <?php elseif ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <a href="index.php" class="btn btn-secondary">← Back</a>
    <?php else: ?>
        <div class="info">
            <strong>Title:</strong> <?php echo htmlspecialchars($article['title']); ?><br>
            <strong>Current Status:</strong> <?php echo htmlspecialchars(ucfirst($article['status'])); ?><br>
            <strong>ID:</strong> <?php echo (int)$article['id']; ?>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$article['id']; ?>">
            <button type="submit" class="btn btn-success">Confirm Approve</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
