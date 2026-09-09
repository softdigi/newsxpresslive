<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if ($_SESSION['admin']['role'] !== 'super_admin' && $_SESSION['admin']['role'] !== 'admin') {
    exit('Access denied');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM viral_boosts WHERE id=?");
$stmt->execute([$id]);
$boost = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$boost) exit('Not found');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? '');

    $level  = $_POST['boost_level'];
    $bonus  = (float)$_POST['reporter_bonus'];
    $status = $_POST['status'];

    $stmtU = $pdo->prepare("
        UPDATE viral_boosts 
        SET boost_level=?, reporter_bonus=?, status=? 
        WHERE id=?
    ");
    $stmtU->execute([$level,$bonus,$status,$id]);

    header("Location: index.php");
    exit;
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Edit Viral Boost</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
Level: <input type="text" name="boost_level" value="<?= htmlspecialchars($boost['boost_level']) ?>" required><br>
Bonus: <input type="number" step="0.01" name="reporter_bonus" value="<?= htmlspecialchars($boost['reporter_bonus']) ?>" required><br>
Status:
<select name="status">
<option value="active" <?= $boost['status']=='active'?'selected':'' ?>>Active</option>
<option value="completed" <?= $boost['status']=='completed'?'selected':'' ?>>Completed</option>
</select><br><br>
<button type="submit">Update</button>
</form>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
