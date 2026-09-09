<?php
// media/upload.php — FIXED
// Extension was taken from original filename: pathinfo($name, PATHINFO_EXTENSION)
// Attacker can rename shell.php → shell.jpg.php → ext = "php"
// FIXED: derive extension from real MIME only, never from filename
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin', 'editor']);

$maxSize = 5 * 1024 * 1024; // 5MB

$allowed_mime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload error: ' . ($_FILES['file']['error'] ?? 'no file');
    } elseif ($_FILES['file']['size'] > $maxSize) {
        $error = 'File too large (max 5MB)';
    } else {
        // FIXED: real MIME from file content — not from client header or filename
        $real_mime = mime_content_type($_FILES['file']['tmp_name']);

        if (!array_key_exists($real_mime, $allowed_mime)) {
            $error = 'Invalid file type. Only JPG, PNG, WebP, GIF allowed.';
        } else {
            // FIXED: extension derived from validated MIME — never from original name
            $ext     = $allowed_mime[$real_mime];
            $newName = bin2hex(random_bytes(16)) . '.' . $ext;

            $uploadDir = __DIR__ . '/../../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $newName)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO media (file_name, created_at) VALUES (?, NOW())"
                );
                $stmt->execute([$newName]);
                header('Location: ' . ADMIN_URL . '/media/index.php');
                exit;
            } else {
                $error = 'Move failed — check directory permissions';
            }
        }
    }
}
?>

<div class="content-wrapper">
<section class="content-header"><h1>Upload Media</h1></section>
<section class="content">

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:500px">
<div class="card-body">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group">
        <label>Image File (JPG, PNG, WebP, GIF — max 5MB)</label>
        <input type="file" name="file" class="form-control" accept="image/*" required>
    </div>
    <button type="submit" class="btn btn-primary">Upload</button>
    <a href="index.php" class="btn btn-secondary">Cancel</a>
</form>
</div>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
