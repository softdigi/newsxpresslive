<?php
// ============================================================
// admin_panel/analytics/heatmap.php
// Click heatmap viewer — shows where users clicked on a
// specific article page. Renders dots using heatmap.js.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

// ── Validate inputs ──────────────────────────────────────────
function validDate(string $val, string $default): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $d = DateTime::createFromFormat('Y-m-d', $val);
        if ($d && $d->format('Y-m-d') === $val) return $val;
    }
    return $default;
}

$newsId  = max(0, (int)($_GET['news_id'] ?? 0));
$from    = validDate($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
$to      = validDate($_GET['to']   ?? '', date('Y-m-d'));
if ($from > $to) $from = $to;

// ── News articles for picker ─────────────────────────────────
$articles = $pdo->query(
    "SELECT id, title FROM news WHERE status = 'approved' ORDER BY created_at DESC LIMIT 200"
)->fetchAll();

// ── Aggregate click data ─────────────────────────────────────
$points = [];
$total  = 0;
if ($newsId > 0) {
    $stmt = $pdo->prepare(
        "SELECT x_pct, y_pct, COUNT(*) AS cnt
         FROM click_heatmap
         WHERE news_id = ?
           AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY x_pct, y_pct"
    );
    $stmt->execute([$newsId, $from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $points[] = [
            'x'     => (int)$row['x_pct'],
            'y'     => (int)$row['y_pct'],
            'value' => (int)$row['cnt'],
        ];
        $total += (int)$row['cnt'];
    }
}
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>🖱️ Click Heatmap</h1>
    <small>Shows where users click on article pages (x/y as % of page dimensions).</small>
</section>
<section class="content">

<!-- Filter form -->
<div class="card" style="margin-bottom:16px">
<form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
    <div>
        <label style="font-size:12px;color:#666;display:block">Article</label>
        <select name="news_id" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;min-width:260px">
            <option value="">— pick an article —</option>
            <?php foreach ($articles as $a): ?>
                <option value="<?= $a['id'] ?>"
                    <?= $a['id'] == $newsId ? 'selected' : '' ?>>
                    #<?= $a['id'] ?> – <?= htmlspecialchars(mb_strimwidth($a['title'], 0, 80, '…'), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
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
    <button type="submit" style="padding:7px 18px;background:#3498db;color:#fff;border:none;border-radius:4px;cursor:pointer">
        Load Heatmap
    </button>
</form>
</div>

<?php if ($newsId > 0): ?>
<div class="card">
    <p style="margin:0 0 8px">
        <strong>Total clicks:</strong> <?= number_format($total) ?>
        &nbsp;|&nbsp; <strong>Unique positions:</strong> <?= count($points) ?>
        &nbsp;|&nbsp; Period: <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?>
    </p>

    <?php if (empty($points)): ?>
        <p style="color:#888">No click data recorded for this article in the selected period.</p>
    <?php else: ?>
    <!-- Heatmap canvas: 800 × 500 normalised grid -->
    <div id="heatmap-container"
         style="position:relative;width:800px;height:500px;background:#f8f9fa;
                border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:0 auto">
        <!-- Simulated article skeleton (purely for context) -->
        <div style="padding:20px 40px;opacity:.15;pointer-events:none">
            <div style="height:28px;background:#555;border-radius:4px;margin-bottom:14px;width:70%"></div>
            <div style="height:14px;background:#888;border-radius:3px;margin-bottom:8px"></div>
            <div style="height:14px;background:#888;border-radius:3px;margin-bottom:8px;width:90%"></div>
            <div style="height:14px;background:#888;border-radius:3px;margin-bottom:8px;width:80%"></div>
            <div style="height:14px;background:#888;border-radius:3px;margin-bottom:8px"></div>
            <div style="height:14px;background:#888;border-radius:3px;margin-bottom:8px;width:95%"></div>
        </div>
        <div id="heatmap-target" style="position:absolute;top:0;left:0;width:100%;height:100%"></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</section>
</div>

<!-- heatmap.js library (MIT, ~30 kB) -->
<script src="https://cdn.jsdelivr.net/npm/heatmap.js@2.0.5/build/heatmap.min.js"></script>
<script>
(function () {
    var points = <?= json_encode($points) ?>;
    if (!points.length) return;

    var container = document.getElementById('heatmap-target');
    var W = container.offsetWidth  || 800;
    var H = container.offsetHeight || 500;

    var data = points.map(function(p) {
        return {
            x: Math.round(p.x / 100 * W),
            y: Math.round(p.y / 100 * H),
            value: p.value
        };
    });

    var maxVal = data.reduce(function(m, d) { return Math.max(m, d.value); }, 0);

    var h = h337.create({
        container: container,
        radius:    40,
        maxOpacity: .8,
        minOpacity: 0,
        blur: .8,
    });

    h.setData({ max: maxVal, data: data });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
