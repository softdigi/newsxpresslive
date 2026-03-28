<?php
// File: /newsxpresslive_api/web/category/index.php
// Category Page - FINAL (Production Ready)

// ⚠️ PRODUCTION SAFE
ini_set('display_errors', 0);
error_reporting(0);

require_once '../includes/config.php';
require_once '../includes/header.php';
require_once '../includes/seo.php';

// Get category slug
$category_slug = trim($_GET['slug'] ?? '');

if ($category_slug === '') {
    header('Location: /');
    exit;
}

/* =========================
   FETCH CATEGORY (SAFE)
========================= */
$stmt = $pdo->prepare("
    SELECT id, name, slug 
    FROM categories 
    WHERE slug = ?
    LIMIT 1
");
$stmt->execute([$category_slug]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    header('HTTP/1.0 404 Not Found');
    include '../includes/404.php';
    exit;
}

/* =========================
   PAGINATION
========================= */
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 20;
$offset    = ($page - 1) * $per_page;

/* =========================
   TOTAL COUNT (FAST)
========================= */
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM news 
    WHERE category_id = ?
      AND status = 'approved'
      AND (kill_switch IS NULL OR kill_switch = 'none')
");
$countStmt->execute([$category['id']]);
$total = (int)$countStmt->fetchColumn();

/* =========================
   FETCH NEWS LIST
========================= */
$stmt = $pdo->prepare("
    SELECT 
        n.id,
        n.title,
        n.description,
        n.featured_image,
        n.created_at,
        n.is_breaking,
        vb.boost_level,
        vb.boost_score
    FROM news n
    LEFT JOIN viral_boosts vb 
        ON n.id = vb.news_id 
       AND vb.status = 'active'
    WHERE n.category_id = ?
      AND n.status = 'approved'
      AND (n.kill_switch IS NULL OR n.kill_switch = 'none')
    ORDER BY 
        vb.boost_score DESC,
        n.is_breaking DESC,
        n.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $category['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   SEO META
========================= */
$page_title       = $category['name'] . " News - Latest Updates | News Xpress Live";
$page_description = "Get latest " . $category['name'] . " news, breaking updates, and trending stories. Stay updated with real-time coverage.";
$page_keywords    = $category['name'] . ", latest news, breaking news, India news";
$canonical_url    = "https://newsxpresslive.com/category/" . $category_slug;

include '../includes/header.php';
?>

<!-- CATEGORY HEADER -->
<section class="category-header">
    <div class="container">
        <nav class="breadcrumb">
            <a href="/">Home</a> /
            <span><?= htmlspecialchars($category['name']) ?></span>
        </nav>

        <h1>
            <i class="fas <?= getCategoryIcon($category['name']) ?>"></i>
            <?= htmlspecialchars($category['name']) ?>
        </h1>

        <p>Latest <?= htmlspecialchars($category['name']) ?> news from across India</p>

        <div class="count">
            <?= number_format($total) ?> stories
        </div>
    </div>
</section>

<!-- NEWS LIST -->
<section class="category-news">
    <div class="container">

        <?php if ($news_list): ?>
            <div class="news-list">

                <?php foreach ($news_list as $news): ?>
                    <article class="news-item">

                        <?php if (!empty($news['featured_image'])): ?>
                            <div class="news-thumbnail">
                                <img 
                                  src="/uploads/news/images/<?= htmlspecialchars($news['featured_image']) ?>" 
                                  alt="<?= htmlspecialchars($news['title']) ?>"
                                  loading="lazy">
                            </div>
                        <?php endif; ?>

                        <div class="news-details">

                            <?php if ($news['is_breaking']): ?>
                                <span class="badge-breaking">BREAKING</span>
                            <?php endif; ?>

                            <?php if (!empty($news['boost_level'])): ?>
                                <span class="viral-badge viral-<?= $news['boost_level'] ?>">
                                    <?= getViralBadge($news['boost_level']) ?> TRENDING
                                </span>
                            <?php endif; ?>

                            <h3>
                                <a href="/news/<?= generateSlug($news['title']) ?>-<?= $news['id'] ?>">
                                    <?= htmlspecialchars($news['title']) ?>
                                </a>
                            </h3>

                            <p class="news-excerpt">
                                <?= htmlspecialchars(mb_substr(strip_tags($news['description']), 0, 180)) ?>…
                            </p>

                            <div class="news-meta">
                                <span>🕒 <?= timeAgo($news['created_at']) ?></span>
                                <span>👁 <?= formatViews($news['id']) ?> views</span>
                            </div>

                        </div>
                    </article>
                <?php endforeach; ?>

            </div>

            <!-- PAGINATION -->
            <?php if ($total > $per_page): ?>
                <div class="pagination">
                    <?= getPagination($total, $per_page, $page, "/category/{$category_slug}") ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <h3>No news found</h3>
                <p>Please check back later.</p>
                <a href="/" class="btn-home">Back to Home</a>
            </div>
        <?php endif; ?>

    </div>
</section>

<!-- RELATED CATEGORIES -->
<section class="related-categories">
    <div class="container">
        <h2>Browse Other Categories</h2>

        <div class="categories-grid">
            <?php
            $cats = $pdo->prepare("
                SELECT name, slug 
                FROM categories 
                WHERE id != ?
                ORDER BY name 
                LIMIT 6
            ");
            $cats->execute([$category['id']]);
            foreach ($cats as $cat):
            ?>
                <a href="/category/<?= htmlspecialchars($cat['slug']) ?>" class="category-card">
                    <i class="fas <?= getCategoryIcon($cat['name']) ?>"></i>
                    <h3><?= htmlspecialchars($cat['name']) ?></h3>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php
generateBreadcrumbSchema([
    'Home' => 'https://newsxpresslive.com/',
    $category['name'] => $canonical_url
]);
?>

<?php include '../includes/footer.php'; ?>
