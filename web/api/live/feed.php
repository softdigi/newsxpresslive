<?php
/**
 * web/api/live/feed.php
 *
 * GET  /api/live/feed.php
 *      Returns live, scheduled, and recently-ended streams.
 *
 * Params:
 *   status     live|scheduled|ended|all   default: "live,scheduled"
 *   limit      1-50                       default: 20
 *   cursor     last seen id (0 = first)
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$statusParam = trim($_GET['status'] ?? 'live,scheduled');
$limit       = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$cursor      = isset($_GET['cursor']) && ctype_digit($_GET['cursor']) ? (int)$_GET['cursor'] : 0;

/* ── Allowed statuses ─────────────────────────────────────── */
$allowed   = ['live', 'scheduled', 'ended', 'cancelled'];
$requested = array_filter(
    array_map('trim', explode(',', $statusParam)),
    static fn($s) => in_array($s, $allowed, true)
);
if (empty($requested)) {
    $requested = ['live', 'scheduled'];
}

/* ── Build placeholders ───────────────────────────────────── */
$placeholders = implode(',', array_fill(0, count($requested), '?'));

$params = array_values($requested);
$types  = str_repeat('s', count($requested));

// Cursor for keyset pagination
$cursorClause = '';
if ($cursor > 0) {
    $cursorClause = 'AND ls.id < ?';
    $params[]     = $cursor;
    $types        .= 'i';
}

$params[] = $limit;
$types    .= 'i';

$sql = "
SELECT
    ls.id,
    ls.title,
    ls.description,
    ls.thumbnail,
    ls.playback_url,
    ls.status,
    ls.viewer_count,
    ls.peak_viewers,
    ls.reaction_count,
    ls.is_featured,
    ls.scheduled_at,
    ls.started_at,
    ls.ended_at,
    ls.created_at,
    COALESCE(r.name,  a.name)  AS author_name,
    COALESCE(r.photo, a.logo)  AS author_photo,
    CASE WHEN r.id IS NOT NULL THEN 'reporter' ELSE 'agency' END AS author_type
FROM live_streams ls
LEFT JOIN reporters r ON r.id = ls.reporter_id
LEFT JOIN agencies  a ON a.id = ls.agency_id
WHERE ls.status IN ($placeholders)
  $cursorClause
ORDER BY
    FIELD(ls.status, 'live', 'scheduled', 'ended', 'cancelled'),
    ls.is_featured DESC,
    ls.started_at DESC,
    ls.scheduled_at ASC,
    ls.id DESC
LIMIT ?
";

try {
    $stmt = $pdo->prepare($sql);

    // Bind all params
    $bindIndex = 1;
    foreach ($params as $i => $v) {
        $type = $types[$i] === 'i' ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($bindIndex++, $v, $type);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

$streams   = [];
$lastId    = 0;
$uploadsUrl = defined('UPLOADS_URL') ? UPLOADS_URL : (SITE_URL . '/uploads/');

foreach ($rows as $row) {
    $lastId = (int)$row['id'];
    $streams[] = [
        'id'            => $lastId,
        'title'         => $row['title'],
        'description'   => $row['description'],
        'thumbnail'     => $row['thumbnail'] ? ($uploadsUrl . $row['thumbnail']) : null,
        'playback_url'  => $row['playback_url'],
        'status'        => $row['status'],
        'viewer_count'  => (int)$row['viewer_count'],
        'peak_viewers'  => (int)$row['peak_viewers'],
        'reaction_count'=> (int)$row['reaction_count'],
        'is_featured'   => (bool)$row['is_featured'],
        'scheduled_at'  => $row['scheduled_at'],
        'started_at'    => $row['started_at'],
        'ended_at'      => $row['ended_at'],
        'created_at'    => $row['created_at'],
        'author_name'   => $row['author_name'] ?? 'NewsXpress',
        'author_photo'  => $row['author_photo'] ? ($uploadsUrl . $row['author_photo']) : null,
        'author_type'   => $row['author_type'] ?? 'reporter',
    ];
}

echo json_encode([
    'streams'     => $streams,
    'next_cursor' => count($streams) === $limit ? $lastId : null,
]);
