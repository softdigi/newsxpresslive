<?php
/**
 * helpers/moderation_service.php
 *
 * ModerationService — 3-strike content moderation system.
 *
 * Strike rules:
 *   Strike 1 → Warning + article unpublished
 *   Strike 2 → All future articles go to mandatory review queue
 *   Strike 3 → Blue tick revoked + account suspended 30 days
 *
 * Strikes older than 6 months are NOT counted.
 *
 * Usage:
 *   $mod = ModerationService::getInstance($pdo);
 *
 *   // Issue a strike:
 *   $result = $mod->issueStrike($reporterId, $articleId, 'fake_news', 'Fabricated quotes from CM', $adminId);
 *
 *   // Get reporter's active strikes:
 *   $strikes = $mod->getActiveStrikes($reporterId);
 *
 *   // Submit appeal:
 *   $mod->submitAppeal($strikeId, $reporterId, 'The article was based on official press release...');
 */

declare(strict_types=1);

class ModerationService
{
    private static ?self $instance = null;
    private PDO $pdo;

    private const MAX_STRIKES          = 3;
    private const STRIKE_EXPIRY_MONTHS = 6;
    private const SUSPENSION_DAYS      = 30;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getInstance(PDO $pdo): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: issue a strike
    // ─────────────────────────────────────────────────────────────────────────
    public function issueStrike(
        int     $reporterId,
        ?int    $articleId,
        string  $reason,
        string  $details    = '',
        ?int    $adminId    = null
    ): array {
        $this->pdo->beginTransaction();
        try {
            // Count active strikes (within last 6 months)
            $active = $this->countActiveStrikes($reporterId);
            $newStrikeNumber = $active + 1;

            if ($newStrikeNumber > self::MAX_STRIKES) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Maximum strike limit already reached'];
            }

            // Determine consequence
            $consequence = match ($newStrikeNumber) {
                1 => 'warning',
                2 => 'review_queue',
                3 => 'blue_tick_revoked_account_suspended',
                default => 'warning',
            };

            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::STRIKE_EXPIRY_MONTHS . ' months'));

            // Insert strike
            $this->pdo->prepare(
                "INSERT INTO reporter_strikes
                   (reporter_id, article_id, reason, strike_number, consequence, details, issued_by, expires_at)
                 VALUES (:rid, :aid, :reason, :snum, :consequence, :details, :admin, :exp)"
            )->execute([
                ':rid'         => $reporterId,
                ':aid'         => $articleId,
                ':reason'      => $reason,
                ':snum'        => $newStrikeNumber,
                ':consequence' => $consequence,
                ':details'     => $details,
                ':admin'       => $adminId,
                ':exp'         => $expiresAt,
            ]);
            $strikeId = (int)$this->pdo->lastInsertId();

            // Apply consequence
            $this->applyConsequence($reporterId, $articleId, $newStrikeNumber, $strikeId, $adminId);

            // Audit log
            $this->logAction($strikeId, $reporterId, $articleId, 'strike_issued', $adminId,
                "Strike #{$newStrikeNumber}: {$reason}");

            $this->pdo->commit();

            // Send FCM notification
            $this->notifyReporter($reporterId, $newStrikeNumber, $reason);

            return [
                'success'       => true,
                'strike_id'     => $strikeId,
                'strike_number' => $newStrikeNumber,
                'consequence'   => $consequence,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[ModerationService] issueStrike error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: submit appeal
    // ─────────────────────────────────────────────────────────────────────────
    public function submitAppeal(int $strikeId, int $reporterId, string $reason): array
    {
        // Verify the strike belongs to this reporter
        $stmt = $this->pdo->prepare(
            "SELECT id FROM reporter_strikes WHERE id=:sid AND reporter_id=:rid"
        );
        $stmt->execute([':sid' => $strikeId, ':rid' => $reporterId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Strike not found'];
        }

        // Check if appeal already exists
        $existStmt = $this->pdo->prepare("SELECT id, status FROM strike_appeals WHERE strike_id=:sid");
        $existStmt->execute([':sid' => $strikeId]);
        $existing = $existStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return ['success' => false, 'error' => 'Appeal already ' . $existing['status']];
        }

        $this->pdo->prepare(
            "INSERT INTO strike_appeals (strike_id, reporter_id, appeal_reason) VALUES (:sid, :rid, :reason)"
        )->execute([':sid' => $strikeId, ':rid' => $reporterId, ':reason' => $reason]);

        $appealId = (int)$this->pdo->lastInsertId();
        $this->logAction($strikeId, $reporterId, null, 'appeal_submitted', $reporterId, 'Appeal submitted');

        return ['success' => true, 'appeal_id' => $appealId];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: review appeal (admin)
    // ─────────────────────────────────────────────────────────────────────────
    public function reviewAppeal(int $appealId, int $adminId, string $decision, string $notes = ''): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['success' => false, 'error' => 'Invalid decision'];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM strike_appeals WHERE id=:id AND status='pending'");
        $stmt->execute([':id' => $appealId]);
        $appeal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appeal) {
            return ['success' => false, 'error' => 'Appeal not found or already reviewed'];
        }

        $this->pdo->prepare(
            "UPDATE strike_appeals SET status=:s, reviewed_by=:admin, review_notes=:notes, reviewed_at=NOW()
             WHERE id=:id"
        )->execute([':s' => $decision, ':admin' => $adminId, ':notes' => $notes, ':id' => $appealId]);

        if ($decision === 'approved') {
            // Delete the strike
            $this->pdo->prepare("DELETE FROM reporter_strikes WHERE id=:sid")
                ->execute([':sid' => $appeal['strike_id']]);

            // If this was a 3rd strike, check if we need to reinstate account
            $this->maybeReinstate((int)$appeal['reporter_id']);
        }

        $action = $decision === 'approved' ? 'appeal_approved' : 'appeal_rejected';
        $this->logAction(
            (int)$appeal['strike_id'],
            (int)$appeal['reporter_id'],
            null, $action, $adminId, $notes
        );

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: get active strikes for a reporter
    // ─────────────────────────────────────────────────────────────────────────
    public function getActiveStrikes(int $reporterId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, a.status AS appeal_status
             FROM reporter_strikes s
             LEFT JOIN strike_appeals a ON a.strike_id = s.id
             WHERE s.reporter_id=:rid
               AND s.expires_at > NOW()
             ORDER BY s.created_at ASC"
        );
        $stmt->execute([':rid' => $reporterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function countActiveStrikes(int $reporterId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM reporter_strikes
             WHERE reporter_id=:rid AND expires_at > NOW()"
        );
        $stmt->execute([':rid' => $reporterId]);
        return (int)$stmt->fetchColumn();
    }

    private function applyConsequence(int $reporterId, ?int $articleId, int $strikeNum, int $strikeId, ?int $adminId): void
    {
        switch ($strikeNum) {
            case 1:
                // Unpublish the violating article
                if ($articleId) {
                    $this->pdo->prepare("UPDATE articles SET status='unpublished' WHERE id=:aid")
                        ->execute([':aid' => $articleId]);
                    $this->logAction($strikeId, $reporterId, $articleId, 'article_unpublished', $adminId);
                }
                break;

            case 2:
                // Flag reporter for mandatory review on future articles
                $this->pdo->prepare(
                    "UPDATE users SET requires_article_review=1 WHERE id=:uid"
                )->execute([':uid' => $reporterId]);
                $this->logAction($strikeId, $reporterId, $articleId, 'forced_review_queue', $adminId);
                break;

            case 3:
                // Revoke blue tick
                $this->pdo->prepare(
                    "UPDATE blue_tick_assignments SET status='revoked', revoked_at=NOW()
                     WHERE user_id=:uid AND status='active'"
                )->execute([':uid' => $reporterId]);

                // Suspend account for 30 days
                $suspendUntil = date('Y-m-d H:i:s', strtotime('+' . self::SUSPENSION_DAYS . ' days'));
                $this->pdo->prepare(
                    "UPDATE users SET status='suspended', suspended_until=:until WHERE id=:uid"
                )->execute([':until' => $suspendUntil, ':uid' => $reporterId]);

                $this->logAction($strikeId, $reporterId, $articleId, 'blue_tick_revoked', $adminId);
                $this->logAction($strikeId, $reporterId, $articleId, 'account_suspended', $adminId,
                    "Suspended until {$suspendUntil}");
                break;
        }
    }

    private function maybeReinstate(int $reporterId): void
    {
        // If no more active 3rd strikes, unsuspend
        $count = $this->countActiveStrikes($reporterId);
        if ($count < self::MAX_STRIKES) {
            $this->pdo->prepare(
                "UPDATE users SET status='active', suspended_until=NULL
                 WHERE id=:uid AND status='suspended'"
            )->execute([':uid' => $reporterId]);
        }
    }

    private function logAction(int $strikeId, int $reporterId, ?int $articleId, string $actionType, ?int $performedBy, string $notes = ''): void
    {
        $this->pdo->prepare(
            "INSERT INTO moderation_actions
               (strike_id, reporter_id, article_id, action_type, performed_by, notes)
             VALUES (:sid, :rid, :aid, :action, :by, :notes)"
        )->execute([
            ':sid'    => $strikeId,
            ':rid'    => $reporterId,
            ':aid'    => $articleId,
            ':action' => $actionType,
            ':by'     => $performedBy,
            ':notes'  => $notes,
        ]);
    }

    private function notifyReporter(int $reporterId, int $strikeNum, string $reason): void
    {
        try {
            if (!function_exists('sendFCMToUser')) {
                return;
            }
            $titles = [
                1 => '⚠️ Strike Warning',
                2 => '⚠️ Strike 2 — Review Queue',
                3 => '🚫 Strike 3 — Account Action',
            ];
            $bodies = [
                1 => "Your article was removed for: {$reason}. This is your 1st strike.",
                2 => "2nd strike issued. All your future articles will need review.",
                3 => "3rd strike issued. Your Blue Tick has been revoked and account suspended for 30 days.",
            ];
            sendFCMToUser($this->pdo, $reporterId, $titles[$strikeNum] ?? 'Strike Issued', $bodies[$strikeNum] ?? '');
        } catch (Throwable $e) {
            error_log('[ModerationService] FCM error: ' . $e->getMessage());
        }
    }
}
