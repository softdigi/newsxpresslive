<?php
// media/index.php — FIXED
// LIMIT/OFFSET interpolated directly — SQL injection risk
// Delete link used GET CSRF — changed to POST form
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin', 'editor']);

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 24;
$offset = ($page - 1) * $limit;

// FIXED: separate COUNT, no SQL_CALC_FOUND_ROWS
$total       = (int)$pdo->query("SELECT COUNT(*) FROM media")->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

// FIXED: LIMIT/OFFSET via bindValue
$stmt = $pdo->prepare(
    "SELECT id, file_name, alt_text, created_at FROM media ORDER BY id DESC LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$data = $stmt->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>Media Library (<?= $total ?>)</h1>
    <a href="upload.php" class="btn btn-primary btn-sm">+ Upload</a>
</section>
<section class="content">

<form method="POST" action="bulk_delete.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">
<?php foreach ($data as $row): ?>
<div style="width:150px;text-align:center;background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px">
    <input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>">
    <img src="/uploads/<?= htmlspecialchars(basename($row['file_name'])) ?>"
         style="width:120px;height:100px;object-fit:cover;border-radius:4px;margin:6px 0"
         alt="<?= htmlspecialchars($row['alt_text'] ?? '') ?>">
    <div style="display:flex;gap:4px;justify-content:center">
        <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
        <!-- FIXED: POST form for delete, not GET link with CSRF in URL -->
        <button type="submit" name="ids[]" value="<?= (int)$row['id'] ?>"
                class="btn btn-sm btn-danger"
                formaction="delete.php"
                onclick="return confirm('Delete this file?')">Del</button>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($data)): ?>
<p style="color:#888">No media files found.</p>
<?php endif; ?>
</div>

<button type="submit" class="btn btn-danger btn-sm"
        onclick="return confirm('Delete all selected files?')">Bulk Delete Selected</button>
</form>

<!-- Pagination -->
<div style="margin-top:16px;display:flex;gap:5px">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i === $page): ?>
            <span style="padding:5px 10px;background:#007bff;color:#fff;border-radius:4px"><?= $i ?></span>
        <?php else: ?>
            <a href="?page=<?= $i ?>"
               style="padding:5px 10px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
