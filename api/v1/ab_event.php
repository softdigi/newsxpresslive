<?php
// ============================================================
// api/v1/ab_event.php
// Records an impression or click for an A/B headline test.
// Called automatically whenever a headline is shown (impression)
// or clicked (click) on the web / app frontend.
// Payload (POST JSON):
//   { "test_id": 7, "variant": "a", "event": "impression" }
//   { "test_id": 7, "variant": "b", "event": "click" }
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

$testId    = isset($body['test_id']) ? (int)$body['test_id']  : 0;
$variant   = $body['variant'] ?? '';
$eventType = $body['event']   ?? '';

$allowedVariants = ['a', 'b'];
$allowedEvents   = ['impression', 'click'];

if ($testId <= 0
    || !in_array($variant, $allowedVariants, true)
    || !in_array($eventType, $allowedEvents, true)
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

// Verify the test is still running
$check = $pdo->prepare(
    "SELECT id FROM ab_tests WHERE id = ? AND status = 'running' LIMIT 1"
);
$check->execute([$testId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Test not found or not running']);
    exit;
}

$sessionId = hash('sha256',
    ($_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown')
    . ($_SERVER['HTTP_USER_AGENT'] ?? '')
);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO ab_test_events (test_id, variant, event_type, session_id)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$testId, $variant, $eventType, $sessionId]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('ab_event: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
