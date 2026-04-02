<?php
// ============================================================
// admin_panel/analytics/ab_tests.php
// List all A/B headline tests with quick stats.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin', 'editor']);

// Handle status-change actions (pause / resume / conclude)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['test_id'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    verify_csrf();

    $testId = (int)$_POST['test_id'];
    $action = $_POST['action'];

    if ($action === 'pause') {
        $pdo->prepare("UPDATE ab_tests SET status='paused' WHERE id=?")->execute([$testId]);
    } elseif ($action === 'resume') {
        $pdo->prepare("UPDATE ab_tests SET status='running' WHERE id=?")->execute([$testId]);
    } elseif ($action === 'conclude' && isset($_POST['winner'])) {
        $winner = in_array($_POST['winner'], ['a', 'b']) ? $_POST['winner'] : 'a';
        $pdo->prepare(
            "UPDATE ab_tests SET status='concluded', winner=?, concluded_at=NOW() WHERE id=?"
        )->execute([$winner, $testId]);
    }
    header('Location: ab_tests.php');
    exit;
}

// ── Fetch tests with aggregated stats ────────────────────────
$tests = $pdo->query("
    SELECT t.*,
           n.title AS current_title,
           -- impressions
           SUM(CASE WHEN e.variant='a' AND e.event_type='impression' THEN 1 ELSE 0 END) AS imp_a,
           SUM(CASE WHEN e.variant='b' AND e.event_type='impression' THEN 1 ELSE 0 END) AS imp_b,
           -- clicks
           SUM(CASE WHEN e.variant='a' AND e.event_type='click'      THEN 1 ELSE 0 END) AS clk_a,
           SUM(CASE WHEN e.variant='b' AND e.event_type='click'      THEN 1 ELSE 0 END) AS clk_b
    FROM ab_tests t
    LEFT JOIN news n ON n.id = t.news_id
    LEFT JOIN ab_test_events e ON e.test_id = t.id
    GROUP BY t.id
    ORDER BY t.created_at DESC
")->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header" style="display:flex;justify-content:space-between;align-items:center">
    <h1>🧪 A/B Headline Tests</h1>
    <a href="ab_test_create.php"
       style="padding:7px 16px;background:#27ae60;color:#fff;text-decoration:none;border-radius:4px;font-size:14px">
        + New Test
    </a>
</section>
<section class="content">

<?php if (empty($tests)): ?>
    <div class="card"><p style="color:#888">No A/B tests yet. <a href="ab_test_create.php">Create one →</a></p></div>
<?php else: ?>

<div class="card" style="overflow-x:auto">
<table style="width:100%;border-collapse:collapse;font-size:14px">
<thead>
<tr style="background:#f4f6f8">
    <th style="padding:8px 10px;text-align:left">ID</th>
    <th style="padding:8px 10px;text-align:left">Article</th>
    <th style="padding:8px 10px;text-align:left">Variant A</th>
    <th style="padding:8px 10px;text-align:left">Variant B</th>
    <th style="padding:8px 10px;text-align:center">CTR A</th>
    <th style="padding:8px 10px;text-align:center">CTR B</th>
    <th style="padding:8px 10px;text-align:center">Status</th>
    <th style="padding:8px 10px;text-align:center">Winner</th>
    <th style="padding:8px 10px;text-align:center">Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($tests as $t):
    $ctrA = $t['imp_a'] > 0 ? round($t['clk_a'] / $t['imp_a'] * 100, 2) : 0;
    $ctrB = $t['imp_b'] > 0 ? round($t['clk_b'] / $t['imp_b'] * 100, 2) : 0;
    $statusColors = ['running' => '#27ae60', 'paused' => '#e67e22', 'concluded' => '#7f8c8d'];
    $sc = $statusColors[$t['status']] ?? '#999';
?>
<tr style="border-bottom:1px solid #eee">
    <td style="padding:8px 10px"><?= $t['id'] ?></td>
    <td style="padding:8px 10px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <a href="ab_test_view.php?id=<?= $t['id'] ?>" style="color:#3498db">
            #<?= $t['news_id'] ?>
        </a>
        <?php if ($t['current_title']): ?>
            <br><small style="color:#888"><?= htmlspecialchars(mb_strimwidth($t['current_title'], 0, 60, '…'), ENT_QUOTES) ?></small>
        <?php endif; ?>
    </td>
    <td style="padding:8px 10px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= htmlspecialchars(mb_strimwidth($t['title_a'], 0, 70, '…'), ENT_QUOTES) ?>
        <br><small style="color:#888"><?= number_format($t['imp_a']) ?> impr / <?= number_format($t['clk_a']) ?> clk</small>
    </td>
    <td style="padding:8px 10px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= htmlspecialchars(mb_strimwidth($t['title_b'], 0, 70, '…'), ENT_QUOTES) ?>
        <br><small style="color:#888"><?= number_format($t['imp_b']) ?> impr / <?= number_format($t['clk_b']) ?> clk</small>
    </td>
    <td style="padding:8px 10px;text-align:center;font-weight:bold;color:<?= $ctrA > $ctrB ? '#27ae60' : '#333' ?>"><?= $ctrA ?>%</td>
    <td style="padding:8px 10px;text-align:center;font-weight:bold;color:<?= $ctrB > $ctrA ? '#27ae60' : '#333' ?>"><?= $ctrB ?>%</td>
    <td style="padding:8px 10px;text-align:center">
        <span style="background:<?= $sc ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">
            <?= ucfirst($t['status']) ?>
        </span>
    </td>
    <td style="padding:8px 10px;text-align:center">
        <?= $t['winner'] ? '<strong style="color:#27ae60">Variant ' . strtoupper($t['winner']) . '</strong>' : '—' ?>
    </td>
    <td style="padding:8px 10px;text-align:center;white-space:nowrap">
        <a href="ab_test_view.php?id=<?= $t['id'] ?>"
           style="font-size:12px;color:#3498db;margin-right:6px">View</a>

        <?php if ($t['status'] === 'running'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="test_id" value="<?= $t['id'] ?>">
            <input type="hidden" name="action"  value="pause">
            <button type="submit"
                    style="font-size:12px;background:#e67e22;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 8px">
                Pause
            </button>
        </form>
        <?php elseif ($t['status'] === 'paused'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="test_id" value="<?= $t['id'] ?>">
            <input type="hidden" name="action"  value="resume">
            <button type="submit"
                    style="font-size:12px;background:#27ae60;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 8px">
                Resume
            </button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
