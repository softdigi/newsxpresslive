<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if ($_SESSION['admin']['role'] !== 'super_admin' && $_SESSION['admin']['role'] !== 'admin') {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? '');

    $news_id = (int)$_POST['news_id'];
    
    // SECURITY FIX: Whitelist boost_level to prevent arbitrary values
    $allowed_levels = ['low', 'medium', 'high', 'mega'];
    $level = in_array($_POST['boost_level'] ?? '', $allowed_levels, true) 
             ? $_POST['boost_level'] : 'low';
    
    // SECURITY FIX: Validate bonus is positive and capped
    $bonus = max(0, min(10000, (float)($_POST['reporter_bonus'] ?? 0)));
    
    // SECURITY FIX: Whitelist status
    $allowed_statuses = ['active', 'scheduled', 'completed'];
    $status = in_array($_POST['status'] ?? '', $allowed_statuses, true) 
              ? $_POST['status'] : 'active';

    $stmtN = $pdo->prepare("SELECT reporter_id, agency_id FROM news WHERE id=?");
    $stmtN->execute([$news_id]);
    $news = $stmtN->fetch(PDO::FETCH_ASSOC);

    if (!$news) exit('Invalid news');

    $stmt = $pdo->prepare("
        INSERT INTO viral_boosts
        (news_id, reporter_id, agency_id, boost_level, reporter_bonus, status, created_at)
        VALUES (?,?,?,?,?,?,NOW())
    ");
    $stmt->execute([
        $news_id,
        $news['reporter_id'],
        $news['agency_id'],
        $level,
        $bonus,
        $status
    ]);

    header("Location: index.php");
    exit;
}

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header"><h1>Create Viral Boost</h1></section>
<section class="content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
News ID: <input type="number" name="news_id" required><br>
Level: <input type="text" name="boost_level" required><br>
Bonus: <input type="number" step="0.01" name="reporter_bonus" required><br>
Status:
<select name="status">
<option value="active">Active</option>
<option value="completed">Completed</option>
</select><br><br>
<button type="submit">Create</button>
</form>
</section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
