<?php
/**
 * web/api/verification/channels.php
 *
 * GET  → paginated list of active media channels (for reporter verification form)
 *   ?type=tv|print|online|digital|radio   (optional filter)
 *   ?q=<search>                            (optional search)
 *   ?page=1&per_page=50
 *
 * Response:
 *   { success: true, channels: [...], total: N }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['GET', 'OPTIONS']);
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$type     = trim($_GET['type'] ?? '');
$q        = trim($_GET['q']    ?? '');
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $perPage;

$allowed_types = ['tv','digital','print','radio','online'];
$type = in_array($type, $allowed_types, true) ? $type : '';

$where = ['is_active = 1'];
$params = [];

if ($type !== '') {
    $where[] = 'type = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where[] = 'name LIKE ?';
    $params[] = '%' . $q . '%';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM media_channels {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, name, type FROM media_channels {$whereSQL}
     ORDER BY name ASC LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'  => true,
    'channels' => $channels,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
