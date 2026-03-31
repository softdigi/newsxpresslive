<?php
/**
 * web/api/reels.php
 *
 * GET  /api/reels.php?page=1&per_page=5
 *      Returns paginated published reels.
 *
 * POST /api/reels.php?action=view&id=N
 *      Increments view count for reel N.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

/* ── Record a view ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        try {
            $pdo->prepare('UPDATE video_reels SET views_count = views_count + 1 WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (PDOException $e) { /* silent */ }
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* ── GET: fetch feed ───────────────────────────────────────── */
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(20, max(1, (int)($_GET['per_page'] ?? 5)));
$offset  = ($page - 1) * $perPage;

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));

try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.title, r.description, r.video_file, r.thumbnail,
                r.likes_count, r.views_count, r.comments_count, r.created_at,
                rep.name AS reporter_name
         FROM video_reels r
         LEFT JOIN reporters rep ON rep.id = r.reporter_id
         WHERE r.status = :status
         ORDER BY r.created_at DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
    $stmt->bindValue(':limit',  $perPage,    PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
    echo json_encode(['reels' => []]);
    exit;
}

// Check which reels the current IP has already liked
$likedIds = [];
if (!empty($rows)) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $likeStmt = $pdo->prepare(
            "SELECT reel_id FROM reel_likes WHERE reel_id IN ($placeholders) AND ip_hash = ?"
        );
        $likeStmt->execute([...$ids, $ipHash]);
        $likedIds = array_column($likeStmt->fetchAll(), 'reel_id');
    } catch (PDOException $e) { /* silent */ }
}

$reels = array_map(static function (array $row) use ($likedIds): array {
    return [
        'id'             => (int)$row['id'],
        'title'          => $row['title'],
        'description'    => $row['description'],
        'video_url'      => reelVideoUrl($row['video_file']),
        'thumbnail'      => $row['thumbnail'] ? reelThumbUrl($row['thumbnail']) : null,
        'reporter_name'  => $row['reporter_name'] ?? 'Reporter',
        'likes_count'    => (int)$row['likes_count'],
        'views_count'    => (int)$row['views_count'],
        'comments_count' => (int)$row['comments_count'],
        'created_at'     => $row['created_at'],
        'user_liked'     => in_array((int)$row['id'], $likedIds, true),
    ];
}, $rows);

echo json_encode(['reels' => $reels, 'page' => $page, 'per_page' => $perPage]);
