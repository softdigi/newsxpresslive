<?php
// analytics/traffic.php — FIXED
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

function validateDate(string $date, string $default): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) return $date;
    }
    return $default;
}

$from = validateDate($_GET['from'] ?? '', date('Y-m-01'));
$to   = validateDate($_GET['to']   ?? '', date('Y-m-d'));
if ($from > $to) $from = $to;

$stmt = $pdo->prepare("
    SELECT DATE(created_at) AS date, COALESCE(SUM(views), 0) AS views
    FROM news
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$from, $to]);
$data = $stmt->fetchAll();

$labels = array_column($data, 'date');
$values = array_map(fn($r) => (int)$r['views'], $data);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Traffic</h1></section>
<section class="content">

<form method="GET" style="margin-bottom:15px;display:flex;gap:8px">
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" style="padding:6px;border:1px solid #ccc;border-radius:4px">
    <input type="date" name="to"   value="<?= htmlspecialchars($to)   ?>" style="padding:6px;border:1px solid #ccc;border-radius:4px">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="traffic.php" class="btn btn-secondary btn-sm">Reset</a>
</form>

<canvas id="trafficChart" height="80"></canvas>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('trafficChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: 'Views',
            data: <?= json_encode($values) ?>,
            borderColor: '#007bff',
            backgroundColor: 'rgba(0,123,255,.1)',
            fill: true,
            tension: 0.3
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
