<?php
/**
 * admin_panel/fake_news/index.php
 * Fake News Review Queue — lists flagged articles awaiting admin review.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

requireRole(['admin', 'super_admin', 'editor']);

/* ── Pagination + filter ──────────────────────────────────────────── */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$filterVerdict = in_array($_GET['verdict'] ?? '', ['suspicious', 'likely_fake', ''])
    ? ($_GET['verdict'] ?? '')
    : '';

$filterReviewed = match ($_GET['reviewed'] ?? 'pending') {
    'all'      => null,    // all rows
    'done'     => 1,
    default    => 0,       // pending (default)
};

/* ── Query ────────────────────────────────────────────────────────── */
$where   = ['1=1'];
$params  = [];

if ($filterVerdict !== '') {
    $where[]  = 'q.fake_verdict = :vd';
    $params[':vd'] = $filterVerdict;
}
if ($filterReviewed !== null) {
    $where[]  = 'q.reviewed = :rv';
    $params[':rv'] = $filterReviewed;
}

$whereStr = implode(' AND ', $where);

try {
    $total = (int)$pdo->prepare(
        "SELECT COUNT(*) FROM fake_news_queue q WHERE $whereStr"
    )->execute($params) ? $pdo->prepare(
        "SELECT COUNT(*) FROM fake_news_queue q WHERE $whereStr"
    )->execute($params) : 0;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fake_news_queue q WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare(
        "SELECT q.id AS qid, q.news_id, q.fake_score, q.fake_verdict,
                q.fake_flags, q.reviewed, q.created_at AS queued_at,
                n.title, n.status AS news_status, n.slug,
                n.created_at AS published_at,
                r.name AS reporter_name
         FROM fake_news_queue q
         JOIN news n ON n.id = q.news_id
         LEFT JOIN reporters r ON r.id = n.reporter_id
         WHERE $whereStr
         ORDER BY q.reviewed ASC, q.fake_score DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll();
} catch (PDOException $e) {
    error_log('fake_news queue fetch: ' . $e->getMessage());
    $rows  = [];
    $total = 0;
}

$pages = (int)ceil($total / $perPage);
?>

<main class="dashboard">
  <h2>🔍 Fake News Detection Queue</h2>

  <!-- Filter bar -->
  <form method="get" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;">
    <select name="verdict">
      <option value="">All Verdicts</option>
      <option value="suspicious"   <?= $filterVerdict === 'suspicious'   ? 'selected' : '' ?>>Suspicious</option>
      <option value="likely_fake"  <?= $filterVerdict === 'likely_fake'  ? 'selected' : '' ?>>Likely Fake</option>
    </select>
    <select name="reviewed">
      <option value="pending" <?= ($filterReviewed === 0)    ? 'selected' : '' ?>>Pending Review</option>
      <option value="done"    <?= ($filterReviewed === 1)    ? 'selected' : '' ?>>Reviewed</option>
      <option value="all"     <?= ($filterReviewed === null) ? 'selected' : '' ?>>All</option>
    </select>
    <button type="submit" class="btn-primary">Filter</button>
    <a href="index.php" style="line-height:2.2">Reset</a>
  </form>

  <p style="color:#666">
    Showing <?= count($rows) ?> of <?= $total ?> flagged article(s)
  </p>

  <?php if (empty($rows)): ?>
    <div class="card" style="padding:32px;text-align:center;color:#555">
      ✅ No flagged articles matching this filter.
    </div>
  <?php else: ?>
  <div class="card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="background:#f5f5f5">
          <th style="padding:8px 12px;text-align:left">Article</th>
          <th style="padding:8px 12px">Score</th>
          <th style="padding:8px 12px">Verdict</th>
          <th style="padding:8px 12px">Reporter</th>
          <th style="padding:8px 12px">Status</th>
          <th style="padding:8px 12px">Queued</th>
          <th style="padding:8px 12px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
          $verdictColor = match ($row['fake_verdict']) {
              'likely_fake' => '#c0392b',
              'suspicious'  => '#e67e22',
              default       => '#27ae60',
          };
          $flags = json_decode($row['fake_flags'] ?? '[]', true) ?: [];
        ?>
        <tr style="border-bottom:1px solid #eee;<?= $row['reviewed'] ? 'opacity:.65' : '' ?>">
          <td style="padding:8px 12px;max-width:280px">
            <a href="review.php?id=<?= (int)$row['qid'] ?>" style="font-weight:600;color:#2c3e50">
              <?= htmlspecialchars(mb_substr($row['title'], 0, 80), ENT_QUOTES, 'UTF-8') ?>
              <?= mb_strlen($row['title']) > 80 ? '…' : '' ?>
            </a>
            <?php if ($flags): ?>
            <div style="margin-top:4px;font-size:11px;color:#888">
              <?= htmlspecialchars(implode(' · ', array_slice($flags, 0, 3)), ENT_QUOTES, 'UTF-8') ?>
              <?= count($flags) > 3 ? ' +' . (count($flags) - 3) . ' more' : '' ?>
            </div>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:<?= $verdictColor ?>">
            <?= number_format($row['fake_score'], 1) ?>
          </td>
          <td style="padding:8px 12px;text-align:center">
            <span style="background:<?= $verdictColor ?>;color:#fff;padding:2px 8px;border-radius:12px;font-size:12px">
              <?= htmlspecialchars($row['fake_verdict'], ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>
          <td style="padding:8px 12px;text-align:center">
            <?= htmlspecialchars($row['reporter_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:8px 12px;text-align:center">
            <span style="font-size:12px;color:#555"><?= htmlspecialchars($row['news_status'], ENT_QUOTES, 'UTF-8') ?></span>
          </td>
          <td style="padding:8px 12px;text-align:center;white-space:nowrap;font-size:12px;color:#888">
            <?= htmlspecialchars(date('d M Y', strtotime($row['queued_at'])), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="padding:8px 12px;text-align:center;white-space:nowrap">
            <a href="review.php?id=<?= (int)$row['qid'] ?>" class="btn-primary" style="font-size:12px;padding:4px 10px">
              Review
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div style="margin-top:16px;display:flex;gap:6px">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?page=<?= $p ?>&verdict=<?= urlencode($filterVerdict) ?>&reviewed=<?= urlencode($_GET['reviewed'] ?? 'pending') ?>"
         style="padding:4px 10px;border-radius:4px;background:<?= $p === $page ? '#2c3e50' : '#eee' ?>;color:<?= $p === $page ? '#fff' : '#333' ?>;text-decoration:none">
        <?= $p ?>
      </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
