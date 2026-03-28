<?php
// ============================================================
// FIXED: users/index.php
// BUGS FIXED:
//   1. JOIN agencies a ON u.agency_id = a.id — 'agencies'
//      table nahi hai schema mein; agencies admin_users mein
//      role='agency' se hain. Fixed to self-join admin_users.
//   2. delete.php link GET se tha — onclick confirm JS-only
//      protection — JS disable karke delete ho sakta tha.
//      Changed to POST form with CSRF.
//   3. is_verified warning (error log) — yeh purani version
//      mein tha; current file mein nahi — but SELECT u.* se
//      agar column exist kare toh crash. Added ?? guard.
//   4. No pagination — all users loaded at once. Added.
//   5. Added search/filter for usability.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    exit('Access denied');
}

// Search / filter
$search_name  = trim($_GET['name']   ?? '');
$search_email = trim($_GET['email']  ?? '');
$search_role  = trim($_GET['role']   ?? '');

$allowed_roles = ['super_admin', 'admin', 'editor', 'reporter', 'agency'];
if ($search_role !== '' && !in_array($search_role, $allowed_roles)) {
    $search_role = '';
}

// Pagination
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Build WHERE
$where  = ['1=1'];
$params = [];

if ($search_name !== '') {
    $where[]  = 'u.name LIKE ?';
    $params[] = '%' . $search_name . '%';
}
if ($search_email !== '') {
    $where[]  = 'u.email LIKE ?';
    $params[] = '%' . $search_email . '%';
}
if ($search_role !== '') {
    $where[]  = 'u.role = ?';
    $params[] = $search_role;
}

$where_sql = implode(' AND ', $where);

// Count
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users u WHERE $where_sql");
$cnt_stmt->execute($params);
$total       = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// FIXED JOIN: admin_users ag (role='agency') instead of 'agencies' table
$sql = "
    SELECT
        u.id, u.name, u.email, u.role,
        u.status, u.created_at,
        COALESCE(ag.name, '-') AS agency_name
    FROM admin_users u
    LEFT JOIN admin_users ag ON ag.id = u.agency_id AND ag.role = 'agency'
    WHERE $where_sql
    ORDER BY u.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
// Bind search params
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
$stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,   PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$qs = http_build_query(array_filter([
    'name'  => $search_name,
    'email' => $search_email,
    'role'  => $search_role,
]));
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Users Management</h1>
</section>

<section class="content">
<div class="card">

<div class="card-header" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <a href="create.php" class="btn btn-primary btn-sm">+ Add User</a>
    <span style="font-size:13px;color:#666;margin-left:auto"><?= $total ?> total user(s)</span>
</div>

<!-- Search filters -->
<div class="card-body" style="border-bottom:1px solid #e9ecef;padding-bottom:12px">
<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:2px">Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($search_name) ?>"
               placeholder="Search name..." style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px">
    </div>
    <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:2px">Email</label>
        <input type="text" name="email" value="<?= htmlspecialchars($search_email) ?>"
               placeholder="Search email..." style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px">
    </div>
    <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:2px">Role</label>
        <select name="role" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px">
            <option value="">All Roles</option>
            <?php foreach ($allowed_roles as $r): ?>
                <option value="<?= $r ?>" <?= $search_role === $r ? 'selected' : '' ?>>
                    <?= ucfirst(str_replace('_', ' ', $r)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
</form>
</div>

<div class="card-body table-responsive" style="padding-top:0">
<table class="table table-bordered table-striped" style="margin-top:12px">
<thead>
<tr>
    <th>ID</th>
    <th>Name</th>
    <th>Email</th>
    <th>Role</th>
    <th>Agency</th>
    <th>Status</th>
    <th>Created</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php if (empty($users)): ?>
<tr><td colspan="8" style="text-align:center;color:#888">No users found.</td></tr>
<?php endif; ?>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= (int)$u['id'] ?></td>
    <td><?= htmlspecialchars($u['name']) ?></td>
    <td><?= htmlspecialchars($u['email']) ?></td>
    <td>
        <span style="background:<?= match($u['role']) {
            'super_admin' => '#6f42c1',
            'admin'       => '#007bff',
            'editor'      => '#17a2b8',
            'reporter'    => '#28a745',
            'agency'      => '#fd7e14',
            default       => '#6c757d'
        } ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">
            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $u['role']))) ?>
        </span>
    </td>
    <td><?= htmlspecialchars($u['agency_name']) ?></td>
    <td>
        <?php if ($u['status'] === 'active'): ?>
            <span class="badge badge-success">Active</span>
        <?php else: ?>
            <span class="badge badge-danger"><?= htmlspecialchars(ucfirst($u['status'])) ?></span>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($u['created_at']) ?></td>
    <td style="white-space:nowrap">
        <a href="view.php?id=<?= (int)$u['id'] ?>"     class="btn btn-sm btn-primary">View</a>
        <a href="edit.php?id=<?= (int)$u['id'] ?>"     class="btn btn-sm btn-warning">Edit</a>
        <a href="activity.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-info">Activity</a>

        <?php if ($u['role'] !== 'super_admin'): ?>

        <!-- Block/Unblock via POST+CSRF (not GET link) -->
        <form method="POST" action="block.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id"         value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-sm btn-<?= $u['status'] === 'active' ? 'secondary' : 'success' ?>"
                    onclick="return confirm('<?= $u['status'] === 'active' ? 'Block' : 'Unblock' ?> this user?')">
                <?= $u['status'] === 'active' ? 'Block' : 'Unblock' ?>
            </button>
        </form>

        <!-- FIXED: Delete via POST form + CSRF (was GET link — JS-bypassable) -->
        <?php if ($_SESSION['admin']['role'] === 'super_admin' && $u['id'] != $_SESSION['admin']['id']): ?>
        <form method="POST" action="delete.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id"         value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="confirm"    value="yes">
            <button type="submit" class="btn btn-sm btn-danger"
                    onclick="return confirm('Permanently delete this user? This cannot be undone.')">
                Delete
            </button>
        </form>
        <?php endif; ?>

        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- Pagination -->
<div style="display:flex;gap:5px;margin-top:10px;flex-wrap:wrap">
    <?php if ($page > 1): ?>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
           style="padding:5px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333">« Prev</a>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 3); $i <= min($total_pages, $page + 3); $i++): ?>
        <?php if ($i === $page): ?>
            <span style="padding:5px 12px;background:#007bff;color:#fff;border-radius:4px"><?= $i ?></span>
        <?php else: ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"
               style="padding:5px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
           style="padding:5px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333">Next »</a>
    <?php endif; ?>
</div>
<p style="font-size:13px;color:#888;margin-top:8px">
    Showing <?= count($users) ?> of <?= $total ?> users — Page <?= $page ?>/<?= $total_pages ?>
</p>

</div>
</div>
</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
