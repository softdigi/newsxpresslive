<?php
/**
 * web/api/mandi/alert.php
 *
 * POST — Create / update a price alert.
 *   Body (JSON): { firebase_uid, commodity_id, mandi_id, alert_type, target_price }
 *
 * GET  ?firebase_uid=xxx — List user's active alerts.
 *
 * DELETE ?id=xxx&firebase_uid=xxx — Delete / deactivate an alert.
 *
 * PATCH ?id=xxx&firebase_uid=xxx — Toggle is_active.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

/* ── POST: create alert ───────────────────────────────────── */

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $uid         = trim($body['firebase_uid'] ?? '');
    $commodityId = isset($body['commodity_id']) ? (int)$body['commodity_id'] : 0;
    $mandiId     = isset($body['mandi_id'])     ? (int)$body['mandi_id']     : 0;
    $alertType   = in_array($body['alert_type'] ?? '', ['above','below'], true)
        ? $body['alert_type'] : 'above';
    $targetPrice = isset($body['target_price']) ? (float)$body['target_price'] : 0;

    if ($uid === '' || $commodityId <= 0 || $mandiId <= 0 || $targetPrice <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'firebase_uid, commodity_id, mandi_id, target_price required']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mandi_alerts
                (user_id, commodity_id, mandi_id, alert_type, target_price, is_active)
             VALUES (:uid, :cid, :mid, :type, :price, 1)
             ON DUPLICATE KEY UPDATE
                target_price = VALUES(target_price),
                is_active    = 1,
                last_triggered = NULL'
        );
        $stmt->execute([
            ':uid'   => $uid,
            ':cid'   => $commodityId,
            ':mid'   => $mandiId,
            ':type'  => $alertType,
            ':price' => $targetPrice,
        ]);
        echo json_encode(['success' => true, 'message' => 'Alert saved']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

/* ── GET: list alerts ─────────────────────────────────────── */

if ($method === 'GET') {
    $uid = trim($_GET['firebase_uid'] ?? '');
    if ($uid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'firebase_uid required']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.alert_type, a.target_price, a.is_active, a.last_triggered, a.created_at,
                c.name AS commodity_en, c.name_hi AS commodity, c.unit,
                m.name AS mandi
         FROM mandi_alerts a
         JOIN commodities c ON c.id = a.commodity_id
         JOIN mandis      m ON m.id = a.mandi_id
         WHERE a.user_id = ?
         ORDER BY a.created_at DESC'
    );
    $stmt->execute([$uid]);
    $alerts = $stmt->fetchAll();

    echo json_encode(['alerts' => $alerts]);
    exit;
}

/* ── DELETE: remove alert ─────────────────────────────────── */

if ($method === 'DELETE') {
    $alertId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
    $uid     = trim($_GET['firebase_uid'] ?? '');
    if ($alertId <= 0 || $uid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'id and firebase_uid required']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM mandi_alerts WHERE id = ? AND user_id = ?');
    $stmt->execute([$alertId, $uid]);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

/* ── PATCH: toggle is_active ──────────────────────────────── */

if ($method === 'PATCH') {
    $alertId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
    $uid     = trim($_GET['firebase_uid'] ?? '');
    if ($alertId <= 0 || $uid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'id and firebase_uid required']);
        exit;
    }
    $stmt = $pdo->prepare(
        'UPDATE mandi_alerts SET is_active = 1 - is_active WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$alertId, $uid]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
