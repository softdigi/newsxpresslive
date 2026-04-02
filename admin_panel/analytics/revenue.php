<?php
// ============================================================
// admin_panel/analytics/revenue.php
// Revenue analytics dashboard — ads + subscriptions.
// Covers: MRR, ARR, active subscribers, ad earnings, daily
// revenue trend and revenue-source breakdown chart.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin']);

// ── Date range ────────────────────────────────────────────────
function revenueValidDate(string $val, string $default): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $d = DateTime::createFromFormat('Y-m-d', $val);
        if ($d && $d->format('Y-m-d') === $val) return $val;
    }
    return $default;
}

$from = revenueValidDate($_GET['from'] ?? '', date('Y-m-01'));
$to   = revenueValidDate($_GET['to']   ?? '', date('Y-m-d'));
if ($from > $to) $from = $to;

// ── Handle POST: save daily ad revenue entry ──────────────────
$postMsg = '';
$postErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $revDate    = revenueValidDate($_POST['revenue_date'] ?? '', '');
    $source     = in_array($_POST['source'] ?? '', ['adsense','direct','affiliate','other'])
                      ? $_POST['source'] : 'adsense';
    $impressions = max(0, (int)($_POST['impressions'] ?? 0));
    $clicks      = max(0, (int)($_POST['clicks']      ?? 0));
    $earnings    = round(max(0, (float)($_POST['earnings'] ?? 0)), 4);
    $currency    = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['currency'] ?? 'USD'));
    $currency    = strlen($currency) === 3 ? $currency : 'USD';
    $notes       = mb_substr(trim($_POST['notes'] ?? ''), 0, 255, 'UTF-8');

    if ($revDate === '') {
        $postErr = 'Invalid date.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO ad_revenue (revenue_date, source, impressions, clicks, earnings, currency, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                impressions = VALUES(impressions),
                clicks      = VALUES(clicks),
                earnings    = VALUES(earnings),
                currency    = VALUES(currency),
                notes       = VALUES(notes)
        ");
        $stmt->execute([
            $revDate, $source, $impressions, $clicks,
            $earnings, $currency, $notes, $_SESSION['admin']['id'],
        ]);
        $postMsg = 'Ad revenue record saved.';
    }
}

// ── Subscription KPIs ─────────────────────────────────────────
// Active subscribers right now
$activeSubs = (int)$pdo->query(
    "SELECT COUNT(*) FROM subscriptions
     WHERE status = 'active' AND expires_at > NOW()"
)->fetchColumn();

// MRR: sum of active subscriptions normalised to monthly
$mrrRow = $pdo->query("
    SELECT
        SUM(CASE WHEN plan='monthly' THEN amount              ELSE 0 END) +
        SUM(CASE WHEN plan='yearly'  THEN amount / 12.0       ELSE 0 END) AS mrr
    FROM subscriptions
    WHERE status = 'active' AND expires_at > NOW()
")->fetch();
$mrr = round((float)($mrrRow['mrr'] ?? 0), 2);
$arr = round($mrr * 12, 2);

// New subscribers in range
$newSubs = (int)$pdo->prepare("
    SELECT COUNT(*) FROM subscriptions
    WHERE DATE(created_at) BETWEEN ? AND ?
")->execute([$from, $to]) ? $pdo->query("
    SELECT COUNT(*) FROM subscriptions
    WHERE DATE(created_at) BETWEEN '$from' AND '$to'
")->fetchColumn() : 0;

// More correct:
$nsStmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE DATE(created_at) BETWEEN ? AND ?");
$nsStmt->execute([$from, $to]);
$newSubs = (int)$nsStmt->fetchColumn();

// Monthly subscription revenue in range
$subRevStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM subscriptions
     WHERE DATE(created_at) BETWEEN ? AND ?"
);
$subRevStmt->execute([$from, $to]);
$subRevInRange = round((float)$subRevStmt->fetchColumn(), 2);

// Subscription plan distribution
$planDist = $pdo->query(
    "SELECT plan, COUNT(*) AS cnt, SUM(amount) AS total
     FROM subscriptions WHERE status='active' AND expires_at > NOW()
     GROUP BY plan"
)->fetchAll();

// ── Ad revenue in range ───────────────────────────────────────
$adRevStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(earnings), 0)    AS total_earnings,
        COALESCE(SUM(impressions), 0) AS total_impressions,
        COALESCE(SUM(clicks), 0)      AS total_clicks
    FROM ad_revenue
    WHERE revenue_date BETWEEN ? AND ?
");
$adRevStmt->execute([$from, $to]);
$adKpi = $adRevStmt->fetch();
$adEarnings    = round((float)$adKpi['total_earnings'], 2);
$adImpressions = (int)$adKpi['total_impressions'];
$adClicks      = (int)$adKpi['total_clicks'];
$adCtr         = $adImpressions > 0 ? round($adClicks / $adImpressions * 100, 2) : 0;
$adRpm         = $adImpressions > 0
                    ? round($adEarnings / $adImpressions * 1000, 2) : 0;

$totalRevInRange = round($subRevInRange + $adEarnings, 2);

// ── Daily combined revenue chart ──────────────────────────────
// Ad revenue per day
$adDailyStmt = $pdo->prepare("
    SELECT revenue_date AS day, SUM(earnings) AS earnings
    FROM ad_revenue
    WHERE revenue_date BETWEEN ? AND ?
    GROUP BY revenue_date
    ORDER BY revenue_date ASC
");
$adDailyStmt->execute([$from, $to]);
$adDailyRows = $adDailyStmt->fetchAll();
$adDailyMap  = [];
foreach ($adDailyRows as $r) $adDailyMap[$r['day']] = (float)$r['earnings'];

// Subscription revenue per day
$subDailyStmt = $pdo->prepare("
    SELECT DATE(created_at) AS day, SUM(amount) AS earnings
    FROM subscriptions
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
");
$subDailyStmt->execute([$from, $to]);
$subDailyRows = $subDailyStmt->fetchAll();
$subDailyMap  = [];
foreach ($subDailyRows as $r) $subDailyMap[$r['day']] = (float)$r['earnings'];

// Build unified day axis
$allDays = array_unique(array_merge(array_keys($adDailyMap), array_keys($subDailyMap)));
sort($allDays);
$chartLabels  = $allDays;
$chartAdRev   = array_map(fn($d) => round($adDailyMap[$d]  ?? 0, 2), $allDays);
$chartSubRev  = array_map(fn($d) => round($subDailyMap[$d] ?? 0, 2), $allDays);

// Revenue source breakdown (pie)
$srcStmt = $pdo->prepare("
    SELECT source, SUM(earnings) AS total
    FROM ad_revenue
    WHERE revenue_date BETWEEN ? AND ?
    GROUP BY source
");
$srcStmt->execute([$from, $to]);
$srcRows  = $srcStmt->fetchAll();
$srcLabels = [];
$srcValues = [];
if ($subRevInRange > 0) {
    $srcLabels[] = 'Subscriptions';
    $srcValues[] = $subRevInRange;
}
foreach ($srcRows as $r) {
    $srcLabels[] = ucfirst($r['source']);
    $srcValues[] = round((float)$r['total'], 2);
}

// ── Subscription new vs churned trend per month ───────────────
$subTrendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS mon,
           COUNT(*) AS new_subs,
           SUM(amount) AS revenue
    FROM subscriptions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ORDER BY mon ASC
");
$subTrendStmt->execute();
$subTrendRows  = $subTrendStmt->fetchAll();
$subTrendLabels= array_column($subTrendRows, 'mon');
$subTrendCounts= array_map('intval', array_column($subTrendRows, 'new_subs'));
$subTrendRevs  = array_map(fn($r) => round((float)$r, 2), array_column($subTrendRows, 'revenue'));
?>

<div class="content-wrapper">
<section class="content-header"><h1>💰 Revenue Analytics</h1></section>
<section class="content">

<!-- Date range filter -->
<div class="card" style="margin-bottom:16px">
<form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
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
    <button type="submit"
            style="padding:7px 18px;background:#3498db;color:#fff;border:none;border-radius:4px;cursor:pointer">
        Apply
    </button>
</form>
</div>

<!-- KPI cards row 1: Subscription -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:16px">
    <?php
    $kpis = [
        ['💳 MRR',             '$' . number_format($mrr, 2),        '#27ae60', 'Monthly Recurring Revenue'],
        ['📈 ARR',             '$' . number_format($arr, 2),         '#2ecc71', 'Annual Run Rate'],
        ['👥 Active Subs',     number_format($activeSubs),           '#3498db', 'Active subscriptions now'],
        ['🆕 New Subs',        number_format($newSubs),              '#9b59b6', 'New in selected range'],
        ['💵 Sub Revenue',     '$' . number_format($subRevInRange, 2),'#1abc9c', 'Subscription revenue in range'],
    ];
    foreach ($kpis as [$label, $val, $color, $hint]):
    ?>
    <div class="stat-card" style="border-top:4px solid <?= $color ?>;padding:14px;border-radius:6px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <div style="font-size:12px;color:#888;margin-bottom:4px" title="<?= htmlspecialchars($hint) ?>"><?= $label ?></div>
        <div style="font-size:24px;font-weight:700;color:#333"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- KPI cards row 2: Ads -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px">
    <?php
    $adKpis = [
        ['📢 Ad Earnings',   '$' . number_format($adEarnings, 2),  '#e67e22', 'Total ad earnings in range'],
        ['👁 Impressions',   number_format($adImpressions),        '#d35400', 'Ad impressions in range'],
        ['🖱 Ad Clicks',     number_format($adClicks),              '#c0392b', 'Ad clicks in range'],
        ['📊 Ad CTR',        $adCtr . '%',                         '#8e44ad', 'Click-through rate'],
        ['💲 RPM',           '$' . number_format($adRpm, 2),       '#2980b9', 'Revenue per 1000 impressions'],
        ['🏦 Total Revenue', '$' . number_format($totalRevInRange, 2),'#16a085','Ads + Subscriptions'],
    ];
    foreach ($adKpis as [$label, $val, $color, $hint]):
    ?>
    <div class="stat-card" style="border-top:4px solid <?= $color ?>;padding:14px;border-radius:6px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <div style="font-size:12px;color:#888;margin-bottom:4px" title="<?= htmlspecialchars($hint) ?>"><?= $label ?></div>
        <div style="font-size:24px;font-weight:700;color:#333"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts row -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px">
    <!-- Daily revenue trend -->
    <div class="card">
        <h3 style="margin:0 0 12px">📈 Daily Revenue</h3>
        <?php if (empty($chartLabels)): ?>
            <p style="color:#888">No revenue data in this range.</p>
        <?php else: ?>
        <canvas id="dailyRevChart" height="100"></canvas>
        <?php endif; ?>
    </div>
    <!-- Revenue source pie -->
    <div class="card">
        <h3 style="margin:0 0 12px">🥧 Revenue Mix</h3>
        <?php if (empty($srcValues)): ?>
            <p style="color:#888">No revenue data in this range.</p>
        <?php else: ?>
        <canvas id="srcPieChart" height="160"></canvas>
        <?php endif; ?>
    </div>
</div>

<!-- Subscription monthly trend -->
<?php if (!empty($subTrendLabels)): ?>
<div class="card" style="margin-bottom:20px">
    <h3 style="margin:0 0 12px">📊 Monthly Subscription Trend (12 months)</h3>
    <canvas id="subTrendChart" height="90"></canvas>
</div>
<?php endif; ?>

<!-- Subscription plan breakdown -->
<?php if (!empty($planDist)): ?>
<div class="card" style="margin-bottom:20px">
    <h3 style="margin:0 0 12px">💳 Active Plan Breakdown</h3>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead><tr style="background:#f4f6f8">
        <th style="padding:8px 12px;text-align:left">Plan</th>
        <th style="padding:8px 12px;text-align:right">Subscribers</th>
        <th style="padding:8px 12px;text-align:right">Revenue</th>
    </tr></thead>
    <tbody>
    <?php foreach ($planDist as $p): ?>
    <tr style="border-bottom:1px solid #eee">
        <td style="padding:8px 12px"><?= htmlspecialchars(ucfirst($p['plan']), ENT_QUOTES) ?></td>
        <td style="padding:8px 12px;text-align:right"><?= number_format($p['cnt']) ?></td>
        <td style="padding:8px 12px;text-align:right">$<?= number_format((float)$p['total'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Add ad revenue entry form -->
<div class="card" style="max-width:700px">
    <h3 style="margin:0 0 12px">📥 Record Ad Revenue</h3>
    <p style="color:#888;font-size:13px;margin:0 0 12px">
        Enter daily figures from your ad network dashboard (AdSense, etc.).
        Existing entries for the same date + source are overwritten.
    </p>

    <?php if ($postMsg): ?>
        <div style="background:#eafaf1;border-left:4px solid #27ae60;padding:8px 12px;border-radius:4px;margin-bottom:12px;color:#1e8449">
            ✅ <?= htmlspecialchars($postMsg) ?>
        </div>
    <?php elseif ($postErr): ?>
        <div style="background:#fdecea;border-left:4px solid #e74c3c;padding:8px 12px;border-radius:4px;margin-bottom:12px;color:#c0392b">
            ⚠️ <?= htmlspecialchars($postErr) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Date *</label>
                <input type="date" name="revenue_date" required
                       value="<?= date('Y-m-d') ?>"
                       style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
            </div>
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Source</label>
                <select name="source"
                        style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
                    <option value="adsense">AdSense</option>
                    <option value="direct">Direct Ads</option>
                    <option value="affiliate">Affiliate</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Currency</label>
                <input type="text" name="currency" value="USD" maxlength="3"
                       style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
            </div>
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Impressions</label>
                <input type="number" name="impressions" min="0" value="0"
                       style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
            </div>
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Clicks</label>
                <input type="number" name="clicks" min="0" value="0"
                       style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
            </div>
            <div>
                <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Earnings ($) *</label>
                <input type="number" name="earnings" step="0.0001" min="0" value="0.00" required
                       style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
            </div>
        </div>
        <div style="margin-bottom:12px">
            <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Notes</label>
            <input type="text" name="notes" maxlength="255" placeholder="Optional note"
                   style="width:100%;padding:7px;border:1px solid #ccc;border-radius:4px">
        </div>
        <button type="submit"
                style="padding:9px 22px;background:#27ae60;color:#fff;border:none;border-radius:4px;cursor:pointer">
            💾 Save Entry
        </button>
    </form>
</div>

</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if (!empty($chartLabels)): ?>
new Chart(document.getElementById('dailyRevChart'), {
    type: 'bar',
    data: {
        labels:   <?= json_encode($chartLabels) ?>,
        datasets: [
            {
                label:           'Subscriptions',
                data:            <?= json_encode($chartSubRev) ?>,
                backgroundColor: 'rgba(52,152,219,0.75)',
                stack:           'rev',
            },
            {
                label:           'Ad Revenue',
                data:            <?= json_encode($chartAdRev) ?>,
                backgroundColor: 'rgba(230,126,34,0.75)',
                stack:           'rev',
            },
        ]
    },
    options: {
        plugins: { legend: { position: 'top' } },
        scales: {
            x: { stacked: true },
            y: { stacked: true, title: { display: true, text: 'USD ($)' } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($srcValues)): ?>
new Chart(document.getElementById('srcPieChart'), {
    type: 'doughnut',
    data: {
        labels:   <?= json_encode($srcLabels) ?>,
        datasets: [{
            data:            <?= json_encode($srcValues) ?>,
            backgroundColor: ['#3498db','#e67e22','#27ae60','#9b59b6','#1abc9c','#e74c3c'],
        }]
    },
    options: {
        plugins: { legend: { position: 'bottom' } },
        cutout: '55%',
    }
});
<?php endif; ?>

<?php if (!empty($subTrendLabels)): ?>
new Chart(document.getElementById('subTrendChart'), {
    type: 'bar',
    data: {
        labels:   <?= json_encode($subTrendLabels) ?>,
        datasets: [
            {
                label:           'New Subscribers',
                data:            <?= json_encode($subTrendCounts) ?>,
                type:            'bar',
                backgroundColor: 'rgba(52,152,219,0.6)',
                yAxisID:         'y',
            },
            {
                label:           'Revenue ($)',
                data:            <?= json_encode($subTrendRevs) ?>,
                type:            'line',
                borderColor:     '#27ae60',
                backgroundColor: 'rgba(39,174,96,0.15)',
                tension:         0.3,
                fill:            true,
                yAxisID:         'y2',
            },
        ]
    },
    options: {
        plugins: { legend: { position: 'top' } },
        scales: {
            y:  { position: 'left',  title: { display: true, text: 'Subscribers' } },
            y2: { position: 'right', title: { display: true, text: 'Revenue ($)' }, grid: { drawOnChartArea: false } },
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

