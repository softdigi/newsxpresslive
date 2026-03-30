<?php
/**
 * News Detail Page
 * NewsXpressLive – Fetch article by slug, show full content,
 * reporter info (via JOIN), and related news (same category).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Validate slug param
$slug = getParam('slug');
if ($slug === '') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

/* ── Fetch the article ──────────────────────────────────────────────── */
$stmt = $pdo->prepare(
    'SELECT n.id, n.title, n.slug, n.content, n.featured_image,
            n.created_at, n.updated_at, n.is_breaking,
            n.reporter_id, n.agency_id, n.category_id,
            n.views,
            c.name  AS category_name, c.slug AS category_slug,
            r.name  AS reporter_name, r.photo AS reporter_photo,
            r.bio   AS reporter_bio,
            a.name  AS agency_name,  a.logo  AS agency_logo
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     LEFT JOIN reporters  r ON r.id = n.reporter_id
     LEFT JOIN agencies   a ON a.id = n.agency_id
     WHERE n.slug = :slug AND n.status = :status
     LIMIT 1'
);
$stmt->execute([':slug' => $slug, ':status' => 'published']);
$news = $stmt->fetch();

if (!$news) {
    // True 404 — tell search engines not to index this page
    http_response_code(404);
    $seoMeta = ['title' => 'Article Not Found', 'robots' => 'noindex,nofollow'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">The article you are looking for does not exist or has been removed.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ── Increment view count ───────────────────────────────────────────── */
try {
    $pdo->prepare('UPDATE news SET views = COALESCE(views, 0) + 1 WHERE id = ?')->execute([$news['id']]);
} catch (PDOException $e) {
    // views column might not exist - try to add it
    if (strpos($e->getMessage(), 'views') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $pdo->exec('ALTER TABLE news ADD COLUMN views INT DEFAULT 0');
            $pdo->prepare('UPDATE news SET views = 1 WHERE id = ?')->execute([$news['id']]);
        } catch (PDOException $e2) {
            // Silently ignore
        }
    }
}

/* ── Get article tags ───────────────────────────────────────────────── */
$articleTags = [];
try {
    $tagsStmt = $pdo->prepare(
        'SELECT t.name, t.slug FROM tags t 
         INNER JOIN news_tags nt ON nt.tag_id = t.id 
         WHERE nt.news_id = :news_id'
    );
    $tagsStmt->execute([':news_id' => $news['id']]);
    $articleTags = $tagsStmt->fetchAll();
} catch (PDOException $e) {
    // Tables might not exist
}

/* ── Related news (same category, exclude current) ─────────────────── */
$relatedNews = [];
if (!empty($news['category_id'])) {
    $relStmt = $pdo->prepare(
        'SELECT n.title, n.slug, n.featured_image, n.created_at
         FROM news n
         WHERE n.status = :status
           AND n.category_id = :cat_id
           AND n.id != :id
         ORDER BY n.created_at DESC
         LIMIT 4'
    );
    $relStmt->execute([
        ':status' => 'published',
        ':cat_id' => $news['category_id'],
        ':id'     => $news['id'],
    ]);
    $relatedNews = $relStmt->fetchAll();
}

/* ── Next article (same category, older or any order) ───────────────── */
$nextArticle = null;
if (!empty($news['category_id'])) {
    $nextStmt = $pdo->prepare(
        'SELECT title, slug, featured_image FROM news
         WHERE status = :status AND category_id = :cat_id AND id != :id
         ORDER BY created_at DESC LIMIT 1'
    );
    $nextStmt->execute([
        ':status' => 'published',
        ':cat_id' => $news['category_id'],
        ':id'     => $news['id'],
    ]);
    $nextArticle = $nextStmt->fetch() ?: null;
}

/* ── Extract Key Points ─────────────────────────────────────────────── */
$keyPoints = extractKeyPoints($news['content'], 3);

/* ── SEO meta + structured data ─────────────────────────────────────── */
$seoMeta = [
    'title'        => $news['title'],
    'description'  => excerpt($news['content'], 160),
    'image'        => newsImage($news['featured_image']),
    'url'          => newsUrl($news['slug']),
    'type'         => 'article',
    'keywords'     => !empty($news['category_name']) ? $news['category_name'] : '',
    'author'       => !empty($news['reporter_name']) ? $news['reporter_name'] : '',
    'published_at' => date('c', strtotime($news['created_at'])),
    // Prefetch the next article so it loads instantly when the user clicks
    'prefetch_url' => $nextArticle ? newsUrl($nextArticle['slug']) : '',
];

// Sidebar – reuse the latest-news query, capped at 6 for efficiency
$sideStmt = $pdo->prepare(
    'SELECT title, slug, created_at FROM news
     WHERE status = :status ORDER BY created_at DESC LIMIT 6'
);
$sideStmt->execute([':status' => 'published']);
$sideItems = $sideStmt->fetchAll();

/* ── Ad code from settings ──────────────────────────────────────────── */
$adHeader    = getSetting($pdo, 'ad_header');
$adInContent = getSetting($pdo, 'ad_in_content');
$adSidebar   = getSetting($pdo, 'ad_sidebar');

/* ── Approved comments for this article ────────────────────────────── */
$comments = [];
try {
    $commStmt = $pdo->prepare(
        'SELECT id, parent_id, author_name, content, created_at
         FROM comments
         WHERE news_id = ? AND status = ?
         ORDER BY created_at ASC'
    );
    $commStmt->execute([$news['id'], 'approved']);
    $comments = $commStmt->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet — silently continue
}

require_once __DIR__ . '/../includes/header.php';

// ── JSON-LD Structured Data ──────────────────────────────────────────────
renderJsonLd(buildNewsArticleJsonLd($news));

// BreadcrumbList
$breadcrumbItems = [['name' => 'Home', 'url' => SITE_URL . '/']];
if (!empty($news['category_name'])) {
    $breadcrumbItems[] = [
        'name' => $news['category_name'],
        'url'  => categoryUrl($news['category_slug']),
    ];
}
$breadcrumbItems[] = ['name' => $news['title'], 'url' => newsUrl($news['slug'])];
renderJsonLd(buildBreadcrumbJsonLd($breadcrumbItems));
?>

<?php if (!empty($adHeader)): ?>
<div class="ad-slot ad-slot--header" style="text-align:center;padding:0.75rem 0;background:#f8f8f8;">
    <?= $adHeader /* Ad code from admin settings — sanitized at entry point */ ?>
</div>
<?php endif; ?>

<div class="container page-body">
<div class="layout-main">

    <!-- ===== ARTICLE ===== -->
    <article class="article" itemscope itemtype="https://schema.org/NewsArticle" data-article-id="<?= (int)$news['id'] ?>">

        <!-- Breadcrumb -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a>
            <?php if (!empty($news['category_name'])): ?>
            &rsaquo;
            <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endif; ?>
            &rsaquo; <span><?= htmlspecialchars(
                mb_strlen($news['title']) > 60
                    ? mb_substr($news['title'], 0, 60) . '...'
                    : $news['title'],
                ENT_QUOTES, 'UTF-8'
            ) ?></span>
        </nav>

        <!-- Header -->
        <header class="article__header">
            <?php if (!empty($news['category_name'])): ?>
            <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
               class="badge badge--red">
                <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endif; ?>

            <?php if ($news['is_breaking']): ?>
            <span class="badge badge--breaking translatable" data-hi="ब्रेकिंग">Breaking</span>
            <?php endif; ?>

            <h1 class="article__title" itemprop="headline">
                <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <div class="article__meta">
                <?php if (!empty($news['reporter_name'])): ?>
                <span class="article__meta-by">
                    By <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>"
                          itemprop="author">
                        <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </span>
                <?php endif; ?>
                <?php if (!empty($news['agency_name'])): ?>
                <span class="article__meta-agency">
                    via <a href="<?= htmlspecialchars(agencyUrl((int)$news['agency_id']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($news['agency_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </span>
                <?php endif; ?>
                <time class="article__date" itemprop="datePublished"
                      datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= formatDate($news['created_at'], 'F j, Y \a\t g:i A') ?>
                </time>
                <span class="article__read-time">
                    &#9201; <?= readingTime($news['content']) ?> min read
                </span>
                <?php if (!empty($news['views']) && $news['views'] > 0): ?>
                <span class="article__views">
                    👁 <?= formatViews((int)$news['views']) ?> views
                </span>
                <?php endif; ?>
            </div>
        </header>

        <!-- Social Share Bar + Bookmark -->
        <?php
        $shareUrl   = htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8');
        $shareTitle = htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="share-bar" aria-label="Share this article">
            <span class="share-bar__label">Share:</span>
            <a href="https://api.whatsapp.com/send?text=<?= rawurlencode($news['title'] . ' ' . newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--wa" target="_blank" rel="noopener noreferrer"
               aria-label="Share on WhatsApp">WhatsApp</a>
            <a href="https://twitter.com/intent/tweet?url=<?= rawurlencode(newsUrl($news['slug'])) ?>&text=<?= rawurlencode($news['title']) ?>"
               class="share-btn share-btn--tw" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Twitter">Twitter</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode(newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--fb" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Facebook">Facebook</a>
            <a href="https://t.me/share/url?url=<?= rawurlencode(newsUrl($news['slug'])) ?>&text=<?= rawurlencode($news['title']) ?>"
               class="share-btn share-btn--tg" target="_blank" rel="noopener noreferrer"
               aria-label="Share on Telegram">Telegram</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(newsUrl($news['slug'])) ?>"
               class="share-btn share-btn--ln" target="_blank" rel="noopener noreferrer"
               aria-label="Share on LinkedIn">LinkedIn</a>
            <button class="share-btn share-btn--copy" data-url="<?= $shareUrl ?>"
                    aria-label="Copy link to clipboard">Copy Link</button>
            <button class="bookmark-btn" id="bookmarkBtn" 
                    data-id="<?= (int)$news['id'] ?>"
                    data-title="<?= $shareTitle ?>"
                    data-slug="<?= htmlspecialchars($news['slug'], ENT_QUOTES, 'UTF-8') ?>"
                    data-image="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                    data-date="<?= formatDate($news['created_at']) ?>">
                <span class="bookmark-btn__icon">🔖</span> Bookmark
            </button>
        </div>

        <!-- Key Points Summary -->
        <?php if (!empty($keyPoints)): ?>
        <div class="key-points">
            <h3 class="key-points__title">📌 Key Points</h3>
            <ul class="key-points__list">
                <?php foreach ($keyPoints as $point): ?>
                <li class="key-points__item"><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($news['featured_image'])): ?>
        <figure class="article__hero">
            <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                 class="article__hero-img"
                 itemprop="image"
                 loading="lazy">
        </figure>
        <?php endif; ?>

        <!-- Full content (HTML stored in DB).
             SECURITY NOTE: article body is stored as HTML (rich text editor output).
             In production, sanitize HTML at write-time with a library such as HTML Purifier
             before storing it in the database to prevent stored XSS. -->
        <div class="article__body" itemprop="articleBody"
             data-lockable="true"
             data-lock-percent="<?= ARTICLE_LOCK_PERCENT ?>"
             data-play-store="<?= htmlspecialchars(PLAY_STORE_URL, ENT_QUOTES, 'UTF-8') ?>"
             data-app-store="<?= htmlspecialchars(APP_STORE_URL,  ENT_QUOTES, 'UTF-8') ?>">
            <?= $news['content'] ?>
        </div>

        <!-- In-Content Ad -->
        <?php if (!empty($adInContent)): ?>
        <div class="ad-slot ad-slot--in-content" style="text-align:center;margin:1.5rem 0;">
            <?= $adInContent ?>
        </div>
        <?php endif; ?>

        <!-- Tags Section -->
        <?php if (!empty($articleTags)): ?>
        <div class="article-tags">
            <h4 class="article-tags__title">🏷️ Tags</h4>
            <div class="article-tags__list">
                <?php foreach ($articleTags as $tag): ?>
                <a href="<?= htmlspecialchars(tagUrl($tag['slug']), ENT_QUOTES, 'UTF-8') ?>" 
                   class="tag-badge">
                    <?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reporter info card -->
        <?php if (!empty($news['reporter_name'])): ?>
        <div class="reporter-card">
            <?php
            // Use mediaUrl() to validate the filename (prevents path traversal)
            $reporterPhotoUrl = !empty($news['reporter_photo'])
                ? mediaUrl($news['reporter_photo'], 'reporters')
                : '';
            ?>
            <?php if ($reporterPhotoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($reporterPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>"
                 class="reporter-card__photo"
                 loading="lazy">
            <?php endif; ?>
            <div class="reporter-card__info">
                <h3 class="reporter-card__name">
                    <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($news['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </h3>
                <?php if (!empty($news['reporter_bio'])): ?>
                <p class="reporter-card__bio">
                    <?= htmlspecialchars($news['reporter_bio'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(reporterUrl((int)$news['reporter_id']), ENT_QUOTES, 'UTF-8') ?>"
                   class="reporter-card__more">More by this reporter &rarr;</a>
            </div>
        </div>
        <?php endif; ?>

    </article><!-- /.article -->

    <!-- ===== COMMENTS SECTION ===== -->
    <section class="comments-section" id="comments" aria-labelledby="comments-heading">
        <h2 class="section__title" id="comments-heading">
            <span class="section__title-accent">💬 Comments</span>
            <?php if (!empty($comments)): ?>
            <span style="font-size:0.85em;font-weight:400;color:#888">(<?= count($comments) ?>)</span>
            <?php endif; ?>
        </h2>

        <!-- Existing approved comments -->
        <?php if (!empty($comments)): ?>
        <div class="comments-list" id="commentsList">
            <?php foreach ($comments as $comment): ?>
            <?php if ($comment['parent_id'] === null): ?>
            <div class="comment" id="comment-<?= (int)$comment['id'] ?>">
                <div class="comment__avatar" aria-hidden="true">
                    <?= htmlspecialchars(mb_strtoupper(mb_substr($comment['author_name'], 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="comment__body">
                    <div class="comment__meta">
                        <strong class="comment__author"><?= htmlspecialchars($comment['author_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <time class="comment__date" datetime="<?= htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= timeAgo($comment['created_at']) ?>
                        </time>
                    </div>
                    <p class="comment__text"><?= nl2br(htmlspecialchars($comment['content'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <button class="comment__reply-btn" data-id="<?= (int)$comment['id'] ?>"
                            data-name="<?= htmlspecialchars($comment['author_name'], ENT_QUOTES, 'UTF-8') ?>">
                        ↩ Reply
                    </button>
                </div>

                <!-- Nested replies for this comment -->
                <?php foreach ($comments as $reply): ?>
                <?php if ((int)$reply['parent_id'] === (int)$comment['id']): ?>
                <div class="comment comment--reply">
                    <div class="comment__avatar" aria-hidden="true">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr($reply['author_name'], 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="comment__body">
                        <div class="comment__meta">
                            <strong class="comment__author"><?= htmlspecialchars($reply['author_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <time class="comment__date" datetime="<?= htmlspecialchars($reply['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= timeAgo($reply['created_at']) ?>
                            </time>
                        </div>
                        <p class="comment__text"><?= nl2br(htmlspecialchars($reply['content'], ENT_QUOTES, 'UTF-8')) ?></p>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="comments-empty">No comments yet. Be the first to share your thoughts!</p>
        <?php endif; ?>

        <!-- Comment submission form -->
        <div class="comment-form-wrap" id="commentFormWrap">
            <h3 class="comment-form__title">Leave a Comment</h3>
            <div class="comment-form__notice">Your comment will be visible after moderation.</div>
            <div id="commentAlert" style="display:none" role="alert"></div>
            <form class="comment-form" id="commentForm" novalidate>
                <input type="hidden" name="news_id" value="<?= (int)$news['id'] ?>">
                <input type="hidden" name="parent_id" id="commentParentId" value="">

                <div id="replyingTo" style="display:none" class="comment-form__replying">
                    Replying to <strong id="replyingToName"></strong>
                    <button type="button" id="cancelReply" style="margin-left:8px;background:none;border:none;cursor:pointer;color:#e50914">✕ Cancel</button>
                </div>

                <div class="comment-form__row">
                    <div class="comment-form__field">
                        <label for="commentName">Name <span aria-hidden="true">*</span></label>
                        <input type="text" id="commentName" name="author_name"
                               class="comment-form__input" maxlength="80"
                               placeholder="Your name" required autocomplete="name">
                    </div>
                    <div class="comment-form__field">
                        <label for="commentEmail">Email <small>(optional, not shown)</small></label>
                        <input type="email" id="commentEmail" name="author_email"
                               class="comment-form__input" maxlength="255"
                               placeholder="your@email.com" autocomplete="email">
                    </div>
                </div>

                <div class="comment-form__field">
                    <label for="commentContent">Comment <span aria-hidden="true">*</span></label>
                    <textarea id="commentContent" name="content"
                              class="comment-form__input comment-form__textarea"
                              rows="4" maxlength="1000"
                              placeholder="Write your comment here..." required></textarea>
                    <small class="comment-form__char-count">
                        <span id="commentCharCount">0</span>/1000
                    </small>
                </div>

                <button type="submit" class="comment-form__submit" id="commentSubmitBtn">
                    Post Comment
                </button>
            </form>
        </div>
    </section><!-- /.comments-section -->

    <script>
    (function () {
        'use strict';
        var form      = document.getElementById('commentForm');
        var submitBtn = document.getElementById('commentSubmitBtn');
        var alertBox  = document.getElementById('commentAlert');
        var textarea  = document.getElementById('commentContent');
        var charCount = document.getElementById('commentCharCount');
        var parentInput = document.getElementById('commentParentId');
        var replyingTo  = document.getElementById('replyingTo');
        var replyingName= document.getElementById('replyingToName');
        var cancelReply = document.getElementById('cancelReply');

        // Character counter
        if (textarea) {
            textarea.addEventListener('input', function () {
                charCount.textContent = this.value.length;
            });
        }

        // Reply buttons
        document.querySelectorAll('.comment__reply-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                parentInput.value    = this.dataset.id;
                replyingName.textContent = this.dataset.name;
                replyingTo.style.display = '';
                document.getElementById('commentFormWrap').scrollIntoView({behavior:'smooth'});
                document.getElementById('commentName').focus();
            });
        });

        if (cancelReply) {
            cancelReply.addEventListener('click', function () {
                parentInput.value        = '';
                replyingTo.style.display = 'none';
            });
        }

        function showAlert(msg, type) {
            alertBox.textContent   = msg;
            alertBox.className     = 'comment-form__alert comment-form__alert--' + type;
            alertBox.style.display = '';
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitBtn.disabled   = true;
                submitBtn.textContent = 'Posting…';
                alertBox.style.display = 'none';

                var data = new FormData(form);

                fetch('<?= SITE_URL ?>/api/comment_submit.php', {
                    method : 'POST',
                    body   : data,
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        showAlert(res.message, 'success');
                        form.reset();
                        charCount.textContent    = '0';
                        parentInput.value        = '';
                        replyingTo.style.display = 'none';
                    } else {
                        showAlert(res.message || 'Something went wrong.', 'error');
                    }
                })
                .catch(function () {
                    showAlert('Network error. Please try again.', 'error');
                })
                .finally(function () {
                    submitBtn.disabled    = false;
                    submitBtn.textContent = 'Post Comment';
                });
            });
        }
    }());
    </script>

    <!-- ===== RELATED NEWS ===== -->
    <?php if (!empty($relatedNews)): ?>
    <section class="section" aria-labelledby="related-heading">
        <h2 class="section__title" id="related-heading">
            <span class="section__title-accent">Related</span> News
        </h2>
        <div class="news-grid news-grid--4col">
            <?php foreach ($relatedNews as $item): ?>
            <article class="news-card">
                <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="news-card__img-link">
                    <img src="<?= htmlspecialchars(newsImage($item['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img"
                         loading="lazy">
                </a>
                <div class="news-card__body">
                    <h3 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h3>
                    <time class="news-card__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($item['created_at']) ?>
                    </time>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <!-- ===== NEXT ARTICLE ===== -->
    <?php if ($nextArticle): ?>
    <div class="next-article-box">
        <span class="next-article-box__label">Next Article</span>
        <a href="<?= htmlspecialchars(newsUrl($nextArticle['slug']), ENT_QUOTES, 'UTF-8') ?>"
           class="next-article-box__link">
            <?php if (!empty($nextArticle['featured_image'])): ?>
            <img src="<?= htmlspecialchars(newsImage($nextArticle['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($nextArticle['title'], ENT_QUOTES, 'UTF-8') ?>"
                 class="next-article-box__img"
                 loading="lazy">
            <?php endif; ?>
            <div class="next-article-box__body">
                <p class="next-article-box__title">
                    <?= htmlspecialchars($nextArticle['title'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <span class="next-article-box__cta">Read now &rarr;</span>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.layout-main -->

<!-- ===== SIDEBAR ===== -->
<aside class="layout-sidebar" aria-label="Sidebar">
    <?php if (!empty($sideItems)): ?>
    <div class="widget">
        <h3 class="widget__title translatable" data-hi="ताज़ा खबर">Latest News</h3>
        <ul class="trending-list">
            <?php foreach ($sideItems as $i => $item): ?>
            <li class="trending-list__item">
                <span class="trending-list__num"><?= $i + 1 ?></span>
                <div class="trending-list__body">
                    <a href="<?= htmlspecialchars(newsUrl($item['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="trending-list__link">
                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <time class="trending-list__date" datetime="<?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= formatDate($item['created_at']) ?>
                    </time>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Categories widget -->
    <div class="widget">
        <h3 class="widget__title translatable" data-hi="श्रेणियाँ">Categories</h3>
        <ul class="cat-list">
            <?php foreach (getAllCategories($pdo) as $cat): ?>
            <li>
                <a href="<?= htmlspecialchars(categoryUrl($cat['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="cat-list__link">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Sidebar Ad -->
    <?php if (!empty($adSidebar)): ?>
    <div class="widget ad-slot ad-slot--sidebar" style="text-align:center;">
        <?= $adSidebar ?>
    </div>
    <?php endif; ?>
</aside>

</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
