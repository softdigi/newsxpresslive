<?php
// ============================================================
// admin_panel/analytics/ab_test_create.php
// Create a new A/B headline test for an existing news article.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

requireRole(['super_admin', 'admin', 'editor']);

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $newsId  = max(0, (int)($_POST['news_id']  ?? 0));
    $titleA  = trim($_POST['title_a']  ?? '');
    $titleB  = trim($_POST['title_b']  ?? '');

    if ($newsId <= 0 || $titleA === '' || $titleB === '') {
        $error = 'All fields are required.';
    } elseif (mb_strlen($titleA) > 500 || mb_strlen($titleB) > 500) {
        $error = 'Titles must be 500 characters or fewer.';
    } else {
        // Verify article exists
        $check = $pdo->prepare("SELECT id FROM news WHERE id = ? LIMIT 1");
        $check->execute([$newsId]);
        if (!$check->fetchColumn()) {
            $error = 'Article not found.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO ab_tests (news_id, title_a, title_b, status, created_by)
                 VALUES (?, ?, ?, 'running', ?)"
            );
            $stmt->execute([
                $newsId,
                mb_substr($titleA, 0, 500),
                mb_substr($titleB, 0, 500),
                $_SESSION['admin']['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            header("Location: ab_test_view.php?id=$newId&created=1");
            exit;
        }
    }
}

// Recent articles for the picker
$articles = $pdo->query(
    "SELECT id, title FROM news WHERE status = 'approved' ORDER BY created_at DESC LIMIT 200"
)->fetchAll();
?>

<div class="content-wrapper">
<section class="content-header">
    <h1>🧪 New A/B Headline Test</h1>
    <small>Run two headline variants side-by-side to see which drives more clicks.</small>
</section>
<section class="content">

<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fdecea;border-left:4px solid #e74c3c;padding:10px 14px;margin-bottom:16px;border-radius:4px;color:#c0392b">
        ⚠️ <?= htmlspecialchars($error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:700px">
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:4px">Article</label>
        <select name="news_id" required
                style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
            <option value="">— select an article —</option>
            <?php foreach ($articles as $a): ?>
                <option value="<?= $a['id'] ?>"
                    <?= ((int)($_POST['news_id'] ?? 0) === $a['id']) ? 'selected' : '' ?>>
                    #<?= $a['id'] ?> – <?= htmlspecialchars(mb_strimwidth($a['title'], 0, 90, '…'), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:4px">
            Variant A — Headline <small style="color:#888">(current / control)</small>
        </label>
        <input type="text" name="title_a" maxlength="500" required
               value="<?= htmlspecialchars($_POST['title_a'] ?? '', ENT_QUOTES) ?>"
               placeholder="e.g. Scientists Discover New Planet"
               style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
        <small style="color:#888">The original / control headline.</small>
    </div>

    <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:4px">
            Variant B — Headline <small style="color:#888">(challenger)</small>
        </label>
        <input type="text" name="title_b" maxlength="500" required
               value="<?= htmlspecialchars($_POST['title_b'] ?? '', ENT_QUOTES) ?>"
               placeholder="e.g. New Planet Found: What It Means for Us"
               style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
        <small style="color:#888">The challenger headline you want to test.</small>
    </div>

    <button type="submit"
            style="padding:9px 22px;background:#27ae60;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px">
        🚀 Start Test
    </button>
    <a href="ab_tests.php"
       style="margin-left:12px;color:#888;font-size:14px;text-decoration:none">Cancel</a>
</form>
</div>

<div class="card" style="max-width:700px;margin-top:16px;background:#fffbf0;border-left:4px solid #f39c12">
<h4 style="margin:0 0 8px;color:#f39c12">📋 How it works</h4>
<ol style="margin:0;padding-left:20px;line-height:1.8;font-size:14px;color:#555">
    <li>Choose an approved article and enter two headline variants.</li>
    <li>Your frontend randomly assigns Variant A or B to each visitor (50/50 split).</li>
    <li>Each time a headline is shown, call <code>POST /api/v1/ab_event.php</code> with <code>event=impression</code>.</li>
    <li>When the headline is clicked, call the same endpoint with <code>event=click</code>.</li>
    <li>Monitor CTR (click-through rate) on the <em>View</em> page. Conclude the test when you have a winner.</li>
</ol>
</div>

</section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
