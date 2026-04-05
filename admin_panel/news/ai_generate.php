<?php
// ============================================================
// admin_panel/news/ai_generate.php
//
// AI News Generator — Admin Panel Page
//
// Features:
//   - Enter a topic / keyword
//   - AI generates: title, content, meta title, meta description
//   - Auto image suggestions (Pexels search links)
//   - Preview generated article
//   - Save as pending news (one click)
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
<section class="content-header">
  <h1>🤖 AI News Generator</h1>
  <p style="color:#666;margin-top:4px">Generate a full news article from a topic or keyword using OpenAI.</p>
</section>
<section class="content">
<div class="container-fluid">

<!-- KEY MISSING WARNING -->
<?php
require_once __DIR__ . '/../../helpers/ai_news_generator.php';
$hasKey = (bool)(getenv('OPENAI_API_KEY') || getenv('GEMINI_API_KEY'));
if (!$hasKey) {
    // Check DB
    global $pdo;
    try {
        $ks = $pdo->query("SELECT setting_key FROM settings WHERE setting_key IN ('openai_api_key','gemini_api_key') AND setting_value != '' LIMIT 1")->fetchColumn();
        $hasKey = (bool)$ks;
    } catch (Throwable $e) {}
}
if (!$hasKey): ?>
<div class="alert alert-warning" style="max-width:850px">
    ⚠️ <strong>No AI API key configured.</strong>
    Please add your <strong>OpenAI</strong> or <strong>Gemini</strong> API key in
    <a href="<?= ADMIN_URL ?>/settings/api_keys.php">Settings → API Keys</a>
    before using this feature.
</div>
<?php endif; ?>

<!-- GENERATOR CARD -->
<div class="card" style="max-width:1000px">
<div class="card-body">

  <!-- INPUT FORM -->
  <div id="ai-input-section">
    <div class="form-group">
      <label for="ai-topic" style="font-weight:600;font-size:15px">
        📌 Enter a news topic or keyword
      </label>
      <div style="display:flex;gap:10px;align-items:flex-start">
        <input type="text" id="ai-topic" class="form-control"
               placeholder="e.g. India Budget 2025, Climate Summit, Tech startup funding..."
               style="font-size:15px;max-width:600px" maxlength="300">
        <select id="ai-lang" class="form-control" style="max-width:140px">
          <option value="en">English</option>
          <option value="hi">Hindi</option>
        </select>
        <button id="ai-generate-btn" class="btn btn-primary" style="white-space:nowrap">
          ✨ Generate Article
        </button>
      </div>
      <small class="form-text text-muted">
        Be specific for best results. Example: "RBI interest rate hike effect on home loans"
      </small>
    </div>

    <!-- QUICK TOPIC CHIPS -->
    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
      <?php
      $quickTopics = [
          'Politics & Government',
          'Economy & Finance',
          'Technology',
          'Health & Medicine',
          'Sports',
          'Environment',
          'Science & Space',
          'Entertainment',
      ];
      foreach ($quickTopics as $qt): ?>
        <span class="ai-chip"
              style="cursor:pointer;background:#f0f4ff;border:1px solid #c5d0f0;
                     padding:4px 12px;border-radius:20px;font-size:13px;color:#3a5bd0"
              onclick="document.getElementById('ai-topic').value=this.textContent.trim()">
          <?= htmlspecialchars($qt) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- LOADING SPINNER -->
  <div id="ai-loading" style="display:none;text-align:center;padding:40px 0">
    <div style="font-size:32px;margin-bottom:12px">⏳</div>
    <p style="font-size:16px;color:#555">AI is writing your article… this may take 10–20 seconds.</p>
    <div class="progress" style="max-width:400px;margin:12px auto">
      <div class="progress-bar progress-bar-striped progress-bar-animated"
           style="width:100%;background:#4a6cf7"></div>
    </div>
  </div>

  <!-- ERROR DISPLAY -->
  <div id="ai-error" style="display:none" class="alert alert-danger mt-3"></div>

  <!-- GENERATED RESULT -->
  <div id="ai-result" style="display:none;margin-top:24px">

    <hr>
    <h4 style="color:#4a6cf7;margin-bottom:16px">📄 Generated Article Preview</h4>

    <!-- TITLE -->
    <div class="form-group">
      <label><strong>Headline / Title</strong>
        <span class="badge badge-info" style="font-size:11px;margin-left:6px">editable</span>
      </label>
      <input type="text" id="result-title" class="form-control"
             style="font-size:16px;font-weight:600" maxlength="255">
    </div>

    <!-- META TITLE -->
    <div class="form-group">
      <label><strong>SEO / Meta Title</strong></label>
      <input type="text" id="result-meta-title" class="form-control" maxlength="255">
    </div>

    <!-- META DESCRIPTION -->
    <div class="form-group">
      <label><strong>Meta Description</strong></label>
      <textarea id="result-meta-desc" class="form-control" rows="2" maxlength="500"></textarea>
    </div>

    <!-- CONTENT -->
    <div class="form-group">
      <label><strong>Article Content</strong>
        <span class="badge badge-info" style="font-size:11px;margin-left:6px">editable</span>
      </label>
      <div id="result-content-preview"
           style="border:1px solid #dee2e6;border-radius:4px;padding:16px;
                  background:#fafbff;min-height:200px;line-height:1.7;font-size:14px">
      </div>
      <textarea id="result-content" style="display:none"></textarea>
    </div>

    <!-- IMAGE SUGGESTIONS -->
    <div id="image-suggestion-section" style="margin-bottom:20px">
      <label><strong>🖼️ Image Suggestions</strong>
        <small style="color:#888;font-size:12px;margin-left:6px">
          — Click to search on Pexels (free stock photos)
        </small>
      </label>
      <div id="image-suggestions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px"></div>
    </div>

    <!-- TAGS -->
    <div id="tags-section" style="margin-bottom:20px">
      <label><strong>🏷️ Suggested Tags</strong></label>
      <div id="result-tags" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px"></div>
    </div>

    <!-- ACTIONS -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">
      <button id="ai-save-btn" class="btn btn-success btn-lg">
        💾 Save as Pending News
      </button>
      <button id="ai-regenerate-btn" class="btn btn-outline-primary btn-lg">
        🔄 Regenerate
      </button>
      <a id="ai-edit-link" href="#" class="btn btn-outline-secondary btn-lg" style="display:none">
        ✏️ Open in Editor
      </a>
    </div>

    <!-- SAVE SUCCESS -->
    <div id="ai-save-success" style="display:none" class="alert alert-success mt-3">
      ✅ Article saved as <strong>pending</strong>!
      <a id="ai-view-link" href="#" target="_blank">View / Edit →</a>
    </div>

  </div><!-- /ai-result -->

</div><!-- /card-body -->
</div><!-- /card -->

</div><!-- /container-fluid -->
</section>
</div><!-- /content-wrapper -->

<script>
window.SITE_URL  = <?= json_encode(rtrim(getenv('SITE_URL') ?: '', '/')) ?>;
window.ADMIN_URL = <?= json_encode(ADMIN_URL) ?>;
</script>
<script>
(function () {
    const CSRF  = <?= json_encode(csrf_token()) ?>;
    const API   = (window.SITE_URL || '') + '/api/v1/ai_generate.php';

    const topicEl  = document.getElementById('ai-topic');
    const langEl   = document.getElementById('ai-lang');
    const genBtn   = document.getElementById('ai-generate-btn');
    const regenBtn = document.getElementById('ai-regenerate-btn');
    const saveBtn  = document.getElementById('ai-save-btn');

    let currentTopic = '';

    // ---- Generate ---------------------------------------------------
    function generate() {
        const topic = topicEl.value.trim();
        if (!topic) {
            topicEl.focus();
            topicEl.style.borderColor = '#e74c3c';
            setTimeout(() => topicEl.style.borderColor = '', 2000);
            return;
        }
        currentTopic = topic;

        document.getElementById('ai-loading').style.display = 'block';
        document.getElementById('ai-result').style.display  = 'none';
        document.getElementById('ai-error').style.display   = 'none';
        document.getElementById('ai-save-success').style.display = 'none';
        genBtn.disabled = true;

        const fd = new FormData();
        fd.append('action',     'generate');
        fd.append('csrf_token', CSRF);
        fd.append('topic',      topic);
        fd.append('lang',       langEl.value);

        fetch(API, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                document.getElementById('ai-loading').style.display = 'none';
                genBtn.disabled = false;

                if (data.error) {
                    showError(data.error);
                    return;
                }
                renderResult(data);
            })
            .catch(err => {
                document.getElementById('ai-loading').style.display = 'none';
                genBtn.disabled = false;
                showError('Network error: ' + err.message);
            });
    }

    // ---- Render result ----------------------------------------------
    function renderResult(data) {
        document.getElementById('result-title').value     = data.title || '';
        document.getElementById('result-meta-title').value = data.meta_title || '';
        document.getElementById('result-meta-desc').value  = data.meta_description || '';
        document.getElementById('result-content').value    = data.content || '';
        document.getElementById('result-content-preview').innerHTML = data.content || '';

        // Image suggestions
        const imgBox = document.getElementById('image-suggestions');
        imgBox.innerHTML = '';
        (data.image_suggestions || []).forEach(q => {
            const a = document.createElement('a');
            a.href   = 'https://www.pexels.com/search/' + encodeURIComponent(q) + '/';
            a.target = '_blank';
            a.rel    = 'noopener noreferrer';
            a.style.cssText = 'display:inline-block;background:#f0f4ff;border:1px solid #c5d0f0;'
                            + 'padding:6px 14px;border-radius:20px;font-size:13px;color:#3a5bd0;'
                            + 'text-decoration:none';
            a.textContent = '🔍 ' + q;
            imgBox.appendChild(a);
        });

        // Tags
        const tagsBox = document.getElementById('result-tags');
        tagsBox.innerHTML = '';
        (data.tags || []).forEach(tag => {
            const span = document.createElement('span');
            span.style.cssText = 'background:#e8f5e9;border:1px solid #a5d6a7;'
                               + 'padding:4px 12px;border-radius:20px;font-size:12px;color:#2e7d32';
            span.textContent = '#' + tag;
            tagsBox.appendChild(span);
        });

        document.getElementById('ai-result').style.display = 'block';
        document.getElementById('ai-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ---- Save -------------------------------------------------------
    function saveArticle() {
        const title    = document.getElementById('result-title').value.trim();
        const content  = document.getElementById('result-content').value.trim()
                      || document.getElementById('result-content-preview').innerHTML.trim();
        const metaT    = document.getElementById('result-meta-title').value.trim();
        const metaD    = document.getElementById('result-meta-desc').value.trim();

        if (!title || !content) {
            showError('Title and content are required before saving.');
            return;
        }

        saveBtn.disabled  = true;
        saveBtn.textContent = '⏳ Saving…';

        const fd = new FormData();
        fd.append('action',           'save');
        fd.append('csrf_token',       CSRF);
        fd.append('title',            title);
        fd.append('content',          content);
        fd.append('meta_title',       metaT);
        fd.append('meta_description', metaD);
        fd.append('topic',            currentTopic);

        fetch(API, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                saveBtn.disabled  = false;
                saveBtn.textContent = '💾 Save as Pending News';

                if (data.error) {
                    showError(data.error);
                    return;
                }

                const successBox = document.getElementById('ai-save-success');
                successBox.style.display = 'block';

                const viewLink = document.getElementById('ai-view-link');
                viewLink.href = (window.ADMIN_URL || '/admin_panel') + '/news/view.php?id=' + data.news_id;

                const editLink = document.getElementById('ai-edit-link');
                editLink.href = (window.ADMIN_URL || '/admin_panel') + '/news/edit.php?id=' + data.news_id;
                editLink.style.display = 'inline-block';

                successBox.scrollIntoView({ behavior: 'smooth' });
            })
            .catch(err => {
                saveBtn.disabled  = false;
                saveBtn.textContent = '💾 Save as Pending News';
                showError('Network error: ' + err.message);
            });
    }

    // ---- Helpers ----------------------------------------------------
    function showError(msg) {
        const el = document.getElementById('ai-error');
        el.textContent = '❌ ' + msg;
        el.style.display = 'block';
        el.scrollIntoView({ behavior: 'smooth' });
    }

    // ---- Events -----------------------------------------------------
    genBtn.addEventListener('click', generate);
    regenBtn.addEventListener('click', generate);
    saveBtn.addEventListener('click', saveArticle);

    topicEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            generate();
        }
    });

    // Keep hidden textarea in sync when user edits the preview div
    document.getElementById('result-content-preview').addEventListener('input', function () {
        document.getElementById('result-content').value = this.innerHTML;
    });
    document.getElementById('result-content-preview').contentEditable = 'true';

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
