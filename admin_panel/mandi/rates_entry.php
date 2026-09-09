<?php
/**
 * admin_panel/mandi/rates_entry.php
 *
 * Admin: Manual mandi rate entry.
 * - Date + Mandi select
 * - Commodity-wise rate table with pre-fill from previous day
 * - Bulk CSV upload
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../web/includes/config.php';

$message = '';
$error   = '';

/* ── handle form POST ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rates'])) {
    $mandiId  = (int)($_POST['mandi_id'] ?? 0);
    $rateDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['rate_date'] ?? '') ? $_POST['rate_date'] : null;

    if ($mandiId <= 0 || !$rateDate) {
        $error = 'Mandi and date are required.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO mandi_rates
                 (mandi_id, commodity_id, rate_date, min_price, max_price, modal_price, arrivals_tonnes, source)
             VALUES (:mid, :cid, :date, :min, :max, :modal, :arr, \'manual\')
             ON DUPLICATE KEY UPDATE
                 min_price = VALUES(min_price),
                 max_price = VALUES(max_price),
                 modal_price = VALUES(modal_price),
                 arrivals_tonnes = VALUES(arrivals_tonnes),
                 source = \'manual\''
        );
        $saved = 0;
        foreach ($_POST['rates'] as $cid => $r) {
            if (empty($r['modal_price'])) continue;
            $stmt->execute([
                ':mid'   => $mandiId,
                ':cid'   => (int)$cid,
                ':date'  => $rateDate,
                ':min'   => (float)($r['min_price']   ?? $r['modal_price']),
                ':max'   => (float)($r['max_price']   ?? $r['modal_price']),
                ':modal' => (float)$r['modal_price'],
                ':arr'   => $r['arrivals'] !== '' ? (float)$r['arrivals'] : null,
            ]);
            $saved++;
        }
        $message = "{$saved} commodity rates saved for " . date('d M Y', strtotime($rateDate)) . '.';
    }
}

/* ── handle CSV upload ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['tmp_name'])) {
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    fgetcsv($handle); // skip header
    $stmt = $pdo->prepare(
        'INSERT INTO mandi_rates
             (mandi_id, commodity_id, rate_date, min_price, max_price, modal_price, arrivals_tonnes, source)
         VALUES (?,?,?,?,?,?,?,\'manual\')
         ON DUPLICATE KEY UPDATE
             min_price = VALUES(min_price), max_price = VALUES(max_price),
             modal_price = VALUES(modal_price), source = \'manual\''
    );
    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 6) continue;
        try {
            $stmt->execute([$row[0],$row[1],$row[2],(float)$row[3],(float)$row[4],(float)$row[5],$row[6]??null]);
            $count++;
        } catch (PDOException $e) { /* skip */ }
    }
    fclose($handle);
    $message = "CSV: {$count} rates imported.";
}

/* ── fetch form data ──────────────────────────────────────── */
$mandis      = $pdo->query('SELECT id, name FROM mandis WHERE is_active=1 ORDER BY name')->fetchAll();
$commodities = $pdo->query('SELECT id, name, name_hi, category, unit FROM commodities ORDER BY category, name')->fetchAll();

$selMandiId  = (int)($_GET['mandi_id'] ?? ($_POST['mandi_id'] ?? (count($mandis) ? $mandis[0]['id'] : 0)));
$selDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');

/* ── pre-fill: today's existing rates OR yesterday's rates ── */
$existing = [];
$todayStmt = $pdo->prepare(
    'SELECT commodity_id, min_price, max_price, modal_price, arrivals_tonnes
     FROM mandi_rates WHERE mandi_id=? AND rate_date=?'
);
$todayStmt->execute([$selMandiId, $selDate]);
foreach ($todayStmt->fetchAll() as $r) {
    $existing[$r['commodity_id']] = $r;
}

if (empty($existing)) {
    // pre-fill from previous day
    $prevDate  = date('Y-m-d', strtotime($selDate . ' -1 day'));
    $prevStmt  = $pdo->prepare(
        'SELECT commodity_id, min_price, max_price, modal_price, arrivals_tonnes
         FROM mandi_rates WHERE mandi_id=? AND rate_date=?'
    );
    $prevStmt->execute([$selMandiId, $prevDate]);
    foreach ($prevStmt->fetchAll() as $r) {
        $existing[$r['commodity_id']] = $r;
    }
}
?>

<div class="page-header">
    <h1>🌾 Mandi Rate Entry</h1>
    <p>Enter daily commodity prices manually or upload CSV.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filter bar -->
<form method="GET" class="filter-form" style="display:flex;gap:12px;margin-bottom:20px;">
    <select name="mandi_id" onchange="this.form.submit()">
        <?php foreach ($mandis as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $m['id'] == $selMandiId ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?= $selDate ?>" onchange="this.form.submit()">
</form>

<!-- Rate entry table -->
<form method="POST" class="admin-form">
    <input type="hidden" name="mandi_id"  value="<?= $selMandiId ?>">
    <input type="hidden" name="rate_date" value="<?= $selDate ?>">

    <table class="admin-table">
        <thead>
            <tr>
                <th>Commodity</th>
                <th>Min Price (₹)</th>
                <th>Max Price (₹)</th>
                <th>Modal Price (₹) *</th>
                <th>Arrivals (Tonnes)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($commodities as $c):
            $prev = $existing[$c['id']] ?? null; ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($c['name_hi']) ?></strong><br>
                    <small><?= htmlspecialchars($c['name']) ?> / <?= htmlspecialchars($c['unit']) ?></small>
                </td>
                <td>
                    <input type="number" name="rates[<?= $c['id'] ?>][min_price]"
                           step="0.01" min="0" style="width:100px"
                           value="<?= $prev ? htmlspecialchars($prev['min_price']) : '' ?>">
                </td>
                <td>
                    <input type="number" name="rates[<?= $c['id'] ?>][max_price]"
                           step="0.01" min="0" style="width:100px"
                           value="<?= $prev ? htmlspecialchars($prev['max_price']) : '' ?>">
                </td>
                <td>
                    <input type="number" name="rates[<?= $c['id'] ?>][modal_price]"
                           step="0.01" min="0" style="width:100px"
                           value="<?= $prev ? htmlspecialchars($prev['modal_price']) : '' ?>">
                </td>
                <td>
                    <input type="number" name="rates[<?= $c['id'] ?>][arrivals]"
                           step="0.01" min="0" style="width:100px"
                           value="<?= $prev ? htmlspecialchars($prev['arrivals_tonnes'] ?? '') : '' ?>">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit" class="btn btn-primary" style="margin-top:16px">💾 Save Rates</button>
</form>

<hr style="margin:32px 0">

<!-- CSV upload -->
<form method="POST" enctype="multipart/form-data" class="admin-form">
    <h3>📤 Bulk CSV Upload</h3>
    <p>CSV format: <code>mandi_id,commodity_id,rate_date,min_price,max_price,modal_price,arrivals_tonnes</code></p>
    <input type="file" name="csv_file" accept=".csv" required>
    <button type="submit" class="btn btn-secondary">Upload CSV</button>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
