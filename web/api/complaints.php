<?php
/**
 * web/api/complaints.php
 *
 * GET  /api/complaints.php
 *      Returns paginated approved complaints.
 *
 * Query params:
 *   page        int     (default 1)
 *   per_page    int     (default 10, max 30)
 *   category    string  category slug (optional)
 *   district_id int     (optional)
 *   status      string  'approved'|'under_review'|'resolved' (default 'approved')
 *
 * Also returns the full list of complaint categories on GET with ?meta=1.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

/* ── Meta: categories list ─────────────────────────────────── */
if (isset($_GET['meta'])) {
    try {
        $cats = $pdo->query(
            'SELECT id, name, slug, icon FROM complaint_categories ORDER BY sort_order ASC'
        )->fetchAll();
        echo json_encode(['categories' => $cats]);
    } catch (PDOException $e) {
        echo json_encode(['categories' => []]);
    }
    exit;
}

/* ── Pagination & filters ──────────────────────────────────── */
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(30, max(1, (int)($_GET['per_page'] ?? 10)));
$offset  = ($page - 1) * $perPage;

$categorySlug = trim($_GET['category'] ?? '');
$districtId   = isset($_GET['district_id']) && ctype_digit($_GET['district_id'])
    ? (int)$_GET['district_id'] : null;

$allowedStatuses = ['approved', 'under_review', 'resolved'];
$status = in_array($_GET['status'] ?? '', $allowedStatuses, true)
    ? $_GET['status']
    : 'approved';

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));

/* ── Build query ────────────────────────────────────────────── */
$where  = ['c.status = :status'];
$params = [':status' => $status];

if ($categorySlug !== '') {
    $where[]                = 'cc.slug = :cat';
    $params[':cat']         = $categorySlug;
}
if ($districtId !== null) {
    $where[]                = 'c.district_id = :did';
    $params[':did']         = $districtId;
}

$whereSql = implode(' AND ', $where);

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.id = c.category_id
         WHERE $whereSql"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, c.description, c.photo, c.votes_count,
                c.status, c.location_text, c.is_anonymous,
                IF(c.is_anonymous = 1, NULL, c.author_name) AS author_name,
                c.created_at,
                cc.name AS category_name, cc.slug AS category_slug, cc.icon AS category_icon,
                l.name AS district_name
         FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.id = c.category_id
         LEFT JOIN locations l             ON l.id = c.district_id
         WHERE $whereSql
         ORDER BY c.votes_count DESC, c.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $params[':limit']  = $perPage;
    $params[':offset'] = $offset;
    $stmt->bindValue(':status', $params[':status'], PDO::PARAM_STR);
    if ($categorySlug !== '') $stmt->bindValue(':cat', $params[':cat'], PDO::PARAM_STR);
    if ($districtId   !== null) $stmt->bindValue(':did', $params[':did'], PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    echo json_encode(['complaints' => [], 'total' => 0, 'page' => $page]);
    exit;
}

// Check which complaints the current IP has already voted on
$votedIds = [];
if (!empty($rows)) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $voteStmt = $pdo->prepare(
            "SELECT complaint_id FROM complaint_votes
             WHERE complaint_id IN ($placeholders) AND ip_hash = ?"
        );
        $voteStmt->execute([...$ids, $ipHash]);
        $votedIds = array_column($voteStmt->fetchAll(), 'complaint_id');
    } catch (PDOException $e) { /* silent */ }
}

$items = array_map(static function (array $row) use ($votedIds): array {
    return [
        'id'            => (int)$row['id'],
        'title'         => $row['title'],
        'description'   => $row['description'],
        'photo'         => $row['photo']
            ? rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/uploads/complaints/' . $row['photo']
            : null,
        'votes_count'   => (int)$row['votes_count'],
        'status'        => $row['status'],
        'location_text' => $row['location_text'],
        'district_name' => $row['district_name'],
        'author_name'   => $row['author_name'],
        'created_at'    => $row['created_at'],
        'category'      => [
            'name' => $row['category_name'],
            'slug' => $row['category_slug'],
            'icon' => $row['category_icon'],
        ],
        'user_voted'    => in_array((int)$row['id'], $votedIds, true),
    ];
}, $rows);

echo json_encode([
    'complaints' => $items,
    'total'      => $total,
    'page'       => $page,
    'per_page'   => $perPage,
    'has_more'   => $total > $page * $perPage,
]);
