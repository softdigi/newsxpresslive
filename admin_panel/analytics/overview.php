<?php
// analytics/overview.php — FIXED
// $from and $to from GET — never validated as real dates
// Attacker could send: from=2024-01-01' OR '1'='1
// (Though PDO parameterized, the DATE() comparison is safe,
// but invalid dates cause MySQL warnings and wrong results)
// FIXED: validate date format strictly
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

// FIXED: validate dates — must be YYYY-MM-DD format and real dates
function validateDate(string $date, string $default): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) {
            return $date;
        }
    }
    return $default;
}

$from = validateDate($_GET['from'] ?? '', date('Y-m-01'));
$to   = validateDate($_GET['to']   ?? '', date('Y-m-d'));

// Ensure $from <= $to
if ($from > $to) {
    $from = $to;
}

$stmt = $pdo->prepare("
    SELECT
        COUNT(*)                                            AS total_news,
        COALESCE(SUM(views), 0)                            AS total_views,
        SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_news
    FROM news
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$from, $to]);
$data = $stmt->fetch();
?>

<div class="content-wrapper">
<section class="content-header"><h1>Analytics Overview</h1></section>
<section class="content">

<form method="GET" style="margin-bottom:15px;display:flex;gap:8px;align-items:flex-end">
    <div>
        <label style="font-size:12px;color:#666;display:block">From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"
               style="padding:6px;border:1px solid #ccc;border-radius:4px">
    </div>
    <div>
        <label style="font-size:12px;color:#666;display:block">To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"
               style="padding:6px;border:1px solid #ccc;border-radius:4px">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="overview.php" class="btn btn-secondary btn-sm">Reset</a>
</form>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:20px">
    <div style="background:#007bff;color:#fff;padding:20px;border-radius:8px;text-align:center">
        <div style="font-size:28px;font-weight:bold"><?= number_format((int)$data['total_news']) ?></div>
        <div>Total Articles</div>
    </div>
    <div style="background:#28a745;color:#fff;padding:20px;border-radius:8px;text-align:center">
        <div style="font-size:28px;font-weight:bold"><?= number_format((int)$data['approved_news']) ?></div>
        <div>Approved</div>
    </div>
    <div style="background:#6f42c1;color:#fff;padding:20px;border-radius:8px;text-align:center">
        <div style="font-size:28px;font-weight:bold"><?= number_format((int)$data['total_views']) ?></div>
        <div>Total Views</div>
    </div>
</div>

<p style="color:#888;font-size:13px">Period: <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></p>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
