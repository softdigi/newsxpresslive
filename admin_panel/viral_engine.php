<?php
// ============================================================
// FIXED: viral_engine.php
// BUGS FIXED (confirmed in error_log):
//   FATAL 1: Failed to open stream: ../config/database.php
//   FATAL 2: Failed to open stream: ../includes/header.php
//            (because config was loading from wrong path,
//             crashing before header could be included)
//   FATAL 3: "Cannot modify header information — headers
//             already sent" — output before redirect
// ALSO FIXED:
//   4. Auth check completely commented out (!):
//      // if (!isset($_SESSION['admin_id'])) { ... }
//      Now uses proper $_SESSION['admin'] check via auth.php
//   5. pdo->query() with no error handling — wrapped.
//   6. stopBoost() JS was posting to external API with no CSRF
//      — now posts to local admin action endpoint
//   7. createBoost() was calling external API directly from
//      browser — SSRF/auth bypass risk. Kept for now but
//      noted; ideally route through a local PHP endpoint.
//   8. end_time column referenced — may not exist in schema
//      (schema shows no end_time). Guarded with COALESCE.
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Only admin/super_admin
if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    http_response_code(403);
    exit('Access denied');
}

// Fetch active boosts — guarded against missing end_time column
$active_boosts = [];
try {
    $stmt = $pdo->query("
        SELECT
            vb.*,
            n.title   AS news_title,
            r.name    AS reporter_name,
            COALESCE(
                TIMESTAMPDIFF(HOUR, NOW(), vb.created_at + INTERVAL 24 HOUR),
                0
            ) AS hours_remaining
        FROM viral_boosts vb
        LEFT JOIN news        n ON n.id = vb.news_id
        LEFT JOIN admin_users r ON r.id = vb.reporter_id
        WHERE vb.status IN ('active', 'scheduled')
        ORDER BY vb.created_at DESC
        LIMIT 50
    ");
    $active_boosts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('viral_engine active_boosts error: ' . $e->getMessage());
}

// Fetch approved news not already actively boosted
$pending_news = [];
try {
    $stmt = $pdo->query("
        SELECT n.id, n.title, n.created_at
        FROM news n
        WHERE n.status = 'approved'
          AND n.id NOT IN (
              SELECT news_id FROM viral_boosts WHERE status = 'active'
          )
        ORDER BY n.created_at DESC
        LIMIT 30
    ");
    $pending_news = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('viral_engine pending_news error: ' . $e->getMessage());
}

// Metrics
$total_bonus = array_sum(array_column($active_boosts, 'reporter_bonus'));

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>🔥 Viral Engine Control Panel</h1>
</section>
<section class="content">

<!-- Stats row -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center" style="border-left:4px solid #ef4444">
            <div class="card-body">
                <h3 class="text-danger"><?= count($active_boosts) ?></h3>
                <p class="mb-0">Active Boosts</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center" style="border-left:4px solid #28a745">
            <div class="card-body">
                <h3 class="text-success"><?= count($pending_news) ?></h3>
                <p class="mb-0">Ready to Boost</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center" style="border-left:4px solid #ffc107">
            <div class="card-body">
                <h3 class="text-warning">₹<?= number_format($total_bonus, 2) ?></h3>
                <p class="mb-0">Active Bonus Pool</p>
            </div>
        </div>
    </div>
</div>

<div class="row">

    <!-- News ready to boost -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h5>📰 Ready to Boost</h5></div>
            <div class="card-body" style="max-height:550px;overflow-y:auto">
                <?php if (empty($pending_news)): ?>
                    <p class="text-muted text-center">No approved news available to boost.</p>
                <?php endif; ?>
                <?php foreach ($pending_news as $news): ?>
                <div style="background:#f8f9fa;padding:14px;border-radius:8px;margin-bottom:10px;border-left:4px solid #007bff">
                    <h6 style="margin-bottom:4px"><?= htmlspecialchars($news['title']) ?></h6>
                    <small class="text-muted"><?= htmlspecialchars($news['created_at']) ?></small>
                    <div style="margin-top:8px">
                        <button class="btn btn-danger btn-sm"
                                onclick="openBoostModal(<?= (int)$news['id'] ?>, <?= htmlspecialchars(json_encode($news['title'])) ?>)">
                            🚀 Make Viral
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Active boosts -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h5>📈 Active Viral Boosts</h5></div>
            <div class="card-body" style="max-height:550px;overflow-y:auto">
                <?php if (empty($active_boosts)): ?>
                    <p class="text-muted text-center">No active boosts.</p>
                <?php endif; ?>
                <?php foreach ($active_boosts as $boost): ?>
                <div style="background:#fff5f5;padding:14px;border-radius:8px;margin-bottom:10px;border:2px solid #ef4444">
                    <div style="display:flex;justify-content:space-between;align-items:start">
                        <div>
                            <strong><?= strtoupper(htmlspecialchars($boost['boost_level'])) ?> BOOST</strong>
                            <div><?= htmlspecialchars($boost['news_title'] ?? '-') ?></div>
                            <small class="text-muted">
                                Reporter: <?= htmlspecialchars($boost['reporter_name'] ?? '-') ?>
                                &nbsp;|&nbsp; Bonus: ₹<?= number_format((float)$boost['reporter_bonus'], 2) ?>
                            </small>
                        </div>
                        <!-- FIXED: POST form with CSRF for stop action -->
                        <form method="POST" action="viral/control.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id"         value="<?= (int)$boost['id'] ?>">
                            <input type="hidden" name="action"     value="complete">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Stop this boost?')">Stop</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
</section>
</div>

<!-- Boost Modal -->
<div class="modal fade" id="boostModal" tabindex="-1" role="dialog">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#ef4444,#f97316);color:#fff">
    <h5 class="modal-title">⚡ Configure Viral Boost</h5>
    <button type="button" class="close" data-dismiss="modal"
            style="color:#fff;background:transparent;border:none;font-size:20px">&times;</button>
</div>

<!-- FIXED: Route through local viral/create.php (not directly to external API) -->
<form method="POST" action="viral/create.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<div class="modal-body">
    <input type="hidden" name="news_id" id="newsId">
    <div class="alert alert-info"><strong id="newsTitle"></strong></div>

    <div class="form-group">
        <label><strong>Boost Level</strong></label>
        <div class="row">
            <?php
            $levels = [
                'low'    => ['label' => '🔹 LOW — 2×',    'bonus' => 50],
                'medium' => ['label' => '⭐ MEDIUM — 5×', 'bonus' => 100],
                'high'   => ['label' => '🔥 HIGH — 10×',  'bonus' => 500],
                'mega'   => ['label' => '💥 MEGA — 50×',  'bonus' => 1000],
            ];
            foreach ($levels as $val => $info):
            ?>
            <div class="col-6 mb-2">
                <div class="form-check" style="border:1px solid #ddd;padding:10px;border-radius:6px">
                    <input class="form-check-input" type="radio"
                           name="boost_level" id="lvl_<?= $val ?>"
                           value="<?= $val ?>" <?= $val === 'low' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lvl_<?= $val ?>">
                        <?= $info['label'] ?> <small class="text-muted">(₹<?= $info['bonus'] ?>)</small>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-group">
        <label><strong>Reporter Bonus (₹)</strong></label>
        <input type="number" name="reporter_bonus" class="form-control"
               value="50" min="0" step="0.01" required>
    </div>

    <div class="form-group">
        <label><strong>Status</strong></label>
        <select name="status" class="form-control">
            <option value="active">Active (start now)</option>
            <option value="completed">Completed</option>
        </select>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-danger">🚀 Launch Boost</button>
</div>
</form>
</div>
</div>
</div>

<script>
function openBoostModal(newsId, newsTitle) {
    document.getElementById('newsId').value    = newsId;
    document.getElementById('newsTitle').textContent = newsTitle;
    // Bootstrap 3 / 4 compatible
    if (typeof $ !== 'undefined') {
        $('#boostModal').modal('show');
    } else {
        document.getElementById('boostModal').style.display = 'block';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
