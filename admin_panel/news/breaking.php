<?php
require "../includes/config.php";
require "../includes/auth.php";

/* Fetch approved news */
$stmt = $pdo->query(
    "SELECT 
        n.id,
        n.title,
        n.is_breaking,
        n.created_at,
        c.name AS category
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     WHERE n.status='approved'
     ORDER BY n.created_at DESC
     LIMIT 50"
);
$newsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/sidebar.php"; ?>

<div class="content">
  <h1>Breaking News Control</h1>

  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Category</th>
        <th>Breaking</th>
        <th>Action</th>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($newsList as $news): ?>
      <tr>
        <td><?= $news['id'] ?></td>
        <td><?= htmlspecialchars($news['title']) ?></td>
        <td><?= $news['category'] ?></td>

        <td>
          <?php if ($news['is_breaking']): ?>
            <span class="badge green">YES</span>
          <?php else: ?>
            <span class="badge red">NO</span>
          <?php endif; ?>
        </td>

        <td>
          <?php if (!$news['is_breaking']): ?>
            <a href="../actions/breaking_news.php?id=<?= $news['id'] ?>"
               class="btn orange">Make Breaking</a>
          <?php else: ?>
            <a href="../actions/unbreaking_news.php?id=<?= $news['id'] ?>"
               class="btn red">Remove</a>

            <a href="../notifications/breaking.php?news_id=<?= $news['id'] ?>"
               class="btn blue">Send Notification</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include "../includes/footer.php"; ?>
