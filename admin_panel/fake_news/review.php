<?php
/**
 * admin_panel/fake_news/review.php
 * Detailed review page for a single flagged article.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';

requireRole(['admin', 'super_admin', 'editor']);

$qid = (int)($_GET['id'] ?? 0);
if ($qid <= 0) {
    header('Location: index.php');
    exit;
}

/* ── Fetch queue row + article ────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        'SELECT q.id AS qid, q.news_id, q.fake_score, q.fake_verdict,
                q.fake_flags, q.reviewed, q.review_note, q.created_at AS queued_at,
                n.title, n.content, n.status AS news_status, n.slug,
                n.created_at AS published_at,
                r.name AS reporter_name, r.photo AS reporter_photo,
                a.name AS agency_name
         FROM fake_news_queue q
         JOIN news n ON n.id = q.news_id
         LEFT JOIN reporters r ON r.id = n.reporter_id
         LEFT JOIN agencies  a ON a.id = n.agency_id
         WHERE q.id = :qid
         LIMIT 1'
    );
    $stmt->execute([':qid' => $qid]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('fake_news review fetch: ' . $e->getMessage());
    $row = null;
}

if (!$row) {
    echo '<p style="padding:32px">Queue entry not found. <a href="index.php">Back</a></p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$flags       = json_decode($row['fake_flags'] ?? '[]', true) ?: [];
$verdictColor = match ($row['fake_verdict']) {
    'likely_fake' => '#c0392b',
    'suspicious'  => '#e67e22',
    default       => '#27ae60',
};
$plainBody   = strip_tags($row['content']);
$wordCount   = str_word_count($plainBody);
?>

<main class="dashboard">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <a href="index.php" style="color:#555;text-decoration:none">← Back to Queue</a>
    <h2 style="margin:0">🔍 Fake News Review</h2>
    <?php if ($row['reviewed']): ?>
      <span style="background:#27ae60;color:#fff;padding:3px 10px;border-radius:12px;font-size:13px">✔ Reviewed</span>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

    <!-- LEFT: Article content -->
    <div>
      <div class="card" style="padding:20px">
        <h3 style="margin-top:0"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p style="font-size:13px;color:#888;margin-bottom:12px">
          📰 <?= htmlspecialchars($row['reporter_name'] ?? 'Unknown reporter', ENT_QUOTES, 'UTF-8') ?>
          <?= $row['agency_name'] ? ' · ' . htmlspecialchars($row['agency_name'], ENT_QUOTES, 'UTF-8') : '' ?>
          &nbsp;|&nbsp; <?= htmlspecialchars(date('d M Y H:i', strtotime($row['published_at'])), ENT_QUOTES, 'UTF-8') ?>
          &nbsp;|&nbsp; Status: <strong><?= htmlspecialchars($row['news_status'], ENT_QUOTES, 'UTF-8') ?></strong>
          &nbsp;|&nbsp; Words: <?= $wordCount ?>
        </p>
        <div style="background:#fafafa;border:1px solid #eee;border-radius:6px;padding:14px;max-height:400px;overflow-y:auto;font-size:14px;line-height:1.7;white-space:pre-wrap">
<?= htmlspecialchars(mb_substr($plainBody, 0, 3000), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($plainBody) > 3000 ? '…' : '' ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: Analysis + actions -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- Score card -->
      <div class="card" style="padding:20px;text-align:center">
        <div style="font-size:48px;font-weight:700;color:<?= $verdictColor ?>">
          <?= number_format($row['fake_score'], 1) ?>
        </div>
        <div style="font-size:13px;color:#888;margin-bottom:8px">Risk Score (0–100)</div>
        <span style="background:<?= $verdictColor ?>;color:#fff;padding:4px 14px;border-radius:16px;font-size:14px;font-weight:600">
          <?= strtoupper(str_replace('_', ' ', $row['fake_verdict'])) ?>
        </span>
        <div style="margin-top:12px">
          <div style="background:#eee;border-radius:8px;height:10px;overflow:hidden">
            <div style="width:<?= min(100, $row['fake_score']) ?>%;height:100%;background:<?= $verdictColor ?>;border-radius:8px"></div>
          </div>
        </div>
      </div>

      <!-- Triggered flags -->
      <div class="card" style="padding:16px">
        <h4 style="margin:0 0 10px">🚩 Triggered Signals (<?= count($flags) ?>)</h4>
        <?php if (empty($flags)): ?>
          <p style="color:#888;font-size:13px">No specific signals triggered.</p>
        <?php else: ?>
          <ul style="margin:0;padding-left:18px;font-size:13px;color:#444">
            <?php foreach ($flags as $flag): ?>
              <li style="margin-bottom:4px"><?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- Re-scan button -->
      <form method="post" action="../actions/fake_news_rescan.php">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="qid"        value="<?= $qid ?>">
        <input type="hidden" name="news_id"    value="<?= (int)$row['news_id'] ?>">
        <button type="submit" style="width:100%;padding:8px;background:#3498db;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px">
          🔄 Re-scan Article
        </button>
      </form>

      <?php if (!$row['reviewed']): ?>
      <!-- Review actions -->
      <div class="card" style="padding:16px">
        <h4 style="margin:0 0 10px">Admin Decision</h4>

        <!-- Clear flag (article stays published) -->
        <form method="post" action="../actions/fake_news_clear.php" style="margin-bottom:10px">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="qid"        value="<?= $qid ?>">
          <input type="hidden" name="news_id"    value="<?= (int)$row['news_id'] ?>">
          <textarea name="review_note" placeholder="Optional note…"
            style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:13px;margin-bottom:6px;resize:vertical"
            rows="2"></textarea>
          <button type="submit"
            style="width:100%;padding:8px;background:#27ae60;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px"
            onclick="return confirm('Mark this article as clean and clear the flag?')">
            ✅ Clear — Article is Legitimate
          </button>
        </form>

        <!-- Reject article -->
        <form method="post" action="../actions/fake_news_reject.php">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="qid"        value="<?= $qid ?>">
          <input type="hidden" name="news_id"    value="<?= (int)$row['news_id'] ?>">
          <textarea name="review_note" placeholder="Rejection reason…"
            style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:13px;margin-bottom:6px;resize:vertical"
            rows="2"></textarea>
          <button type="submit"
            style="width:100%;padding:8px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px"
            onclick="return confirm('Reject and unpublish this article?')">
            ❌ Reject — Fake / Misleading News
          </button>
        </form>
      </div>
      <?php else: ?>
      <div class="card" style="padding:16px;background:#f9f9f9">
        <h4 style="margin:0 0 6px;color:#555">Review Note</h4>
        <p style="margin:0;font-size:13px;color:#666">
          <?= htmlspecialchars($row['review_note'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
      <?php endif; ?>

    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
