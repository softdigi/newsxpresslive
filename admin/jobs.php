<?php
/**
 * admin/jobs.php
 *
 * Admin API: GET /admin/jobs — queue status
 *
 * Response:
 * {
 *   "success": true,
 *   "queues": { "default": { "queued": 5, "processing": 1, "completed": 120, ... }, ... }
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../helpers/security_headers.php';
require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/../helpers/redis.php';

corsHeaders();
setSecurityHeaders('admin');

$adminToken    = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
$expectedToken = getenv('ADMIN_API_TOKEN') ?: '';
if (empty($expectedToken) || !hash_equals($expectedToken, $adminToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

$redis = null;
try { $redis = getRedis(); } catch (Throwable) {}

$queue  = new JobQueue($pdo, $redis);
$status = $queue->getQueueStatus();

echo json_encode(['success' => true, 'queues' => $status]);
