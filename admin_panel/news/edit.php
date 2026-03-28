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

$stmt = $pdo->prepare("SELECT * FROM news WHERE id = :id");
$stmt->execute([':id' => $id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title       = trim($_POST['title'] ?? '');
    $content     = trim($_POST['content'] ?? '');
    $reporter_id = (int) ($_POST['reporter_id'] ?? 0);
    $status      = in_array($_POST['status'] ?? '', ['pending', 'approved', 'rejected', 'draft', 'published']) ? $_POST['status'] : $article['status'];
    $is_breaking = isset($_POST['is_breaking']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';
    if ($content === '') $errors[] = 'Content is required.';

    if (empty($errors)) {
        $upd = $pdo->prepare("UPDATE news SET title = :title, content = :content, reporter_id = :reporter_id, status = :status, is_breaking = :is_breaking WHERE id = :id");
        $upd->execute([
            ':title'       => $title,
            ':content'     => $content,
            ':reporter_id' => $reporter_id,
            ':status'      => $status,
            ':is_breaking' => $is_breaking,
            ':id'          => $id,
        ]);
        $success = 'Article updated successfully.';

        $stmt = $pdo->prepare("SELECT * FROM news WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit News</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:700px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{margin-bottom:20px;color:#333}
        label{display:block;margin-bottom:5px;font-weight:bold;font-size:14px;color:#555}
        input[type=text],input[type=number],select,textarea{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px}
        textarea{min-height:200px;font-family:Arial,sans-serif}
        .checkbox-row{margin-bottom:15px;display:flex;align-items:center;gap:8px}
        .checkbox-row input{width:auto}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .meta{background:#f8f9fa;padding:10px;border-radius:4px;margin-bottom:20px;font-size:13px;color:#666}
    </style>
</head>
<body>
<div class="container">
    <h1>Edit Article #<?php echo (int)$article['id']; ?></h1>
    <a href="index.php" class="btn btn-secondary" style="margin-bottom:15px">← Back to List</a>
    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary" style="margin-bottom:15px">View</a>

    <div class="meta">
        Views: <?php echo number_format((int)$article['views']); ?> &middot;
        Created: <?php echo htmlspecialchars($article['created_at']); ?> &middot;
        Reporter ID: <?php echo (int)$article['reporter_id']; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <label>Title</label>
        <input type="text" name="title" value="<?php echo htmlspecialchars($article['title']); ?>" required>

        <label>Content</label>
        <textarea name="content" required><?php echo htmlspecialchars($article['content'] ?? ''); ?></textarea>

        <label>Reporter ID</label>
        <input type="number" name="reporter_id" value="<?php echo (int)$article['reporter_id']; ?>" min="0">

        <label>Status</label>
        <select name="status">
            <?php foreach (['draft', 'pending', 'approved', 'rejected', 'published'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $article['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>

        <div class="checkbox-row">
            <input type="checkbox" name="is_breaking" id="is_breaking" value="1" <?php echo !empty($article['is_breaking']) ? 'checked' : ''; ?>>
            <label for="is_breaking" style="margin:0;font-weight:normal">Breaking News</label>
        </div>

        <button type="submit" class="btn btn-primary">Update Article</button>
    </form>
</div>
</body>
</html>
