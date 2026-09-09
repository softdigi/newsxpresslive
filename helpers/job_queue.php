<?php
/**
 * helpers/job_queue.php
 *
 * JobQueue — Redis LPUSH/BRPOP based background job system (no Laravel).
 *
 * Architecture:
 *   • Jobs are pushed to Redis list: queue:{queueName}
 *   • Each job is also tracked in MySQL jobs table
 *   • Dead letter queue: queue:dead
 *   • Worker: cron/worker.php (run via supervisor)
 *
 * Usage:
 *   require_once __DIR__ . '/job_queue.php';
 *
 *   // Dispatch a job:
 *   JobQueue::dispatch('EmailJob', ['template' => 'payment_receipt', ...]);
 *
 *   // In worker:
 *   $queue = new JobQueue($pdo, $redis);
 *   $queue->work('default');
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Job Interface
// ─────────────────────────────────────────────────────────────────────────────
interface JobInterface
{
    public function handle(array $payload): void;
}

// ─────────────────────────────────────────────────────────────────────────────
// JobQueue
// ─────────────────────────────────────────────────────────────────────────────
class JobQueue
{
    private PDO    $pdo;
    private        $redis;  // Redis|null
    private string $keyPrefix = 'queue:';

    private const MAX_RETRY_ATTEMPTS = 3;
    private const BACKOFF_BASE_SEC   = 2; // exponential: 2^attempt seconds

    /** @var string[] Map of job class name → FQN (all loaded below) */
    private array $jobRegistry = [
        'PaymentVerificationJob'        => PaymentVerificationJob::class,
        'EmailJob'                      => EmailJob::class,
        'RevenueCalculationJob'         => RevenueCalculationJob::class,
        'DocumentReviewNotificationJob' => DocumentReviewNotificationJob::class,
        'ScoreUpdateJob'                => ScoreUpdateJob::class,
    ];

    public function __construct(PDO $pdo, $redis = null)
    {
        $this->pdo   = $pdo;
        $this->redis = $redis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Static: dispatch (push job)
    // ─────────────────────────────────────────────────────────────────────────
    public static function dispatch(
        string $jobClass,
        array  $payload,
        string $queue = 'default',
        int    $delaySeconds = 0
    ): void {
        global $pdo;
        $redis = null;
        try {
            require_once __DIR__ . '/redis.php';
            $redis = getRedis();
        } catch (Throwable) {}

        $instance = new self($pdo, $redis);
        $instance->push($jobClass, $payload, $queue, $delaySeconds);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Push: add job to queue
    // ─────────────────────────────────────────────────────────────────────────
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delaySeconds = 0): int
    {
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);

        // Log to MySQL
        $this->pdo->prepare(
            "INSERT INTO jobs (job_class, payload, status, queue, available_at)
             VALUES (:class, :payload, 'queued', :queue, :avail)"
        )->execute([
            ':class'   => $jobClass,
            ':payload' => json_encode($payload),
            ':queue'   => $queue,
            ':avail'   => $availableAt,
        ]);
        $jobId = (int)$this->pdo->lastInsertId();

        $envelope = json_encode([
            'id'       => $jobId,
            'class'    => $jobClass,
            'payload'  => $payload,
            'queue'    => $queue,
            'attempts' => 0,
        ]);

        if ($this->redis !== null && $delaySeconds === 0) {
            $this->redis->lPush($this->keyPrefix . $queue, $envelope);
        }
        // Delayed jobs are picked up by worker polling MySQL

        return $jobId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Work: blocking pop loop (run in supervisor)
    // ─────────────────────────────────────────────────────────────────────────
    public function work(string $queue = 'default', int $timeout = 5): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] Worker started on queue: {$queue}\n";

        while (true) {
            $envelope = $this->pop($queue, $timeout);
            if ($envelope === null) {
                // Poll MySQL for delayed jobs
                $this->processDelayedJobs($queue);
                continue;
            }
            $this->process($envelope);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin: queue status
    // ─────────────────────────────────────────────────────────────────────────
    public function getQueueStatus(): array
    {
        $stmt = $this->pdo->query(
            "SELECT queue, status, COUNT(*) AS cnt
             FROM jobs
             GROUP BY queue, status
             ORDER BY queue, status"
        );
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['queue']][$row['status']] = (int)$row['cnt'];
        }

        // Redis queue lengths
        if ($this->redis !== null) {
            foreach (['default', 'email', 'scores', 'dead'] as $q) {
                $result[$q]['redis_length'] = (int)$this->redis->lLen($this->keyPrefix . $q);
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function pop(string $queue, int $timeout): ?array
    {
        if ($this->redis !== null) {
            $raw = $this->redis->brPop($this->keyPrefix . $queue, $timeout);
            if ($raw && isset($raw[1])) {
                return json_decode($raw[1], true);
            }
            return null;
        }

        // MySQL fallback polling
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM jobs
             WHERE queue=:q AND status='queued' AND available_at <= :now
             ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $this->pdo->beginTransaction();
        $stmt->execute([':q' => $queue, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->pdo->commit();
            sleep(1);
            return null;
        }
        $this->pdo->prepare("UPDATE jobs SET status='processing', reserved_at=NOW() WHERE id=:id")
            ->execute([':id' => $row['id']]);
        $this->pdo->commit();

        return [
            'id'       => $row['id'],
            'class'    => $row['job_class'],
            'payload'  => json_decode($row['payload'], true),
            'attempts' => $row['attempts'],
        ];
    }

    private function process(array $envelope): void
    {
        $jobId    = (int)($envelope['id'] ?? 0);
        $class    = $envelope['class']   ?? '';
        $payload  = $envelope['payload'] ?? [];
        $attempts = (int)($envelope['attempts'] ?? 0) + 1;

        $this->pdo->prepare(
            "UPDATE jobs SET status='processing', attempts=:a, reserved_at=NOW() WHERE id=:id"
        )->execute([':a' => $attempts, ':id' => $jobId]);

        try {
            $fqn = $this->jobRegistry[$class] ?? null;
            if (!$fqn || !class_exists($fqn)) {
                throw new RuntimeException("Unknown job class: {$class}");
            }
            $job = new $fqn($this->pdo, $this->redis);
            $job->handle($payload);

            $this->pdo->prepare(
                "UPDATE jobs SET status='completed', completed_at=NOW() WHERE id=:id"
            )->execute([':id' => $jobId]);

            echo "[" . date('H:i:s') . "] ✓ {$class} #{$jobId}\n";

        } catch (Throwable $e) {
            $errMsg = $e->getMessage();
            error_log("[JobQueue] Job #{$jobId} {$class} attempt {$attempts} failed: {$errMsg}");

            if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                $this->moveToDead($jobId, $envelope, $errMsg);
            } else {
                // Exponential backoff
                $delaySeconds = (int)pow(self::BACKOFF_BASE_SEC, $attempts);
                $nextAvail    = date('Y-m-d H:i:s', time() + $delaySeconds);
                $this->pdo->prepare(
                    "UPDATE jobs SET status='queued', available_at=:avail, error_message=:err WHERE id=:id"
                )->execute([':avail' => $nextAvail, ':err' => $errMsg, ':id' => $jobId]);

                if ($this->redis !== null) {
                    $envelope['attempts'] = $attempts;
                    $this->redis->lPush($this->keyPrefix . ($envelope['queue'] ?? 'default'),
                        json_encode($envelope));
                }
            }
        }
    }

    private function moveToDead(int $jobId, array $envelope, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status='dead', error_message=:err WHERE id=:id"
        )->execute([':err' => $error, ':id' => $jobId]);

        if ($this->redis !== null) {
            $envelope['error'] = $error;
            $this->redis->lPush($this->keyPrefix . 'dead', json_encode($envelope));
        }

        error_log("[JobQueue] Job #{$jobId} {$envelope['class']} moved to dead letter queue");
    }

    private function processDelayedJobs(string $queue): void
    {
        // Pick up delayed MySQL jobs that are now available
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT id, job_class, payload, attempts FROM jobs
             WHERE queue=:q AND status='queued' AND available_at <= :now
             ORDER BY available_at ASC LIMIT 10"
        );
        $stmt->execute([':q' => $queue, ':now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ($this->redis !== null) {
                $envelope = json_encode([
                    'id'       => $row['id'],
                    'class'    => $row['job_class'],
                    'payload'  => json_decode($row['payload'], true),
                    'attempts' => $row['attempts'],
                ]);
                $this->redis->lPush($this->keyPrefix . $queue, $envelope);
            } else {
                $this->process([
                    'id'       => $row['id'],
                    'class'    => $row['job_class'],
                    'payload'  => json_decode($row['payload'], true),
                    'attempts' => $row['attempts'],
                ]);
            }
        }
    }
}

// =============================================================================
// Concrete Job Classes
// =============================================================================

// ── Job 1: PaymentVerificationJob ────────────────────────────────────────────
class PaymentVerificationJob implements JobInterface
{
    public function __construct(private PDO $pdo, private $redis = null) {}

    public function handle(array $payload): void
    {
        $orderId   = $payload['order_id']   ?? '';
        $paymentId = $payload['payment_id'] ?? '';
        $userId    = (int)($payload['user_id'] ?? 0);

        if (!$orderId || !$paymentId || !$userId) {
            throw new InvalidArgumentException('Missing payment fields');
        }

        // Verify payment signature / status with Razorpay
        $keyId     = getenv('RAZORPAY_KEY_ID')     ?: '';
        $keySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';

        $ch = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$keyId}:{$keySecret}",
        ]);
        $resp   = json_decode((string)curl_exec($ch), true);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200 && ($resp['status'] ?? '') === 'captured') {
            $this->pdo->prepare(
                "UPDATE blue_tick_purchases SET payment_status='verified' WHERE razorpay_payment_id=:pid"
            )->execute([':pid' => $paymentId]);
        } else {
            throw new RuntimeException("Payment {$paymentId} not captured (status: " . ($resp['status'] ?? 'unknown') . ")");
        }
    }
}

// ── Job 2: EmailJob ───────────────────────────────────────────────────────────
class EmailJob implements JobInterface
{
    public function __construct(private PDO $pdo, private $redis = null) {}

    public function handle(array $payload): void
    {
        require_once __DIR__ . '/email_service.php';
        $service = EmailService::getInstance($this->pdo);
        $ok = $service->sendNow(
            (int)($payload['log_id']  ?? 0),
            $payload['template'] ?? '',
            $payload['to_email'] ?? '',
            $payload['to_name']  ?? '',
            $payload['vars']     ?? []
        );
        if (!$ok) {
            throw new RuntimeException('Email send failed');
        }
    }
}

// ── Job 3: RevenueCalculationJob ──────────────────────────────────────────────
class RevenueCalculationJob implements JobInterface
{
    public function __construct(private PDO $pdo, private $redis = null) {}

    public function handle(array $payload): void
    {
        require_once __DIR__ . '/wallet_transaction.php';
        $reporterUserId = (int)($payload['reporter_user_id'] ?? 0);
        $agencyUserId   = (int)($payload['agency_user_id']   ?? 0);
        $grossAmount    = (float)($payload['gross_amount']   ?? 0);
        $description    = $payload['description']            ?? 'Article revenue';

        if ($reporterUserId <= 0 || $grossAmount <= 0) {
            throw new InvalidArgumentException('Invalid revenue payload');
        }

        $result = WalletTransactionManager::processReporterPayout(
            $this->pdo,
            $reporterUserId,
            $agencyUserId ?: null,
            $grossAmount,
            $description
        );

        if (!$result['success']) {
            throw new RuntimeException('Revenue calculation failed: ' . ($result['error'] ?? 'Unknown'));
        }
    }
}

// ── Job 4: DocumentReviewNotificationJob ──────────────────────────────────────
class DocumentReviewNotificationJob implements JobInterface
{
    public function __construct(private PDO $pdo, private $redis = null) {}

    public function handle(array $payload): void
    {
        $userId   = (int)($payload['user_id']   ?? 0);
        $status   = $payload['status']           ?? '';  // 'approved' | 'rejected'
        $template = $status === 'approved' ? 'verification_approved' : 'verification_rejected';

        $stmt = $this->pdo->prepare("SELECT name, email FROM users WHERE id=:uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException("User {$userId} not found");
        }

        require_once __DIR__ . '/email_service.php';
        $email  = EmailService::getInstance($this->pdo);
        $vars   = array_merge(['reporter_name' => $user['name']], $payload['vars'] ?? []);
        $ok     = $email->send($template, $user['email'], $user['name'], $vars, $userId);

        if (!$ok) {
            throw new RuntimeException('Document review notification email failed');
        }
    }
}

// ── Job 5: ScoreUpdateJob ─────────────────────────────────────────────────────
class ScoreUpdateJob implements JobInterface
{
    public function __construct(private PDO $pdo, private $redis = null) {}

    public function handle(array $payload): void
    {
        require_once __DIR__ . '/credibility_score.php';
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id required');
        }
        CredibilityScoreService::recalculateOne($this->pdo, $userId);
    }
}
