<?php
/**
 * web/api/listings/my_listings.php
 *
 * GET ?firebase_uid=xxx[&status=active|sold|expired|pending]
 *      [&cursor=0&limit=20]
 *
 * Returns listings posted by the user, optionally filtered by status.
 *
 * DELETE ?firebase_uid=xxx&id=N  — soft-delete (set status=rejected)
 *
 * PATCH  body: { firebase_uid, id, status }  — mark as sold/active
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

/* ── GET ──────────────────────────────────────────────────── */

if ($method === 'GET') {
    $uid    = trim($_GET['firebase_uid'] ?? '');
    if ($uid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'firebase_uid required']);
        exit;
    }

    $validStatuses = ['active','sold','expired','pending','rejected'];
    $status = in_array($_GET['status'] ?? '', $validStatuses, true)
        ? $_GET['status'] : null;
    $cursor = isset($_GET['cursor']) && ctype_digit($_GET['cursor']) ? (int)$_GET['cursor'] : 0;
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));

    $sql    = '
    SELECT l.id, l.title, l.images, l.price, l.price_type, l.listing_type,
           l.city, l.status, l.views_count, l.saves_count,
           l.is_featured, l.created_at, l.expires_at,
           lc.name AS category, lc.name_hi AS category_hi
    FROM listings l
    JOIN listing_categories lc ON lc.id = l.category_id
    WHERE l.user_id = :uid
    ';
    $params = [':uid' => $uid];

    if ($status !== null) {
        $sql .= ' AND l.status = :status';
        $params[':status'] = $status;
    }
    if ($cursor > 0) {
        $sql .= ' AND l.id < :cursor';
        $params[':cursor'] = $cursor;
    }
    $sql .= ' ORDER BY l.created_at DESC LIMIT :lim';
    $params[':lim'] = $limit + 1;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($k, $v, $type);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $nextCursor = $hasMore && !empty($rows) ? (int)end($rows)['id'] : null;

    $listings = array_map(function ($r) {
        $imgs = [];
        if (!empty($r['images'])) {
            $d = json_decode($r['images'], true);
            if (is_array($d)) $imgs = $d;
        }
        return [
            'id'           => (int)$r['id'],
            'title'        => $r['title'],
            'thumb'        => $imgs[0] ?? null,
            'price'        => $r['price'] !== null ? (float)$r['price'] : null,
            'price_type'   => $r['price_type'],
            'listing_type' => $r['listing_type'],
            'city'         => $r['city'],
            'status'       => $r['status'],
            'views_count'  => (int)$r['views_count'],
            'saves_count'  => (int)$r['saves_count'],
            'is_featured'  => (bool)$r['is_featured'],
            'category'     => $r['category'],
            'category_hi'  => $r['category_hi'],
            'created_at'   => $r['created_at'],
            'expires_at'   => $r['expires_at'],
        ];
    }, $rows);

    echo json_encode([
        'listings'    => $listings,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
    exit;
}

/* ── DELETE: remove listing ───────────────────────────────── */

if ($method === 'DELETE') {
    $uid = trim($_GET['firebase_uid'] ?? '');
    $id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($uid === '' || $id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'firebase_uid and id required']);
        exit;
    }
    $stmt = $pdo->prepare(
        'UPDATE listings SET status = \'rejected\' WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$id, $uid]);
    echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
    exit;
}

/* ── PATCH: update listing status ─────────────────────────── */

if ($method === 'PATCH') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $uid    = trim($body['firebase_uid'] ?? '');
    $id     = isset($body['id']) ? (int)$body['id'] : 0;
    $status = $body['status'] ?? '';

    if ($uid === '' || $id <= 0 || !in_array($status, ['active','sold','expired'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'firebase_uid, id, valid status required']);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE listings SET status = ? WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$status, $id, $uid]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
