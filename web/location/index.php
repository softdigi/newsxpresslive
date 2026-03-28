<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/seo.php';

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    header("Location: ".SITE_URL);
    exit;
}

// slug → location name
$location_name = ucwords(str_replace('-', ' ', $slug));

// Fetch location-based news
$stmt = $pdo->prepare("
    SELECT n.*
    FROM news n
    WHERE n.status='approved'
    AND (n.state_slug = :slug OR n.city_slug = :slug)
    ORDER BY 
      CASE WHEN n.is_viral_boosted=1 THEN 1 ELSE 2 END,
      n.created_at DESC
    LIMIT 30
");
$stmt->execute(['slug' => $slug]);
$news_list = $stmt->fetchAll();

// SEO
$page_title = "$location_name News - Latest & Breaking Updates";
$page_description = "Latest breaking news, viral updates and top stories from $location_name.";
$canonical_url = SITE_URL."/newsxpresslive_api/web/location/".$slug;

include __DIR__.'/../includes/header.php';
?>

<div class="container">

<nav class="breadcrumb">
  <a href="<?= SITE_URL ?>">Home</a> ›
  <span><?= htmlspecialchars($location_name) ?></span>
</nav>

<h1 class="page-title">📍 <?= htmlspecialchars($location_name) ?> News</h1>

<?php if (!$news_list): ?>
  <p>No news available for this location.</p>
<?php endif; ?>

<div class="news-grid">
<?php foreach ($news_list as $news): ?>
  <article class="news-card">
    <?php if ($news['featured_image']): ?>
      <img src="<?= SITE_URL ?>/uploads/news/images/<?= htmlspecialchars($news['featured_image']) ?>">
    <?php endif; ?>

    <div class="news-content">
      <h3>
        <a href="<?= SITE_URL ?>/newsxpresslive_api/web/news/<?= generateSlug($news['title']) ?>-<?= $news['id'] ?>">
          <?= htmlspecialchars($news['title']) ?>
        </a>
      </h3>

      <p><?= substr(strip_tags($news['description']),0,120) ?>...</p>

      <div class="news-meta">
        <span><?= timeAgo($news['created_at']) ?></span>
        <span><?= formatViews($news['views']) ?> views</span>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>

<div class="app-cta-inline">
  <p>📱 Get local alerts instantly on our app</p>
  <a href="<?= APP_DOWNLOAD_LINK ?>" class="btn-download">Download App</a>
</div>

</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
