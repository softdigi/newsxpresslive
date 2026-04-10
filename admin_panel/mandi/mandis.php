<?php
/**
 * admin_panel/mandi/mandis.php
 *
 * Admin: Manage mandi list — add, edit, deactivate.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../web/includes/config.php';

$message = '';
$error   = '';

/* ── handle actions ───────────────────────────────────────── */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    $name     = trim($_POST['name'] ?? '');
    $nameHi   = trim($_POST['name_hi'] ?? '');
    $stateId  = (int)($_POST['state_id']    ?? 0);
    $distId   = (int)($_POST['district_id'] ?? 0);
    $city     = trim($_POST['city'] ?? '');
    $lat      = $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $lng      = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $stateId <= 0 || $distId <= 0) {
        $error = 'Name, State ID, and District ID are required.';
    } elseif ($id > 0) {
        $pdo->prepare(
            'UPDATE mandis SET name=?,name_hi=?,state_id=?,district_id=?,city=?,latitude=?,longitude=?,is_active=? WHERE id=?'
        )->execute([$name,$nameHi,$stateId,$distId,$city,$lat,$lng,$isActive,$id]);
        $message = 'Mandi updated.';
    } else {
        $pdo->prepare(
            'INSERT INTO mandis (name,name_hi,state_id,district_id,city,latitude,longitude,is_active) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$name,$nameHi,$stateId,$distId,$city,$lat,$lng,$isActive]);
        $message = 'Mandi added.';
    }
}

if ($action === 'toggle' && isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $pdo->prepare('UPDATE mandis SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['id']]);
    $message = 'Mandi status toggled.';
}

/* ── edit mode ────────────────────────────────────────────── */
$editRow = null;
if ($action === 'edit' && isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $s = $pdo->prepare('SELECT * FROM mandis WHERE id = ?');
    $s->execute([(int)$_GET['id']]);
    $editRow = $s->fetch();
}

/* ── list ─────────────────────────────────────────────────── */
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $listStmt = $pdo->prepare('SELECT * FROM mandis WHERE name LIKE ? OR name_hi LIKE ? ORDER BY name LIMIT 100');
    $listStmt->execute(["%{$search}%", "%{$search}%"]);
} else {
    $listStmt = $pdo->query('SELECT * FROM mandis ORDER BY is_active DESC, name LIMIT 200');
}
$mandis = $listStmt->fetchAll();
?>

<div class="page-header">
    <h1>🏪 Manage Mandis</h1>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Add / Edit Form -->
<div class="admin-card" style="margin-bottom:24px">
    <h3><?= $editRow ? 'Edit Mandi' : 'Add New Mandi' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= $editRow['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
                <label>Name (English) *</label>
                <input type="text" name="name" class="form-control" required
                       value="<?= htmlspecialchars($editRow['name'] ?? '') ?>">
            </div>
            <div>
                <label>Name (Hindi)</label>
                <input type="text" name="name_hi" class="form-control"
                       value="<?= htmlspecialchars($editRow['name_hi'] ?? '') ?>">
            </div>
            <div>
                <label>State ID *</label>
                <input type="number" name="state_id" class="form-control" required
                       value="<?= htmlspecialchars((string)($editRow['state_id'] ?? '')) ?>">
            </div>
            <div>
                <label>District ID *</label>
                <input type="number" name="district_id" class="form-control" required
                       value="<?= htmlspecialchars((string)($editRow['district_id'] ?? '')) ?>">
            </div>
            <div>
                <label>City</label>
                <input type="text" name="city" class="form-control"
                       value="<?= htmlspecialchars($editRow['city'] ?? '') ?>">
            </div>
            <div>
                <label>Latitude</label>
                <input type="number" name="latitude" class="form-control" step="0.000001"
                       value="<?= htmlspecialchars((string)($editRow['latitude'] ?? '')) ?>">
            </div>
            <div>
                <label>Longitude</label>
                <input type="number" name="longitude" class="form-control" step="0.000001"
                       value="<?= htmlspecialchars((string)($editRow['longitude'] ?? '')) ?>">
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding-top:20px">
                <input type="checkbox" name="is_active" id="is_active"
                       <?= ($editRow['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label for="is_active">Active</label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:16px">
            <?= $editRow ? '💾 Update Mandi' : '➕ Add Mandi' ?>
        </button>
        <?php if ($editRow): ?>
            <a href="mandis.php" class="btn btn-secondary" style="margin-left:8px">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<!-- Search -->
<form method="GET" style="margin-bottom:16px;display:flex;gap:8px">
    <input type="text" name="q" placeholder="Search mandi name…" class="form-control"
           style="max-width:300px" value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<!-- List -->
<table class="admin-table">
    <thead>
        <tr>
            <th>#</th><th>Name</th><th>Hindi</th><th>City</th>
            <th>State/District</th><th>Coords</th><th>Status</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($mandis as $m): ?>
        <tr>
            <td><?= $m['id'] ?></td>
            <td><?= htmlspecialchars($m['name']) ?></td>
            <td><?= htmlspecialchars($m['name_hi'] ?? '') ?></td>
            <td><?= htmlspecialchars($m['city'] ?? '') ?></td>
            <td><?= $m['state_id'] ?> / <?= $m['district_id'] ?></td>
            <td><?= $m['latitude'] ? "{$m['latitude']}, {$m['longitude']}" : '—' ?></td>
            <td>
                <span class="badge <?= $m['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                    <?= $m['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </td>
            <td>
                <a href="?action=edit&id=<?= $m['id'] ?>" class="btn btn-xs">Edit</a>
                <a href="?action=toggle&id=<?= $m['id'] ?>"
                   onclick="return confirm('Toggle status?')"
                   class="btn btn-xs btn-secondary">
                    <?= $m['is_active'] ? 'Deactivate' : 'Activate' ?>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
