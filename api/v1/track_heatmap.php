<?php
// ============================================================
// api/v1/track_heatmap.php
// Records a single click event with normalised (x%, y%) coords.
// Called from web frontend via fetch() on every article page click.
// Payload (POST JSON):
//   { "news_id": 42, "x_pct": 53, "y_pct": 38 }
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../geo/config.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$newsId    = isset($body['news_id']) ? (int)$body['news_id']  : 0;
$xPct      = isset($body['x_pct'])   ? (int)$body['x_pct']   : -1;
$yPct      = isset($body['y_pct'])   ? (int)$body['y_pct']   : -1;

if ($newsId <= 0 || $xPct < 0 || $xPct > 100 || $yPct < 0 || $yPct > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

// Anonymous session fingerprint — never store raw IP
$sessionId = hash('sha256',
    ($_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown')
    . ($_SERVER['HTTP_USER_AGENT'] ?? '')
);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO click_heatmap (news_id, x_pct, y_pct, session_id)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$newsId, $xPct, $yPct, $sessionId]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('track_heatmap: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
