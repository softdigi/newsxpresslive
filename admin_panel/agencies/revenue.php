<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

// ── Date range filter ─────────────────────────────────────────────────────────
$from_date = trim($_GET['from_date'] ?? '');
$to_date   = trim($_GET['to_date']   ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $from_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $to_date = date('Y-m-d');
}

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && intval($_GET['export']) === 1) {
    $exp_stmt = $pdo->prepare(
        "SELECT a.name AS agency_name,
                COALESCE(SUM(r.impressions), 0)   AS total_impressions,
                COALESCE(SUM(r.clicks), 0)         AS total_clicks,
                COALESCE(SUM(r.gross_revenue), 0)  AS gross_revenue,
                COALESCE(SUM(r.agency_share), 0)   AS agency_share,
                COALESCE(SUM(r.platform_share), 0) AS platform_share
         FROM agencies a
         LEFT JOIN agency_revenue r
               ON r.agency_id = a.id
               AND r.revenue_date BETWEEN :fd AND :td
         GROUP BY a.id, a.name
         ORDER BY gross_revenue DESC"
    );
    $exp_stmt->execute([':fd' => $from_date, ':td' => $to_date]);
    $rows = $exp_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="agency_revenue_' . $from_date . '_' . $to_date . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Agency', 'Impressions', 'Clicks', 'Gross Revenue', 'Agency Share', 'Platform Share']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['agency_name'],
            $row['total_impressions'],
            $row['total_clicks'],
            number_format(floatval($row['gross_revenue']), 2),
            number_format(floatval($row['agency_share']),  2),
            number_format(floatval($row['platform_share']), 2),
        ]);
    }
    fclose($out);
    exit;
}

// ── Summary totals ─────────────────────────────────────────────────────────────
$summary_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(gross_revenue), 0)  AS total_gross,
            COALESCE(SUM(platform_share), 0) AS total_platform,
            COALESCE(SUM(agency_share),  0)  AS total_agency
     FROM agency_revenue
     WHERE revenue_date BETWEEN :fd AND :td"
);
$summary_stmt->execute([':fd' => $from_date, ':td' => $to_date]);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

$total_articles_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM agency_articles WHERE created_at BETWEEN :fd AND :td"
);
$total_articles_stmt->execute([':fd' => $from_date . ' 00:00:00', ':td' => $to_date . ' 23:59:59']);
$total_articles = (int) $total_articles_stmt->fetchColumn();

// ── Top agencies ───────────────────────────────────────────────────────────────
$top_ag_stmt = $pdo->prepare(
    "SELECT a.id, a.name,
            COALESCE(SUM(r.impressions), 0)   AS total_impressions,
            COALESCE(SUM(r.clicks), 0)         AS total_clicks,
            COALESCE(SUM(r.gross_revenue), 0)  AS gross_revenue,
            COALESCE(SUM(r.agency_share), 0)   AS agency_share
     FROM agencies a
     LEFT JOIN agency_revenue r
           ON r.agency_id = a.id
           AND r.revenue_date BETWEEN :fd AND :td
     GROUP BY a.id, a.name
     ORDER BY gross_revenue DESC
     LIMIT 20"
);
$top_ag_stmt->execute([':fd' => $from_date, ':td' => $to_date]);
$top_agencies = $top_ag_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Top articles ───────────────────────────────────────────────────────────────
$top_art_stmt = $pdo->prepare(
    "SELECT n.id, n.title, a.name AS agency_name,
            n.total_impressions,
            COALESCE(SUM(r.agency_share), 0) AS article_revenue
     FROM news n
     JOIN agencies a ON a.id = n.agency_id
     LEFT JOIN agency_revenue r
           ON r.agency_id = n.agency_id
           AND r.revenue_date BETWEEN :fd AND :td
     WHERE n.agency_id IS NOT NULL
     GROUP BY n.id, n.title, a.name, n.total_impressions
     ORDER BY n.total_impressions DESC
     LIMIT 20"
);
$top_art_stmt->execute([':fd' => $from_date, ':td' => $to_date]);
$top_articles = $top_art_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .stat-cards { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
  .stat-card  {
    flex: 1; min-width: 180px; background: #fff;
    border: 1px solid #dee2e6; border-radius: 8px; padding: 18px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
  }
  .stat-card .label { font-size: 13px; color: #6c757d; margin-bottom: 6px; }
  .stat-card .value { font-size: 26px; font-weight: 700; color: #343a40; }
  .stat-card .value.green  { color: #28a745; }
  .stat-card .value.blue   { color: #007bff; }
  .stat-card .value.orange { color: #fd7e14; }
  .filter-bar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
                background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;
                padding: 14px 16px; margin-bottom: 24px; }
  .filter-bar .field { display: flex; flex-direction: column; gap: 4px; }
  .filter-bar label { font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; }
  .filter-bar input, .filter-bar select {
    padding: 7px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
  }
  .section-box { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 24px; }
  .section-box .section-header { padding: 12px 16px; background: #f8f9fa;
    border-bottom: 1px solid #dee2e6; font-weight: 600; border-radius: 6px 6px 0 0; }
</style>

<div style="padding: 20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2 style="margin:0;">📊 Agency Revenue Dashboard</h2>
    <a href="?from_date=<?= htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8') ?>&to_date=<?= htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8') ?>&export=1"
       class="btn btn-secondary">⬇ Export CSV</a>
  </div>

  <!-- Date filter -->
  <form method="get" class="filter-bar">
    <div class="field">
      <label>From Date</label>
      <input type="date" name="from_date" value="<?= htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="field">
      <label>To Date</label>
      <input type="date" name="to_date" value="<?= htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <button type="submit" class="btn btn-primary">Apply Filter</button>
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <a href="revenue.php" class="btn btn-secondary">Reset</a>
    </div>
  </form>

  <!-- Summary Cards -->
  <div class="stat-cards">
    <div class="stat-card">
      <div class="label">Total Gross Revenue</div>
      <div class="value green">$<?= number_format(floatval($summary['total_gross']), 2) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Platform Share (60%)</div>
      <div class="value blue">$<?= number_format(floatval($summary['total_platform']), 2) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Agency Share (40%)</div>
      <div class="value orange">$<?= number_format(floatval($summary['total_agency']), 2) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Total Articles</div>
      <div class="value"><?= number_format($total_articles) ?></div>
    </div>
  </div>

  <!-- Top Agencies Table -->
  <div class="section-box">
    <div class="section-header">Top Agencies by Revenue</div>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f8f9fa;">
          <th style="padding:10px; border:1px solid #dee2e6;">#</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Agency</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Impressions</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Clicks</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Gross Revenue</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Agency Share</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($top_agencies)): ?>
        <tr><td colspan="7" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No data for selected period.</td></tr>
      <?php else: ?>
        <?php foreach ($top_agencies as $rank => $ag): ?>
        <tr style="<?= $rank < 3 ? 'background:#fffdf0;' : '' ?>">
          <td style="padding:9px 10px; border:1px solid #dee2e6; color:#888; font-weight:600;">
            <?= $rank + 1 ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; font-weight:500;">
            <a href="detail.php?id=<?= intval($ag['id']) ?>">
              <?= htmlspecialchars($ag['name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($ag['total_impressions'])) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($ag['total_clicks'])) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right; font-weight:600;">$<?= number_format(floatval($ag['gross_revenue']), 2) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($ag['agency_share']), 2) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <a href="detail.php?id=<?= intval($ag['id']) ?>&tab=revenue" class="btn btn-sm btn-primary">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Top Articles Table -->
  <div class="section-box">
    <div class="section-header">Top Articles by Impressions</div>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f8f9fa;">
          <th style="padding:10px; border:1px solid #dee2e6;">#</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Title</th>
          <th style="padding:10px; border:1px solid #dee2e6;">Agency</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Impressions</th>
          <th style="padding:10px; border:1px solid #dee2e6; text-align:right;">Revenue</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($top_articles)): ?>
        <tr><td colspan="5" style="text-align:center; padding:16px; border:1px solid #dee2e6;">No article data for selected period.</td></tr>
      <?php else: ?>
        <?php foreach ($top_articles as $rank => $art): ?>
        <tr>
          <td style="padding:9px 10px; border:1px solid #dee2e6; color:#888;"><?= $rank + 1 ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <?= htmlspecialchars($art['title'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6;">
            <?= htmlspecialchars($art['agency_name'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;"><?= number_format(intval($art['total_impressions'])) ?></td>
          <td style="padding:9px 10px; border:1px solid #dee2e6; text-align:right;">$<?= number_format(floatval($art['article_revenue']), 2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
