<?php
/**
 * admin/live_streams.php
 *
 * POST  action=list        — paginated stream list
 * POST  action=create      — schedule a new live stream
 * POST  action=start       — mark stream as live (sets started_at)
 * POST  action=end         — mark stream as ended
 * POST  action=cancel      — cancel a scheduled stream
 * POST  action=feature     — toggle is_featured
 * POST  action=delete      — hard-delete a stream
 */

header('Content-Type: application/json');
require __DIR__ . '/../geo/config.php';
require __DIR__ . '/../geo/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], 'POST required');
}

$input  = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$admin  = requireAppAdmin($pdo, $input['admin_uid'] ?? '');
$action = trim($input['action'] ?? 'list');

/* ── Helper ──────────────────────────────────────────────── */
function generateStreamKey(): string {
    return 'nxl-' . bin2hex(random_bytes(12));
}

/* ── LIST ────────────────────────────────────────────────── */
if ($action === 'list') {
    $status = in_array($input['status'] ?? '', ['live','scheduled','ended','cancelled','all'], true)
        ? ($input['status'] ?? 'all') : 'all';
    $limit  = max(1, min(100, (int)($input['limit'] ?? 20)));
    $offset = max(0, (int)($input['offset'] ?? 0));

    $where  = $status !== 'all' ? 'WHERE ls.status = :status' : '';
    $params = $status !== 'all' ? [':status' => $status] : [];

    try {
        $total = $pdo->prepare("SELECT COUNT(*) FROM live_streams ls $where");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $params[':limit']  = $limit;
        $params[':offset'] = $offset;

        $stmt = $pdo->prepare("
            SELECT ls.*,
                   COALESCE(r.name, a.name) AS author_name
            FROM live_streams ls
            LEFT JOIN reporters r ON r.id = ls.reporter_id
            LEFT JOIN agencies  a ON a.id = ls.agency_id
            $where
            ORDER BY ls.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $type = ($k === ':limit' || $k === ':offset') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($k, $v, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        jsonResponse(false, [], 'db_error');
    }

    jsonResponse(true, ['streams' => $rows, 'total' => $totalCount]);
}

/* ── CREATE ──────────────────────────────────────────────── */
if ($action === 'create') {
    $title        = mb_substr(strip_tags(trim($input['title'] ?? '')), 0, 200);
    $description  = mb_substr(strip_tags(trim($input['description'] ?? '')), 0, 2000);
    $reporterId   = isset($input['reporter_id']) ? (int)$input['reporter_id'] : null;
    $agencyId     = isset($input['agency_id'])   ? (int)$input['agency_id']   : null;
    $playbackUrl  = mb_substr(trim($input['playback_url'] ?? ''), 0, 500);
    $scheduledAt  = !empty($input['scheduled_at']) ? $input['scheduled_at'] : null;
    $isFeatured   = !empty($input['is_featured']) ? 1 : 0;
    $streamKey    = generateStreamKey();

    if ($title === '') {
        jsonResponse(false, [], 'title_required');
    }

    try {
        $pdo->prepare(
            'INSERT INTO live_streams
             (reporter_id, agency_id, title, description, stream_key, playback_url,
              status, is_featured, scheduled_at)
             VALUES
             (:rid, :aid, :title, :desc, :key, :url,
              "scheduled", :feat, :sched)'
        )->execute([
            ':rid'   => $reporterId,
            ':aid'   => $agencyId,
            ':title' => $title,
            ':desc'  => $description,
            ':key'   => $streamKey,
            ':url'   => $playbackUrl,
            ':feat'  => $isFeatured,
            ':sched' => $scheduledAt,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        jsonResponse(false, [], 'db_error');
    }

    jsonResponse(true, ['id' => $newId, 'stream_key' => $streamKey]);
}

/* ── STATUS TRANSITIONS ──────────────────────────────────── */
$transitionMap = [
    'start'  => ['status' => 'live',      'extra' => 'started_at = NOW()'],
    'end'    => ['status' => 'ended',     'extra' => 'ended_at = NOW()'],
    'cancel' => ['status' => 'cancelled', 'extra' => null],
];

if (isset($transitionMap[$action])) {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) jsonResponse(false, [], 'id_required');

    $map   = $transitionMap[$action];
    $extra = $map['extra'] ? ', ' . $map['extra'] : '';

    try {
        $stmt = $pdo->prepare(
            "UPDATE live_streams SET status = :status $extra WHERE id = :id"
        );
        $stmt->execute([':status' => $map['status'], ':id' => $id]);
        if ($stmt->rowCount() === 0) jsonResponse(false, [], 'not_found');
    } catch (PDOException $e) {
        jsonResponse(false, [], 'db_error');
    }

    jsonResponse(true, ['id' => $id, 'status' => $map['status']]);
}

/* ── FEATURE TOGGLE ──────────────────────────────────────── */
if ($action === 'feature') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) jsonResponse(false, [], 'id_required');

    try {
        $pdo->prepare(
            'UPDATE live_streams SET is_featured = NOT is_featured WHERE id = :id'
        )->execute([':id' => $id]);
    } catch (PDOException $e) {
        jsonResponse(false, [], 'db_error');
    }

    jsonResponse(true, ['id' => $id]);
}

/* ── DELETE ──────────────────────────────────────────────── */
if ($action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) jsonResponse(false, [], 'id_required');

    try {
        $pdo->prepare('DELETE FROM live_chat      WHERE stream_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM live_reactions WHERE stream_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM live_viewers   WHERE stream_id = :id')->execute([':id' => $id]);
        $stmt = $pdo->prepare('DELETE FROM live_streams WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) jsonResponse(false, [], 'not_found');
    } catch (PDOException $e) {
        jsonResponse(false, [], 'db_error');
    }

    jsonResponse(true, []);
}

jsonResponse(false, [], 'unknown_action');
