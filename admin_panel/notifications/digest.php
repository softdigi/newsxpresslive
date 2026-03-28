<?php
// ============================================================
// admin_panel/notifications/digest.php
//
// Admin UI: View AI digest history + manually trigger digests.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/header.php';

requireRole(['super_admin', 'admin']);

$sent  = $_GET['sent']  ?? '';
$error = $_GET['error'] ?? '';

// Fetch recent digests (last 50)
$digests = $pdo->query("
    SELECT id, digest_type, location_id, digest_title, digest_text,
           news_ids, sent_at, fcm_response
    FROM news_digests
    ORDER BY sent_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'local_district' => '📍 District',
    'local_state'    => '🗺️ State',
    'national'       => '🇮🇳 National',
    'international'  => '🌍 International',
];
?>

<div class="content-wrapper">
<section class="content-header">
  <h1>🤖 AI News Digest</h1>
  <small>Geo-targeted AI summaries sent automatically via push notifications</small>
</section>
<section class="content">

<?php if ($sent === 'ok'): ?>
  <div class="alert alert-success">✅ Digest triggered successfully.</div>
<?php elseif ($error === 'fail'): ?>
  <div class="alert alert-danger">❌ Digest trigger failed. Check server logs.</div>
<?php endif; ?>

<!-- Manual Trigger Panel -->
<div class="card mb-4" style="max-width:680px">
  <div class="card-header"><strong>Manual Trigger</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">
      Use these buttons to manually send a digest right now.
      Normally these run automatically via cron jobs.
    </p>
    <form method="POST" action="<?= ADMIN_URL ?>/actions/send_digest.php" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="digest_type" value="local">
      <button type="submit" class="btn btn-warning" style="margin-right:8px">
        📍 Send Local Digest (District + State)
      </button>
    </form>
    <form method="POST" action="<?= ADMIN_URL ?>/actions/send_digest.php" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="digest_type" value="national">
      <button type="submit" class="btn btn-primary" style="margin-right:8px">
        🇮🇳 Send National Digest
      </button>
    </form>
    <form method="POST" action="<?= ADMIN_URL ?>/actions/send_digest.php" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="digest_type" value="international">
      <button type="submit" class="btn btn-info">
        🌍 Send International Digest
      </button>
    </form>
  </div>
</div>

<!-- Cron Setup Info -->
<div class="card mb-4" style="max-width:680px; background:#f8f9fa">
  <div class="card-header"><strong>Cron Configuration</strong></div>
  <div class="card-body">
    <p style="font-size:13px; margin-bottom:6px">Add these lines to your server crontab (<code>crontab -e</code>):</p>
    <pre style="background:#222; color:#aef; padding:10px; border-radius:4px; font-size:12px">
# Local digest (district + state) — every evening at 8 PM
0 20 * * * php <?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html') ?>/cron/local_digest.php >> /var/log/local_digest.log 2>&1

# National + International digest — every 20 minutes
*/20 * * * * php <?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html') ?>/cron/national_digest.php >> /var/log/national_digest.log 2>&1</pre>
    <p style="font-size:12px; color:#888">
      ℹ️ Set <code>OPENAI_API_KEY</code> or <code>GEMINI_API_KEY</code> environment variable,
      or save keys in <a href="<?= ADMIN_URL ?>/settings/api_keys.php">Settings → API Keys</a>.
    </p>
  </div>
</div>

<!-- Recent Digests Table -->
<div class="card">
  <div class="card-header"><strong>Recent Digests (Last 50)</strong></div>
  <div class="card-body" style="padding:0">
    <?php if (empty($digests)): ?>
      <p style="padding:16px; color:#888">No digests sent yet. Set up cron jobs or use manual trigger above.</p>
    <?php else: ?>
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#f4f4f4; text-align:left">
          <th style="padding:10px">Type</th>
          <th style="padding:10px">Title</th>
          <th style="padding:10px">Summary (preview)</th>
          <th style="padding:10px">Articles</th>
          <th style="padding:10px">Sent At</th>
          <th style="padding:10px">FCM</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($digests as $d): ?>
        <tr style="border-top:1px solid #eee">
          <td style="padding:10px">
            <?= htmlspecialchars($typeLabels[$d['digest_type']] ?? $d['digest_type']) ?>
            <?php if ($d['location_id']): ?>
              <br><small style="color:#888">ID: <?= (int)$d['location_id'] ?></small>
            <?php endif; ?>
          </td>
          <td style="padding:10px"><?= htmlspecialchars($d['digest_title'] ?? '') ?></td>
          <td style="padding:10px; max-width:280px">
            <?= htmlspecialchars(mb_substr($d['digest_text'] ?? '', 0, 120)) ?>…
          </td>
          <td style="padding:10px; text-align:center">
            <?= $d['news_ids'] ? substr_count($d['news_ids'], ',') + 1 : 0 ?>
          </td>
          <td style="padding:10px; white-space:nowrap"><?= htmlspecialchars($d['sent_at']) ?></td>
          <td style="padding:10px">
            <?php
              $fcmOk = !empty($d['fcm_response']) && $d['fcm_response'] !== 'error';
              echo $fcmOk ? '<span style="color:green">✔ OK</span>' : '<span style="color:red">✘</span>';
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
