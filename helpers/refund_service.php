<?php
/**
 * helpers/refund_service.php
 *
 * RefundService — Razorpay refund API + dispute handling.
 *
 * Usage:
 *   $refund = RefundService::getInstance($pdo);
 *   $result = $refund->initiateRefund(
 *       razorpayPaymentId: 'pay_xxx',
 *       userId:            42,
 *       amountPaise:       9900,   // ₹99 in paise
 *       reason:            'verification_rejected'
 *   );
 *
 * Environment variables required:
 *   RAZORPAY_KEY_ID     — Razorpay key_id
 *   RAZORPAY_KEY_SECRET — Razorpay key_secret
 */

declare(strict_types=1);

class RefundService
{
    private static ?self $instance = null;
    private PDO    $pdo;
    private string $keyId;
    private string $keySecret;

    private const RAZORPAY_REFUND_URL = 'https://api.razorpay.com/v1/payments/%s/refund';
    private const RAZORPAY_FETCH_URL  = 'https://api.razorpay.com/v1/refunds/%s';

    private function __construct(PDO $pdo)
    {
        $this->pdo       = $pdo;
        $this->keyId     = getenv('RAZORPAY_KEY_ID')     ?: '';
        $this->keySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';
    }

    public static function getInstance(PDO $pdo): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: initiate refund
    // ─────────────────────────────────────────────────────────────────────────
    public function initiateRefund(
        string  $razorpayPaymentId,
        int     $userId,
        int     $amountPaise,
        string  $reason = 'verification_rejected',
        ?string $notes  = null,
        string  $initiatedBy = 'system'
    ): array {
        // Guard: prevent duplicate refunds
        $existing = $this->findExistingRefund($razorpayPaymentId, $reason);
        if ($existing && in_array($existing['status'], ['initiated', 'processed'], true)) {
            return [
                'success' => false,
                'error'   => 'Refund already ' . $existing['status'],
                'refund'  => $existing,
            ];
        }

        // Insert/update refund record in pending state
        $refundId = $this->upsertRefund(
            $razorpayPaymentId, $userId, $amountPaise, $reason, 'initiated', $initiatedBy, $notes
        );

        // Call Razorpay
        [$ok, $rzResponse, $errMsg] = $this->callRazorpayRefund($razorpayPaymentId, $amountPaise, $notes ?? $reason);

        if ($ok) {
            $rzRefundId = $rzResponse['id'] ?? null;
            $this->updateRefundStatus($refundId, 'processed', $rzRefundId, null);

            // Send email confirmation
            $this->sendRefundEmail($userId, $razorpayPaymentId, $amountPaise, $reason);

            return ['success' => true, 'refund_id' => $refundId, 'razorpay_refund_id' => $rzRefundId];
        }

        $this->updateRefundStatus($refundId, 'failed', null, $errMsg);
        return ['success' => false, 'error' => $errMsg, 'refund_id' => $refundId];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: handle Razorpay webhook
    // ─────────────────────────────────────────────────────────────────────────
    public function handleWebhook(array $payload, string $signature, string $webhookSecret): bool
    {
        // Verify signature
        $body = json_encode($payload);
        $expected = hash_hmac('sha256', $body, $webhookSecret);
        if (!hash_equals($expected, $signature)) {
            error_log('[RefundService] Invalid webhook signature');
            return false;
        }

        $event   = $payload['event']  ?? '';
        $entity  = $payload['payload']['refund']['entity']  ?? null;
        $dispute = $payload['payload']['dispute']['entity'] ?? null;

        switch ($event) {
            case 'refund.processed':
                if ($entity) {
                    $this->markRefundProcessed($entity);
                }
                break;

            case 'refund.failed':
                if ($entity) {
                    $rzId = $entity['id'] ?? '';
                    $this->pdo->prepare(
                        "UPDATE refunds SET status='failed', error_message=:err, updated_at=NOW()
                         WHERE razorpay_refund_id=:rid"
                    )->execute([':err' => 'Refund failed (webhook)', ':rid' => $rzId]);
                }
                break;

            case 'payment.dispute.created':
            case 'payment.dispute.under_review':
            case 'payment.dispute.won':
            case 'payment.dispute.lost':
            case 'payment.dispute.closed':
                if ($dispute) {
                    $this->upsertDispute($event, $dispute);
                }
                break;
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: admin — list refunds
    // ─────────────────────────────────────────────────────────────────────────
    public function listRefunds(string $status = 'all', int $page = 1, int $perPage = 20): array
    {
        $where  = $status !== 'all' ? 'WHERE r.status = :status' : '';
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name AS user_name, u.email AS user_email
            FROM refunds r
            LEFT JOIN users u ON u.id = r.user_id
            {$where}
            ORDER BY r.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        if ($status !== 'all') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM refunds r {$where}");
        if ($status !== 'all') $countStmt->bindValue(':status', $status);
        $countStmt->execute();

        return [
            'refunds'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'    => (int)$countStmt->fetchColumn(),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function callRazorpayRefund(string $paymentId, int $amountPaise, string $notes): array
    {
        if (empty($this->keyId) || empty($this->keySecret)) {
            return [false, null, 'Razorpay credentials not configured'];
        }

        $url  = sprintf(self::RAZORPAY_REFUND_URL, $paymentId);
        $body = json_encode(['amount' => $amountPaise, 'notes' => ['reason' => $notes]]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return [false, null, "cURL: {$curlErr}"];

        $decoded = json_decode((string)$resp, true);
        if ($status === 200) {
            return [true, $decoded, null];
        }

        $errMsg = $decoded['error']['description'] ?? "HTTP {$status}";
        return [false, null, $errMsg];
    }

    private function findExistingRefund(string $paymentId, string $reason): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM refunds WHERE razorpay_payment_id=:pid AND reason=:reason ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':pid' => $paymentId, ':reason' => $reason]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function upsertRefund(
        string $paymentId, int $userId, int $amount, string $reason,
        string $status, string $by, ?string $notes
    ): int {
        $this->pdo->prepare(
            "INSERT INTO refunds
               (razorpay_payment_id, user_id, amount_paise, reason, status, initiated_by, notes)
             VALUES (:pid, :uid, :amt, :reason, :status, :by, :notes)
             ON DUPLICATE KEY UPDATE
               status=VALUES(status), initiated_by=VALUES(initiated_by), notes=VALUES(notes)"
        )->execute([
            ':pid'    => $paymentId,
            ':uid'    => $userId,
            ':amt'    => $amount,
            ':reason' => $reason,
            ':status' => $status,
            ':by'     => $by,
            ':notes'  => $notes,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateRefundStatus(int $id, string $status, ?string $rzRefundId, ?string $error): void
    {
        $this->pdo->prepare(
            "UPDATE refunds SET status=:s, razorpay_refund_id=:rid, error_message=:err,
             processed_at=IF(:s2='processed',NOW(),NULL), updated_at=NOW()
             WHERE id=:id"
        )->execute([':s' => $status, ':rid' => $rzRefundId, ':err' => $error, ':s2' => $status, ':id' => $id]);
    }

    private function markRefundProcessed(array $entity): void
    {
        $rzRefundId = $entity['id']         ?? '';
        $paymentId  = $entity['payment_id'] ?? '';
        $this->pdo->prepare(
            "UPDATE refunds SET status='processed', razorpay_refund_id=:rid, processed_at=NOW(), updated_at=NOW()
             WHERE razorpay_payment_id=:pid AND status != 'processed'"
        )->execute([':rid' => $rzRefundId, ':pid' => $paymentId]);
    }

    private function upsertDispute(string $event, array $entity): void
    {
        $statusMap = [
            'payment.dispute.created'      => 'open',
            'payment.dispute.under_review' => 'under_review',
            'payment.dispute.won'          => 'won',
            'payment.dispute.lost'         => 'lost',
            'payment.dispute.closed'       => 'closed',
        ];
        $newStatus = $statusMap[$event] ?? 'open';

        // Find related refund
        $paymentId = $entity['payment_id'] ?? '';
        $refundStmt = $this->pdo->prepare("SELECT id FROM refunds WHERE razorpay_payment_id=:pid LIMIT 1");
        $refundStmt->execute([':pid' => $paymentId]);
        $refundId = $refundStmt->fetchColumn() ?: null;

        $this->pdo->prepare(
            "INSERT INTO disputes
               (razorpay_payment_id, razorpay_dispute_id, refund_id, amount_paise, status, razorpay_payload)
             VALUES (:pid, :did, :rid, :amt, :s, :payload)
             ON DUPLICATE KEY UPDATE
               status=VALUES(status), razorpay_payload=VALUES(razorpay_payload), updated_at=NOW()"
        )->execute([
            ':pid'     => $paymentId,
            ':did'     => $entity['id']     ?? null,
            ':rid'     => $refundId,
            ':amt'     => $entity['amount'] ?? null,
            ':s'       => $newStatus,
            ':payload' => json_encode($entity),
        ]);
    }

    private function sendRefundEmail(int $userId, string $paymentId, int $amountPaise, string $reason): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT name, email FROM users WHERE id=:uid");
            $stmt->execute([':uid' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && class_exists('EmailService')) {
                $email = EmailService::getInstance($this->pdo);
                $email->send('verification_rejected', $user['email'], $user['name'], [
                    'reporter_name' => $user['name'],
                    'reason'        => 'Your verification was rejected. Refund of ₹' .
                                       number_format($amountPaise / 100, 2) . ' initiated.',
                ], $userId);
            }
        } catch (Throwable $e) {
            error_log('[RefundService] Email send failed: ' . $e->getMessage());
        }
    }
}
