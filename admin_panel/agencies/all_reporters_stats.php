<?php
// ============================================================
// FIXED: agencies/all_reporters_stats.php
// BUG: Date condition was placed inside LEFT JOIN ON clause:
//
//   LEFT JOIN news n
//       ON n.reporter_id = r.id
//       $dateCondition       ← WRONG: this is part of ON clause
//
// This is a classic MySQL LEFT JOIN trap:
// When a filter is in the ON clause of a LEFT JOIN, MySQL
// still returns the left-side row but sets right-side columns
// to NULL. The date filter has NO effect on which rows are
// counted — it just NULLs out the news columns.
//
// CORRECT approach: move the date filter to a subquery or
// use conditional aggregation with CASE WHEN inside SUM().
//
// FIXED with conditional aggregation — no subquery needed,
// same performance, correct semantics:
//   SUM(CASE WHEN n.status='approved' AND <date_cond> THEN 1 END)
//
// ALSO FIXED:
//   2. $range whitelist — arbitrary string injection into SQL
//      was possible if $dateCondition was user-controlled
//      (it's not here, but still good practice)
//   3. Added pagination — could return thousands of rows
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    exit('Access denied');
}

// Whitelist range input
$allowed_ranges = ['all', '7', '30'];
$range = in_array($_GET['range'] ?? '', $allowed_ranges) ? $_GET['range'] : 'all';

// Build date condition for conditional aggregation
// FIXED: Use CASE WHEN inside SUM() — not inside JOIN ON clause
$date_cond = '1=1'; // default: all time
if ($range === '7') {
    $date_cond = "n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($range === '30') {
    $date_cond = "n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// Pagination
$per_page = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Count reporters
$cnt_stmt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'reporter'");
$total       = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// FIXED QUERY:
// Date filter moved from LEFT JOIN ON → CASE WHEN inside SUM()
// This correctly counts only news within the date range,
// while still showing reporters who have no news in that range
// (with 0 counts) rather than hiding them.
$sql = "
SELECT
    a.id   AS agency_id,
    a.name AS agency_name,
    r.id   AS reporter_id,
    r.name AS reporter_name,
    r.email,

    COUNT(DISTINCT n.id)                                       AS total_news,
    SUM(CASE WHEN n.status = 'approved' AND $date_cond
             THEN 1 ELSE 0 END)                               AS approved_news,
    SUM(CASE WHEN n.status = 'rejected' AND $date_cond
             THEN 1 ELSE 0 END)                               AS rejected_news,
    COALESCE(SUM(CASE WHEN $date_cond THEN n.views ELSE 0 END), 0)
                                                               AS total_views,
    COALESCE(SUM(v.reporter_bonus), 0)                        AS total_earnings

FROM admin_users r

LEFT JOIN admin_users a
    ON a.id = r.agency_id AND a.role = 'agency'

LEFT JOIN news n
    ON n.reporter_id = r.id      -- No date filter here (correct LEFT JOIN)

LEFT JOIN viral_boosts v
    ON v.reporter_id = r.id

WHERE r.role = 'reporter'

GROUP BY r.id, r.name, r.email, a.id, a.name
ORDER BY a.name ASC, total_views DESC
LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(2, $offset,   PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$qs = http_build_query(array_filter(['range' => $range]));
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>All Agencies — Reporter Performance</h1>
</section>
<section class="content">

<!-- Date range filter -->
<form method="GET" style="margin-bottom:15px;display:flex;gap:8px;align-items:center">
    <?php foreach (['all' => 'All Time', '7' => 'Last 7 Days', '30' => 'Last 30 Days'] as $val => $label): ?>
        <button type="submit" name="range" value="<?= $val ?>"
                class="btn <?= $range === $val ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            <?= $label ?>
        </button>
    <?php endforeach; ?>
    <span style="font-size:13px;color:#888;margin-left:auto"><?= $total ?> reporters total</span>
</form>

<div class="table-responsive">
<table class="table table-bordered table-striped">
<thead>
<tr>
    <th>Agency</th>
    <th>Reporter</th>
    <th>Total News</th>
    <th>Approved <?= $range !== 'all' ? "($range d)" : '' ?></th>
    <th>Rejected <?= $range !== 'all' ? "($range d)" : '' ?></th>
    <th>Views <?= $range !== 'all' ? "($range d)" : '' ?></th>
    <th>Total Earnings</th>
</tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="7" style="text-align:center;color:#888">No data found.</td></tr>
<?php endif; ?>

<?php
$prev_agency = null;
foreach ($rows as $r):
    $agency_display = $r['agency_name'] ?? 'Independent';
?>
<tr>
    <td>
        <?php if ($agency_display !== $prev_agency): ?>
            <span style="font-weight:bold;color:#007bff"><?= htmlspecialchars($agency_display) ?></span>
        <?php else: ?>
            <span style="color:#ccc">↳</span>
        <?php endif; ?>
        <?php $prev_agency = $agency_display; ?>
    </td>
    <td>
        <a href="../reporters/view.php?id=<?= (int)$r['reporter_id'] ?>">
            <?= htmlspecialchars($r['reporter_name']) ?>
        </a><br>
        <small style="color:#888"><?= htmlspecialchars($r['email']) ?></small>
    </td>
    <td><?= (int)$r['total_news'] ?></td>
    <td style="color:#28a745;font-weight:bold"><?= (int)$r['approved_news'] ?></td>
    <td style="color:#dc3545"><?= (int)$r['rejected_news'] ?></td>
    <td><?= number_format((int)$r['total_views']) ?></td>
    <td>₹<?= number_format((float)$r['total_earnings'], 2) ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<!-- Pagination -->
<div style="display:flex;gap:5px;margin-top:10px;flex-wrap:wrap">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i === $page): ?>
            <span style="padding:5px 12px;background:#007bff;color:#fff;border-radius:4px"><?= $i ?></span>
        <?php else: ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"
               style="padding:5px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<p style="font-size:13px;color:#888;margin-top:8px">Page <?= $page ?>/<?= $total_pages ?></p>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
