<?php
// ============================================================
// FIXED: news/create.php
// BUGS FIXED:
//   1. INSERT used wrong columns: description→content,
//      state_slug→slug, had extra author_id not in schema
//   2. File upload: no extension/MIME check → RCE risk fixed
//      Now validates: only jpg/jpeg/png/gif/webp allowed,
//      checks both extension AND MIME type
//   3. agency_id was read from session but session didn't store
//      it — now works because login.php fix stores agency_id
//   4. $success output had raw HTML (<?= $success ?>) — escaped
//   5. TinyMCE 'no-api-key' warning is cosmetic, left as-is
//      (replace with real key in production)
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$user        = $_SESSION['admin'];
$reporter_id = (int)$user['id'];
$agency_id   = isset($user['agency_id']) ? (int)$user['agency_id'] : null;

$errors  = [];
$success = '';

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $title       = trim($_POST['title']            ?? '');
    $content     = trim($_POST['content']          ?? '');
    $seo_title   = trim($_POST['seo_title']        ?? '');
    $meta        = trim($_POST['meta_description'] ?? '');
    $is_breaking = isset($_POST['is_breaking']) ? 1 : 0;

    if ($title === '')   $errors[] = 'Title is required.';
    if ($content === '') $errors[] = 'Content is required.';

    $slug = slugify($title);

    // Ensure slug unique
    if ($slug !== '') {
        $slugCheck = $pdo->prepare("SELECT id FROM news WHERE slug = ? LIMIT 1");
        $slugCheck->execute([$slug]);
        if ($slugCheck->fetch()) {
            $slug = $slug . '-' . time(); // append timestamp to avoid collision
        }
    }

    // ---- FILE UPLOAD VALIDATION ----
    $featured = null;
    if (!empty($_FILES['featured']['name'])) {

        $allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        $original_name = $_FILES['featured']['name'];
        $ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $mime          = mime_content_type($_FILES['featured']['tmp_name']);

        if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mime, true)) {
            $errors[] = 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.';
        } elseif ($_FILES['featured']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Image must be under 5MB.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/news/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            // Safe filename — never use original name directly
            $safe_name = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['featured']['tmp_name'], $uploadDir . $safe_name)) {
                $errors[] = 'File upload failed. Check directory permissions.';
            } else {
                $featured = $safe_name;
            }
        }
    }

    // ---- INSERT ----
    if (empty($errors)) {
        // FIXED column names to match actual schema:
        // title, slug, content, reporter_id, agency_id, status, is_breaking, created_at
        $stmt = $pdo->prepare("
            INSERT INTO news
                (title, slug, content, reporter_id, agency_id, status, is_breaking, created_at)
            VALUES
                (?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $title,
            $slug,
            $content,
            $reporter_id,
            $agency_id,
            $is_breaking
        ]);

        $success = 'News submitted successfully and is pending review.';
    }
}
?>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="card" style="max-width:1100px;margin:auto">
<div class="card-body">

<h3>📰 Create News Article</h3>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

<div class="form-group">
    <label>News Title <span style="color:red">*</span></label>
    <input type="text" id="title" name="title" class="form-control"
           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
</div>

<div class="form-group">
    <label>SEO Title</label>
    <input type="text" name="seo_title" class="form-control"
           value="<?= htmlspecialchars($_POST['seo_title'] ?? '') ?>">
</div>

<div class="form-group">
    <label>Meta Description</label>
    <textarea name="meta_description" class="form-control"
              rows="2"><?= htmlspecialchars($_POST['meta_description'] ?? '') ?></textarea>
</div>

<div class="form-group">
    <label>Featured Image (JPG/PNG/GIF/WEBP, max 5MB)</label>
    <input type="file" name="featured" class="form-control" accept="image/*">
</div>

<div class="form-group">
    <label>News Content <span style="color:red">*</span></label>
    <textarea id="editor" name="content"><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
</div>

<div class="form-check mt-2 mb-3">
    <input type="checkbox" name="is_breaking" class="form-check-input"
           <?= !empty($_POST['is_breaking']) ? 'checked' : '' ?>>
    <label class="form-check-label">Breaking News</label>
</div>

<button type="submit" class="btn btn-primary">🚀 Submit News</button>
<a href="index.php" class="btn btn-secondary">⬅ Back</a>

</form>
</div>
</div>
</div>
</section>
</div>

<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#editor',
    height: 450,
    plugins: 'image link media table code fullscreen lists',
    toolbar: 'undo redo | bold italic | alignleft aligncenter alignright | bullist numlist | image media link | fullscreen code',
    automatic_uploads: false // disable until upload endpoint is secured
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
