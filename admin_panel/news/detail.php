<?php
// ============================================================
// FIXED: news/detail.php
// BUGS FIXED (seen in error_log):
//   FATAL: "Unknown column 'n.user_id' in ON"
//   Root cause: Old query joined 'users' table with 'n.user_id'
//   but schema has 'admin_users' with 'n.reporter_id'
//   Also removed joins to: categories, languages, countries,
//   states, districts — these tables are NOT in the provided
//   schema and cause FATAL errors.
//   If you add these tables later, re-add the joins.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin', 'editor'])) {
    header('Location: ' . ADMIN_URL . '/login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . ADMIN_URL . '/news/pending.php');
    exit;
}

// FIXED: Use admin_users with reporter_id (not users/user_id)
// Removed: categories, languages, countries, states, districts joins
$stmt = $pdo->prepare("
    SELECT
        n.*,
        r.name  AS author,
        r.role  AS author_role,
        r.email AS author_email
    FROM news n
    LEFT JOIN admin_users r ON r.id = n.reporter_id AND r.role = 'reporter'
    WHERE n.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$news = $stmt->fetch();

if (!$news) {
    header('Location: ' . ADMIN_URL . '/news/pending.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content">
  <h1>News Review — #<?= (int)$news['id'] ?></h1>

  <div class="news-box" style="background:#fff;padding:20px;border-radius:8px;max-width:900px">

    <h2><?= htmlspecialchars($news['title']) ?></h2>

    <?php if (!empty($news['slug'])): ?>
        <p style="color:#888;font-size:13px">Slug: <?= htmlspecialchars($news['slug']) ?></p>
    <?php endif; ?>

    <p style="white-space:pre-wrap"><?= nl2br(htmlspecialchars($news['content'] ?? $news['description'] ?? '')) ?></p>

    <hr>

    <p><b>Author:</b> <?= htmlspecialchars($news['author'] ?? 'Unknown') ?>
       (<?= htmlspecialchars(ucfirst($news['author_role'] ?? '')) ?>)</p>
    <p><b>Status:</b> <?= strtoupper(htmlspecialchars($news['status'])) ?></p>
    <p><b>Views:</b> <?= number_format((int)$news['views']) ?></p>
    <p><b>Breaking:</b> <?= $news['is_breaking'] ? 'Yes' : 'No' ?></p>
    <p><b>Created:</b> <?= htmlspecialchars($news['created_at']) ?></p>

    <hr>

    <div class="action-buttons" style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($news['status'] === 'pending'): ?>

        <form method="POST" action="<?= ADMIN_URL ?>/actions/approve_news.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$news['id'] ?>">
            <button type="submit" class="btn btn-success">✅ Approve</button>
        </form>

        <form method="POST" action="<?= ADMIN_URL ?>/actions/reject_news.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$news['id'] ?>">
            <button type="submit" class="btn btn-danger">❌ Reject</button>
        </form>

      <?php endif; ?>

      <?php if ($news['status'] === 'approved'): ?>

        <form method="POST" action="<?= ADMIN_URL ?>/actions/breaking_news.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="id"     value="<?= (int)$news['id'] ?>">
            <input type="hidden" name="action" value="<?= $news['is_breaking'] ? 'remove' : 'add' ?>">
            <button type="submit" class="btn btn-warning">
                <?= $news['is_breaking'] ? '🔕 Remove Breaking' : '🔥 Make Breaking' ?>
            </button>
        </form>

      <?php endif; ?>

      <a href="<?= ADMIN_URL ?>/news/view.php?id=<?= (int)$news['id'] ?>" class="btn btn-primary">View Full</a>
      <a href="<?= ADMIN_URL ?>/news/pending.php" class="btn btn-secondary">Back</a>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
