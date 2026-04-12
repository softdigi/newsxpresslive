#!/usr/bin/env php
<?php
/**
 * cron/worker.php
 * NewsXpressLive — Background Job Worker
 *
 * Run via Supervisor (recommended) or nohup:
 *
 *   # supervisor.conf
 *   [program:newsxpress_worker]
 *   command=php /path/to/newsxpresslive/cron/worker.php --queue=default
 *   autostart=true
 *   autorestart=true
 *   numprocs=2
 *   redirect_stderr=true
 *   stdout_logfile=/var/log/newsxpress_worker.log
 *
 * Options:
 *   --queue=default    Queue name to consume (default: 'default')
 *   --timeout=5        BRPOP timeout in seconds
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$opts    = getopt('', ['queue:', 'timeout:']);
$queue   = $opts['queue']   ?? 'default';
$timeout = (int)($opts['timeout'] ?? 5);

$root = dirname(__DIR__);
require_once $root . '/web/includes/config.php';
require_once $root . '/helpers/redis.php';
require_once $root . '/helpers/job_queue.php';
require_once $root . '/helpers/email_service.php';
require_once $root . '/helpers/credibility_score.php';
require_once $root . '/helpers/wallet_transaction.php';

$redis = null;
try {
    $redis = getRedis();
    echo "[" . date('Y-m-d H:i:s') . "] Redis connected\n";
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Redis unavailable — using MySQL polling: {$e->getMessage()}\n";
}

$worker = new JobQueue($pdo, $redis);
$worker->work($queue, $timeout);
