<?php
/**
 * web/api/read_history.php
 * Feature 2 — Explicit Read History Tracking
 *
 * ── POST  (record a read event) ────────────────────────────────────────
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 * Body (JSON):
 *   {
 *     "news_id":        123,
 *     "read_percent":   85,     // 0-100; optional, default 0
 *     "time_spent_sec": 60      // seconds; optional, default 0
 *   }
 * Response: {"success": true}
 *
 * ── GET   (fetch read history) ─────────────────────────────────────────
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 * Query:
 *   page   int ≥1      (default 1)
 *   limit  int 1–50    (default 20)
 * Response:
 *   {
 *     "success": true,
 *     "page": 1,
 *     "has_more": true,
 *     "history": [
 *       { "news_id": 123, "title": "...", "read_percent": 85,
 *         "time_spent_sec": 60, "read_at": "2024-01-01 12:00:00" }
 *     ]
 *   }
 *
 * Side-effects on POST
 * ────────────────────
 * After recording the history row this endpoint also updates the user's
 * behavioral interest weight for the article's category in user_interests.
 * Weight delta formula:
 *   delta = base_weight * time_factor * completion_factor
 *   base_weight       = 0.10  (each individual read)
 *   time_factor       = MIN(time_spent_sec / 60, 3)  →  0 – 3
 *   completion_factor = read_percent / 100            →  0 – 1
 *   delta is capped so total weight never exceeds 5.00
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../auth/firebase.php';

/* ── Auth ────────────────────────────────────────────────────────────── */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}

if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}

$payload = verifyFirebaseToken($idToken);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

$firebaseUid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($firebaseUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token payload']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ══════════════════════════════════════════════════════════════════════
   POST — record a read event
   ══════════════════════════════════════════════════════════════════════ */
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }

    $newsId       = (int)($input['news_id']        ?? 0);
    $readPercent  = min(100, max(0, (int)($input['read_percent']   ?? 0)));
    $timeSpentSec = min(3600, max(0, (int)($input['time_spent_sec'] ?? 0)));

    if ($newsId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid news_id']);
        exit;
    }

    try {
        // Upsert read-history row; re-reads update percent and time
        $pdo->prepare(
            'INSERT INTO user_read_history
                 (user_id, news_id, read_percent, time_spent_sec)
             VALUES (:uid, :nid, :pct, :ts)
             ON DUPLICATE KEY UPDATE
                 read_percent   = GREATEST(read_percent,   VALUES(read_percent)),
                 time_spent_sec = GREATEST(time_spent_sec, VALUES(time_spent_sec)),
                 read_at        = CURRENT_TIMESTAMP'
        )->execute([
            ':uid' => $firebaseUid,
            ':nid' => $newsId,
            ':pct' => $readPercent,
            ':ts'  => $timeSpentSec,
        ]);
    } catch (PDOException $e) {
        error_log('read_history insert: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }

    /* ── Update behavioral interest weight for the article's category ── */
    try {
        $catRow = $pdo->prepare(
            'SELECT category_id FROM news WHERE id = :id AND status = \'approved\' LIMIT 1'
        );
        $catRow->execute([':id' => $newsId]);
        $categoryId = (int)($catRow->fetchColumn() ?: 0);

        if ($categoryId > 0) {
            // delta = base(0.10) * time_factor(0–3) * completion(0–1)
            $timeFactor       = min($timeSpentSec / 60.0, 3.0);
            $completionFactor = $readPercent / 100.0;
            // Ensure a minimum delta even for quick reads (click = some interest)
            $delta = 0.10 * max($timeFactor, 0.2) * max($completionFactor, 0.2);
            $delta = round($delta, 4);

            $pdo->prepare(
                'INSERT INTO user_interests
                     (user_id, category_id, tag, source, weight)
                 VALUES (:uid, :cid, NULL, \'behavioral\', :w)
                 ON DUPLICATE KEY UPDATE
                     weight = LEAST(weight + :w2, 5.00),
                     source = CASE WHEN source = \'behavioral\' THEN \'behavioral\'
                                   ELSE source END'
            )->execute([
                ':uid' => $firebaseUid,
                ':cid' => $categoryId,
                ':w'   => $delta,
                ':w2'  => $delta,
            ]);
        }
    } catch (PDOException $e) {
        // Non-fatal: history row already saved
        error_log('read_history interest update: ' . $e->getMessage());
    }

    echo json_encode(['success' => true]);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   GET — fetch read history
   ══════════════════════════════════════════════════════════════════════ */
if ($method === 'GET') {
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $stmt = $pdo->prepare(
            'SELECT urh.news_id,
                    n.title,
                    n.slug,
                    n.featured_image,
                    n.created_at        AS article_created_at,
                    c.name              AS category_name,
                    c.slug              AS category_slug,
                    urh.read_percent,
                    urh.time_spent_sec,
                    urh.read_at
             FROM   user_read_history urh
             JOIN   news n        ON n.id = urh.news_id
             LEFT   JOIN categories c ON c.id = n.category_id
             WHERE  urh.user_id = :uid
               AND  n.status    = \'approved\'
             ORDER  BY urh.read_at DESC
             LIMIT  :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $firebaseUid, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit,       PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,      PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['news_id']       = (int)$row['news_id'];
            $row['read_percent']  = (int)$row['read_percent'];
            $row['time_spent_sec'] = (int)$row['time_spent_sec'];
            $row['featured_image'] = !empty($row['featured_image'])
                ? UPLOADS_URL . rawurlencode($row['featured_image'])
                : null;
        }
        unset($row);

        echo json_encode([
            'success'  => true,
            'page'     => $page,
            'has_more' => (count($rows) >= $limit),
            'history'  => array_values($rows),
        ]);

    } catch (PDOException $e) {
        error_log('read_history GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
