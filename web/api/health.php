<?php
/**
 * web/api/health.php
 *
 * TIER 4 — DevOps: Health check endpoint
 *
 * Used by load balancers, uptime monitors, and Kubernetes liveness/readiness
 * probes to verify that the application and its dependencies are healthy.
 *
 * GET /api/health          — returns component status
 * GET /api/health?verbose=1 — includes version and build info
 *
 * HTTP status codes:
 *   200  — healthy (all required components up)
 *   503  — degraded (at least one required component is down)
 *
 * Response shape:
 * {
 *   "status":  "ok" | "degraded",
 *   "checks": {
 *     "database": { "status": "ok",   "latency_ms": 3 },
 *     "redis":    { "status": "warn", "message": "unavailable — optional" },
 *     ...
 *   },
 *   "timestamp": "2026-04-11T12:00:00Z"
 * }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/redis.php';

$verbose   = !empty($_GET['verbose']);
$checks    = [];
$degraded  = false;

// ── 1. Database check ─────────────────────────────────────────────────
$t0 = microtime(true);
try {
    $pdo->query('SELECT 1');
    $latencyMs = round((microtime(true) - $t0) * 1000, 1);
    $checks['database'] = ['status' => 'ok', 'latency_ms' => $latencyMs];
} catch (PDOException $e) {
    $checks['database'] = ['status' => 'error', 'message' => 'DB connection failed'];
    $degraded = true;
}

// ── 2. Redis check (optional / non-critical) ──────────────────────────
$t0 = microtime(true);
try {
    $redis = getRedis();
    $pong  = $redis->ping();
    if ($pong === true || $pong === '+PONG') {
        $latencyMs = round((microtime(true) - $t0) * 1000, 1);
        $checks['redis'] = ['status' => 'ok', 'latency_ms' => $latencyMs];
    } else {
        $checks['redis'] = ['status' => 'warn', 'message' => 'ping failed'];
    }
} catch (Exception $e) {
    // Redis is optional — only warn, don't degrade
    $checks['redis'] = ['status' => 'warn', 'message' => 'unavailable (optional)'];
}

// ── 3. Disk write check ───────────────────────────────────────────────
$tmpFile = sys_get_temp_dir() . '/nxl_health_' . getmypid();
try {
    file_put_contents($tmpFile, '1');
    unlink($tmpFile);
    $checks['disk'] = ['status' => 'ok'];
} catch (Exception $e) {
    $checks['disk'] = ['status' => 'warn', 'message' => 'tmp write failed'];
}

// ── 4. PHP version check ──────────────────────────────────────────────
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$checks['php'] = [
    'status'  => $phpOk ? 'ok' : 'warn',
    'version' => PHP_VERSION,
];

// ── Build response ────────────────────────────────────────────────────
$status = $degraded ? 'degraded' : 'ok';
http_response_code($degraded ? 503 : 200);

$body = [
    'status'    => $status,
    'checks'    => $checks,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
];

if ($verbose) {
    $body['app']   = 'NewsXpressLive';
    $body['env']   = getenv('APP_ENV') ?: 'production';
    $body['php']   = PHP_VERSION;
}

echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
