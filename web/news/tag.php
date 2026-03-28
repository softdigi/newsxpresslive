<?php
/**
 * Tag Page
 * NewsXpressLive
 * 
 * Lists news articles with a specific tag
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$tagSlug = getParam('slug');
$tagName = '';
$tagId = 0;
$newsItems = [];
$totalItems = 0;

if ($tagSlug !== '') {
    try {
        // Get tag info
        $tagStmt = $pdo->prepare('SELECT id, name FROM tags WHERE slug = :slug LIMIT 1');
        $tagStmt->execute([':slug' => $tagSlug]);
        $tag = $tagStmt->fetch();
        
        if ($tag) {
            $tagId = (int)$tag['id'];
            $tagName = $tag['name'];
            
            // Get pagination
            $pagination = getPagination(12);
            $page = $pagination['page'];
            $offset = $pagination['offset'];
            $perPage = $pagination['perPage'];
            
            // Count total
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM news n
                 INNER JOIN news_tags nt ON nt.news_id = n.id
                 WHERE nt.tag_id = :tag_id AND n.status = :status'
            );
            $countStmt->execute([':tag_id' => $tagId, ':status' => 'published']);
            $totalItems = (int)$countStmt->fetchColumn();
            
            // Get news
            $newsStmt = $pdo->prepare(
                'SELECT n.id, n.title, n.slug, n.featured_image, n.content, n.created_at,
                        c.name AS category_name, c.slug AS category_slug
                 FROM news n
                 INNER JOIN news_tags nt ON nt.news_id = n.id
                 LEFT JOIN categories c ON c.id = n.category_id
                 WHERE nt.tag_id = :tag_id AND n.status = :status
                 ORDER BY n.created_at DESC
                 LIMIT :limit OFFSET :offset'
            );
            $newsStmt->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
            $newsStmt->bindValue(':status', 'published', PDO::PARAM_STR);
            $newsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $newsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $newsStmt->execute();
            $newsItems = $newsStmt->fetchAll();
        }
    } catch (PDOException $e) {
        // Tables might not exist
        error_log('Tag page error: ' . $e->getMessage());
    }
}

if (!$tagName) {
    http_response_code(404);
    $seoMeta = ['title' => 'Tag Not Found', 'robots' => 'noindex,nofollow'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><p class="not-found">The tag you are looking for does not exist.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$seoMeta = [
    'title'       => 'Tag: ' . $tagName,
    'description' => 'Browse all news articles tagged with "' . $tagName . '" on ' . SITE_NAME,
    'url'         => SITE_URL . '/news/tag.php?slug=' . urlencode($tagSlug),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main">

    <section class="section" aria-labelledby="tag-heading">
        <div class="tag-header">
            <span class="tag-header__icon">🏷️</span>
            <h1 class="tag-header__name" id="tag-heading"><?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        
        <p class="section__count"><?= $totalItems ?> article<?= $totalItems !== 1 ? 's' : '' ?> found</p>
        
        <?php if (empty($newsItems)): ?>
        <p class="no-results">No articles found with this tag.</p>
        <?php else: ?>
        
        <div class="news-grid">
            <?php foreach ($newsItems as $news): ?>
            <article class="news-card">
                <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   class="news-card__img-link">
                    <img src="<?= htmlspecialchars(newsImage($news['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>"
                         class="news-card__img"
                         loading="lazy">
                </a>
                <div class="news-card__body">
                    <?php if (!empty($news['category_name'])): ?>
                    <a href="<?= htmlspecialchars(categoryUrl($news['category_slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="badge badge--outline">
                        <?= htmlspecialchars($news['category_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php endif; ?>
                    <h3 class="news-card__title">
                        <a href="<?= htmlspecialchars(newsUrl($news['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </h3>
                    <p class="news-card__excerpt">
                        <?= htmlspecialchars(excerpt($news['content']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="news-card__footer">
                        <time class="news-card__date" datetime="<?= htmlspecialchars($news['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= formatDate($news['created_at']) ?>
                        </time>
                        <span class="news-card__read-time"><?= readingTime($news['content']) ?> min read</span>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        
        <?php renderPagination($totalItems, $perPage, $page, SITE_URL . '/news/tag.php?slug=' . urlencode($tagSlug)); ?>
        <?php endif; ?>
        
    </section>

</div><!-- /.layout-main -->

<!-- Sidebar -->
<aside class="layout-sidebar" aria-label="Sidebar">
    <div class="widget">
        <h3 class="widget__title">Categories</h3>
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
</aside>

</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
