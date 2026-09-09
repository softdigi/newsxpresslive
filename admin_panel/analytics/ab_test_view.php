<?php
// ============================================================
// admin_panel/analytics/ab_test_view.php
// Detailed results for a single A/B headline test.
// Shows CTR, impressions, clicks, daily trend and a
// chi-squared significance test.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin', 'editor']);

$testId = max(0, (int)($_GET['id'] ?? 0));
if ($testId <= 0) {
    echo '<p>Invalid test ID.</p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Load test meta ────────────────────────────────────────────
$test = $pdo->prepare(
    "SELECT t.*, n.title AS current_title
     FROM ab_tests t
     LEFT JOIN news n ON n.id = t.news_id
     WHERE t.id = ?
     LIMIT 1"
);
$test->execute([$testId]);
$test = $test->fetch();

if (!$test) {
    echo '<p>Test not found.</p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Handle conclude / pause / resume ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];
    if ($action === 'pause' && $test['status'] === 'running') {
        $pdo->prepare("UPDATE ab_tests SET status='paused' WHERE id=?")->execute([$testId]);
    } elseif ($action === 'resume' && $test['status'] === 'paused') {
        $pdo->prepare("UPDATE ab_tests SET status='running' WHERE id=?")->execute([$testId]);
    } elseif ($action === 'conclude' && in_array($_POST['winner'] ?? '', ['a', 'b'])) {
        $winner = $_POST['winner'];
        $pdo->prepare(
            "UPDATE ab_tests SET status='concluded', winner=?, concluded_at=NOW() WHERE id=?"
        )->execute([$winner, $testId]);
    }
    header("Location: ab_test_view.php?id=$testId");
    exit;
}

// ── Aggregate stats ───────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        SUM(CASE WHEN variant='a' AND event_type='impression' THEN 1 ELSE 0 END) AS imp_a,
        SUM(CASE WHEN variant='b' AND event_type='impression' THEN 1 ELSE 0 END) AS imp_b,
        SUM(CASE WHEN variant='a' AND event_type='click'      THEN 1 ELSE 0 END) AS clk_a,
        SUM(CASE WHEN variant='b' AND event_type='click'      THEN 1 ELSE 0 END) AS clk_b
    FROM ab_test_events
    WHERE test_id = ?
");
$stats->execute([$testId]);
$s = $stats->fetch();

$impA  = (int)($s['imp_a'] ?? 0);
$impB  = (int)($s['imp_b'] ?? 0);
$clkA  = (int)($s['clk_a'] ?? 0);
$clkB  = (int)($s['clk_b'] ?? 0);
$ctrA  = $impA > 0 ? round($clkA / $impA * 100, 2) : 0;
$ctrB  = $impB > 0 ? round($clkB / $impB * 100, 2) : 0;
$liftPct = $ctrA > 0 ? round(($ctrB - $ctrA) / $ctrA * 100, 1) : null;

// Chi-squared significance (2×2 contingency table)
// H0: CTR(A) = CTR(B). Significant if p < 0.05 (χ² > 3.84).
$chiSquared = null;
$significant = false;
$pValue = null;
if ($impA > 0 && $impB > 0) {
    $nonClkA = $impA - $clkA;
    $nonClkB = $impB - $clkB;
    $total   = $impA + $impB;
    $totClk  = $clkA + $clkB;
    $totNon  = $nonClkA + $nonClkB;

    if ($total > 0 && $totClk > 0 && $totNon > 0) {
        // Expected values
        $eA1 = $impA * $totClk / $total;
        $eA0 = $impA * $totNon / $total;
        $eB1 = $impB * $totClk / $total;
        $eB0 = $impB * $totNon / $total;

        // Avoid division by zero
        $chiSquared = 0;
        foreach ([[$clkA, $eA1], [$nonClkA, $eA0], [$clkB, $eB1], [$nonClkB, $eB0]] as [$obs, $exp]) {
            if ($exp > 0) $chiSquared += pow($obs - $exp, 2) / $exp;
        }
        $chiSquared = round($chiSquared, 4);
        $significant = $chiSquared > 3.841;
        // Approximate p-value buckets for display
        if ($chiSquared > 10.828) $pValue = '< 0.001';
        elseif ($chiSquared > 6.635) $pValue = '< 0.01';
        elseif ($chiSquared > 3.841) $pValue = '< 0.05';
        else $pValue = '≥ 0.05 (not significant)';
    }
}

// ── Daily trend (last 30 days) ────────────────────────────────
$daily = $pdo->prepare("
    SELECT DATE(created_at) AS day,
           SUM(CASE WHEN variant='a' AND event_type='impression' THEN 1 ELSE 0 END) AS imp_a,
           SUM(CASE WHEN variant='b' AND event_type='impression' THEN 1 ELSE 0 END) AS imp_b,
           SUM(CASE WHEN variant='a' AND event_type='click'      THEN 1 ELSE 0 END) AS clk_a,
           SUM(CASE WHEN variant='b' AND event_type='click'      THEN 1 ELSE 0 END) AS clk_b
    FROM ab_test_events
    WHERE test_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$daily->execute([$testId]);
$dailyRows = $daily->fetchAll();

$daysLabels = [];
$dayCtrA    = [];
$dayCtrB    = [];
foreach ($dailyRows as $r) {
    $daysLabels[] = $r['day'];
    $dayCtrA[]    = (int)$r['imp_a'] > 0 ? round($r['clk_a'] / $r['imp_a'] * 100, 2) : 0;
    $dayCtrB[]    = (int)$r['imp_b'] > 0 ? round($r['clk_b'] / $r['imp_b'] * 100, 2) : 0;
}

$created = isset($_GET['created']);
?>

<div class="content-wrapper">
<section class="content-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
        <h1>🧪 A/B Test #<?= $testId ?></h1>
        <small>Article #<?= htmlspecialchars($test['news_id'], ENT_QUOTES) ?>
            — <?= htmlspecialchars(mb_strimwidth($test['current_title'] ?? '', 0, 80, '…'), ENT_QUOTES) ?>
        </small>
    </div>
    <a href="ab_tests.php" style="color:#3498db;font-size:14px;text-decoration:none">← All Tests</a>
</section>
<section class="content">

<?php if ($created): ?>
    <div class="alert" style="background:#eafaf1;border-left:4px solid #27ae60;padding:10px 14px;margin-bottom:16px;border-radius:4px;color:#1e8449">
        ✅ Test started! Integrate the API endpoints to start collecting data.
    </div>
<?php endif; ?>

<!-- Status badge + controls -->
<div class="card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px">
    <?php
        $sc = ['running' => '#27ae60','paused' => '#e67e22','concluded' => '#7f8c8d'];
        $col = $sc[$test['status']] ?? '#999';
    ?>
    <span style="background:<?= $col ?>;color:#fff;padding:4px 12px;border-radius:12px;font-size:13px">
        <?= ucfirst($test['status']) ?>
    </span>
    <?php if ($test['winner']): ?>
        <span style="background:#f39c12;color:#fff;padding:4px 12px;border-radius:12px;font-size:13px">
            🏆 Winner: Variant <?= strtoupper($test['winner']) ?>
        </span>
    <?php endif; ?>
    <span style="color:#888;font-size:13px">Created: <?= htmlspecialchars($test['created_at']) ?></span>
    <?php if ($test['concluded_at']): ?>
        <span style="color:#888;font-size:13px">Concluded: <?= htmlspecialchars($test['concluded_at']) ?></span>
    <?php endif; ?>
</div>

<!-- Variant cards -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
    <?php foreach (['a', 'b'] as $v):
        $imp = $v === 'a' ? $impA : $impB;
        $clk = $v === 'a' ? $clkA : $clkB;
        $ctr = $v === 'a' ? $ctrA : $ctrB;
        $title = $test['title_' . $v];
        $isWinner = $test['winner'] === $v;
        $isBetter = $v === 'a' ? $ctrA >= $ctrB : $ctrB > $ctrA;
    ?>
    <div class="card" style="border-top:4px solid <?= $isWinner ? '#27ae60' : ($isBetter ? '#3498db' : '#ccc') ?>">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
            <span style="font-size:20px;font-weight:bold;color:#555">Variant <?= strtoupper($v) ?></span>
            <?php if ($isWinner): ?>
                <span style="background:#27ae60;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px">🏆 WINNER</span>
            <?php endif; ?>
        </div>
        <p style="font-size:15px;font-weight:600;color:#333;margin:0 0 12px">
            "<?= htmlspecialchars($title, ENT_QUOTES) ?>"
        </p>
        <div style="display:flex;gap:24px">
            <div>
                <div style="font-size:28px;font-weight:bold;color:<?= $isBetter ? '#27ae60' : '#555' ?>"><?= $ctr ?>%</div>
                <div style="font-size:12px;color:#888">CTR</div>
            </div>
            <div>
                <div style="font-size:22px;font-weight:bold"><?= number_format($imp) ?></div>
                <div style="font-size:12px;color:#888">Impressions</div>
            </div>
            <div>
                <div style="font-size:22px;font-weight:bold"><?= number_format($clk) ?></div>
                <div style="font-size:12px;color:#888">Clicks</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Statistical significance -->
<div class="card" style="margin-bottom:16px">
    <h3 style="margin:0 0 12px">📊 Statistical Significance</h3>
    <?php if ($chiSquared === null): ?>
        <p style="color:#888">Not enough data yet to calculate significance.</p>
    <?php else: ?>
    <div style="display:flex;gap:32px;flex-wrap:wrap">
        <div>
            <div style="font-size:24px;font-weight:bold;color:<?= $significant ? '#27ae60' : '#e74c3c' ?>">
                <?= $significant ? '✅ Significant' : '❌ Not Significant' ?>
            </div>
            <div style="font-size:12px;color:#888">Chi-squared test (α = 0.05)</div>
        </div>
        <div>
            <div style="font-size:24px;font-weight:bold"><?= $chiSquared ?></div>
            <div style="font-size:12px;color:#888">χ² value (threshold: 3.841)</div>
        </div>
        <div>
            <div style="font-size:24px;font-weight:bold"><?= htmlspecialchars($pValue ?? '—') ?></div>
            <div style="font-size:12px;color:#888">p-value</div>
        </div>
        <?php if ($liftPct !== null): ?>
        <div>
            <div style="font-size:24px;font-weight:bold;color:<?= $liftPct >= 0 ? '#27ae60' : '#e74c3c' ?>">
                <?= ($liftPct >= 0 ? '+' : '') . $liftPct ?>%
            </div>
            <div style="font-size:12px;color:#888">Lift (B vs A)</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Daily CTR chart -->
<?php if (!empty($daysLabels)): ?>
<div class="card" style="margin-bottom:16px">
    <h3 style="margin:0 0 12px">📈 Daily CTR Trend</h3>
    <canvas id="trendChart" height="90"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels:   <?= json_encode($daysLabels) ?>,
        datasets: [
            {
                label:           'Variant A CTR (%)',
                data:            <?= json_encode($dayCtrA) ?>,
                borderColor:     '#3498db',
                backgroundColor: 'rgba(52,152,219,0.1)',
                tension:         0.3,
                fill:            true,
            },
            {
                label:           'Variant B CTR (%)',
                data:            <?= json_encode($dayCtrB) ?>,
                borderColor:     '#e67e22',
                backgroundColor: 'rgba(230,126,34,0.1)',
                tension:         0.3,
                fill:            true,
            },
        ]
    },
    options: {
        plugins: { legend: { position: 'top' } },
        scales:  { y: { min: 0, title: { display: true, text: 'CTR (%)' } } }
    }
});
</script>
<?php endif; ?>

<!-- Actions -->
<?php if ($test['status'] !== 'concluded'): ?>
<div class="card">
    <h3 style="margin:0 0 12px">⚙️ Test Controls</h3>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
        <?php if ($test['status'] === 'running'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="pause">
            <button type="submit"
                    style="padding:8px 18px;background:#e67e22;color:#fff;border:none;border-radius:4px;cursor:pointer">
                ⏸ Pause Test
            </button>
        </form>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="resume">
            <button type="submit"
                    style="padding:8px 18px;background:#27ae60;color:#fff;border:none;border-radius:4px;cursor:pointer">
                ▶ Resume Test
            </button>
        </form>
        <?php endif; ?>

        <!-- Conclude and pick winner -->
        <form method="POST" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="conclude">
            <select name="winner" required
                    style="padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px">
                <option value="">— pick winner —</option>
                <option value="a">Variant A</option>
                <option value="b">Variant B</option>
            </select>
            <button type="submit"
                    style="padding:8px 18px;background:#8e44ad;color:#fff;border:none;border-radius:4px;cursor:pointer">
                🏁 Conclude Test
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- API integration snippet -->
<div class="card" style="background:#f8f9fa;margin-top:16px">
    <h4 style="margin:0 0 8px">🔌 Integration Snippet</h4>
    <pre style="font-size:12px;background:#2c3e50;color:#ecf0f1;padding:14px;border-radius:4px;overflow:auto"><code>// On headline render (impression):
fetch('/api/v1/ab_event.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ test_id: <?= $testId ?>, variant: assignedVariant, event: 'impression' })
});

// On headline click:
fetch('/api/v1/ab_event.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ test_id: <?= $testId ?>, variant: assignedVariant, event: 'click' })
});</code></pre>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
