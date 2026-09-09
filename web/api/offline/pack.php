<?php
/**
 * web/api/offline/pack.php
 * Public API — get metadata for a daily offline news pack.
 *
 * GET ?date=today&lang=hi&state_id=9
 * GET ?date=2025-01-15&lang=hi
 *
 * Response:
 * {
 *   "success": true,
 *   "pack_date": "2025-01-15", "articles_count": 50,
 *   "size_kb": 245, "download_url": "https://...",
 *   "expires_at": "2025-01-16"
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$date_param = trim($_GET['date'] ?? 'today');
$lang       = strtolower(trim($_GET['lang'] ?? 'hi'));
$state_id   = filter_var($_GET['state_id'] ?? '', FILTER_VALIDATE_INT);

// Resolve date
if ($date_param === 'today') {
    $pack_date = date('Y-m-d');
} elseif ($date_param === 'yesterday') {
    $pack_date = date('Y-m-d', strtotime('-1 day'));
} else {
    $dt = DateTime::createFromFormat('Y-m-d', $date_param);
    if (!$dt || $dt->format('Y-m-d') !== $date_param) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD, "today", or "yesterday"']);
        exit;
    }
    $pack_date = $date_param;
}

// Build query
if ($state_id !== false && $state_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM offline_packs
         WHERE pack_date = ? AND language_code = ? AND state_id = ? AND is_ready = 1
         LIMIT 1'
    );
    $stmt->execute([$pack_date, $lang, $state_id]);
} else {
    $stmt = $pdo->prepare(
        'SELECT * FROM offline_packs
         WHERE pack_date = ? AND language_code = ? AND state_id IS NULL AND is_ready = 1
         LIMIT 1'
    );
    $stmt->execute([$pack_date, $lang]);
}

$pack = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pack) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Pack not available for this date/language. Packs are generated daily at 5am.']);
    exit;
}

$expires = date('Y-m-d', strtotime($pack_date . ' +1 day'));

echo json_encode([
    'success'        => true,
    'pack_date'      => $pack['pack_date'],
    'language_code'  => $pack['language_code'],
    'state_id'       => $pack['state_id'],
    'articles_count' => (int)$pack['articles_count'],
    'size_kb'        => (int)$pack['pack_size_kb'],
    'download_url'   => $pack['pack_url'],
    'expires_at'     => $expires,
]);
