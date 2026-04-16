<?php
/**
 * admin_panel/bot/drafts.php
 * Admin — AI Reporter Bot draft review queue.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../web/includes/config.php';

// Basic admin session check (reuse existing admin auth pattern)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

// ── Handle approve / reject actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['article_id'])) {
    $article_id = (int)$_POST['article_id'];
    $action     = $_POST['action'];

    if ($action === 'approve' && $article_id > 0) {
        $new_title   = trim($_POST['title']   ?? '');
        $new_content = trim($_POST['content'] ?? '');
        $sets   = ["status = 'approved'", "approved_at = NOW()"];
        $params = [];
        if ($new_title !== '') { $sets[] = 'title = ?'; $params[] = $new_title; }
        if ($new_content !== '') { $sets[] = 'description = ?'; $params[] = $new_content; }
        $params[] = $article_id;
        $pdo->prepare('UPDATE news SET ' . implode(', ', $sets) . " WHERE id = ? AND status = 'bot_draft'")->execute($params);
        $_SESSION['flash'] = 'Draft approved and published.';
    } elseif ($action === 'reject' && $article_id > 0) {
        $pdo->prepare("UPDATE news SET status = 'rejected' WHERE id = ? AND status = 'bot_draft'")->execute([$article_id]);
        $_SESSION['flash'] = 'Draft rejected.';
    }
    header('Location: drafts.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$total = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'bot_draft'")->fetchColumn();

$drafts_stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.description, n.bot_source_name, n.created_at
     FROM news n
     WHERE n.status = 'bot_draft'
     ORDER BY n.created_at DESC
     LIMIT ? OFFSET ?"
);
$drafts_stmt->execute([$per_page, $offset]);
$drafts = $drafts_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pages = (int)ceil($total / $per_page);
?>
<!DOCTYPE html>
<html lang="hi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bot Drafts — NewsXpressLive Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container-fluid py-4">
  <h2 class="mb-1">🤖 AI Reporter Bot — Draft Queue</h2>
  <p class="text-muted mb-4">Total pending: <strong><?= $total ?></strong></p>

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (empty($drafts)): ?>
    <div class="alert alert-info">No bot drafts pending review.</div>
  <?php else: ?>
    <?php foreach ($drafts as $d): ?>
    <div class="card mb-4 border-warning">
      <div class="card-header d-flex justify-content-between align-items-center bg-warning-subtle">
        <span class="badge bg-warning text-dark">Bot Draft</span>
        <small class="text-muted"><?= htmlspecialchars($d['bot_source_name'] ?? 'Unknown source') ?>
          &bull; <?= htmlspecialchars(date('d M Y H:i', strtotime($d['created_at']))) ?></small>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="article_id" value="<?= (int)$d['id'] ?>">

          <div class="mb-2">
            <label class="form-label fw-bold">Headline</label>
            <input type="text" name="title" class="form-control"
              value="<?= htmlspecialchars($d['title']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Content</label>
            <textarea name="content" class="form-control" rows="4"><?= htmlspecialchars($d['description'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-success">
              ✅ Approve &amp; Publish
            </button>
            <button type="submit" name="action" value="reject" class="btn btn-outline-danger"
              onclick="return confirm('Reject this draft?')">
              ❌ Reject
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav>
      <ul class="pagination">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
