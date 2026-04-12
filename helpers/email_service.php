<?php
/**
 * helpers/email_service.php
 *
 * EmailService — SendGrid-based transactional email sender.
 *
 * Usage:
 *   $email = EmailService::getInstance($pdo);
 *   $email->send('verification_approved', $recipientEmail, $recipientName, [
 *       'reporter_name' => 'Ravi Kumar',
 *       'plan_name'     => 'Reporter Verified',
 *   ]);
 *
 * Queue-safe: pass $enqueue=true to push to RedisJobQueue instead of sending inline.
 *
 * Environment variables required:
 *   SENDGRID_API_KEY   — your SendGrid API key
 *   MAIL_FROM          — sender address (default: noreply@newsxpresslive.com)
 *   MAIL_FROM_NAME     — sender name   (default: NewsXpressLive)
 */

declare(strict_types=1);

class EmailService
{
    private static ?self $instance = null;
    private PDO $pdo;

    private string $apiKey;
    private string $fromEmail;
    private string $fromName;

    private const SENDGRID_API_URL = 'https://api.sendgrid.com/v3/mail/send';
    private const MAX_RETRIES      = 3;
    private const RETRY_BACKOFF_MS = 500;

    // ─────────────────────────────────────────────────────────────────────────
    // Singleton
    // ─────────────────────────────────────────────────────────────────────────
    private function __construct(PDO $pdo)
    {
        $this->pdo       = $pdo;
        $this->apiKey    = getenv('SENDGRID_API_KEY') ?: '';
        $this->fromEmail = getenv('MAIL_FROM')      ?: 'noreply@newsxpresslive.com';
        $this->fromName  = getenv('MAIL_FROM_NAME') ?: 'NewsXpressLive';
    }

    public static function getInstance(PDO $pdo): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: send an email (sync or enqueue to Redis)
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * @param string $template   One of the template keys defined in getTemplate()
     * @param string $toEmail    Recipient email address
     * @param string $toName     Recipient display name
     * @param array  $vars       Template variable substitutions
     * @param int|null $userId   Internal user ID for logging
     * @param bool   $enqueue    If true, push to background job queue instead
     */
    public function send(
        string  $template,
        string  $toEmail,
        string  $toName,
        array   $vars     = [],
        ?int    $userId   = null,
        bool    $enqueue  = false
    ): bool {
        $toEmail = filter_var($toEmail, FILTER_VALIDATE_EMAIL);
        if ($toEmail === false) {
            error_log("[EmailService] Invalid email address");
            return false;
        }

        // Log the email attempt
        $logId = $this->logEmail($toEmail, $toName, $template, $userId, 'queued');

        if ($enqueue) {
            $this->enqueueJob($logId, $template, $toEmail, $toName, $vars, $userId);
            return true;
        }

        return $this->sendNow($logId, $template, $toEmail, $toName, $vars);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal: send immediately with retry
    // ─────────────────────────────────────────────────────────────────────────
    public function sendNow(
        int    $logId,
        string $template,
        string $toEmail,
        string $toName,
        array  $vars
    ): bool {
        $tpl = $this->getTemplate($template, $vars);
        if ($tpl === null) {
            $this->updateLog($logId, 'failed', null, "Unknown template: {$template}");
            return false;
        }

        $payload = [
            'personalizations' => [[
                'to'      => [['email' => $toEmail, 'name' => $toName]],
                'subject' => $tpl['subject'],
            ]],
            'from'    => ['email' => $this->fromEmail, 'name' => $this->fromName],
            'content' => [['type' => 'text/html', 'value' => $tpl['html']]],
        ];

        $attempt = 0;
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            [$ok, $msgId, $error] = $this->callSendGrid($payload);
            if ($ok) {
                $this->updateLog($logId, 'sent', $msgId, null, $attempt);
                return true;
            }
            if ($attempt < self::MAX_RETRIES) {
                usleep((int)(self::RETRY_BACKOFF_MS * 1000 * $attempt));
            }
        }

        $this->updateLog($logId, 'failed', null, $error, $attempt);
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal: call SendGrid v3 API
    // ─────────────────────────────────────────────────────────────────────────
    private function callSendGrid(array $payload): array
    {
        if (empty($this->apiKey)) {
            return [false, null, 'SENDGRID_API_KEY not configured'];
        }

        $ch = curl_init(self::SENDGRID_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $body    = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [false, null, "cURL error: {$curlErr}"];
        }

        if ($status === 202) {
            // SendGrid returns X-Message-Id header; parse from response body if available
            $decoded = json_decode((string)$body, true);
            $msgId   = $decoded['x-message-id'] ?? null;
            return [true, $msgId, null];
        }

        $decoded = json_decode((string)$body, true);
        $errMsg  = $decoded['errors'][0]['message'] ?? "HTTP {$status}";
        return [false, null, $errMsg];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal: push to background job queue
    // ─────────────────────────────────────────────────────────────────────────
    private function enqueueJob(int $logId, string $template, string $toEmail, string $toName, array $vars, ?int $userId): void
    {
        if (!class_exists('JobQueue')) {
            // Fallback: send inline if queue not available
            $this->sendNow($logId, $template, $toEmail, $toName, $vars);
            return;
        }
        JobQueue::dispatch('EmailJob', [
            'log_id'   => $logId,
            'template' => $template,
            'to_email' => $toEmail,
            'to_name'  => $toName,
            'vars'     => $vars,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function logEmail(
        string  $toEmail,
        string  $toName,
        string  $template,
        ?int    $userId,
        string  $status
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO email_logs (to_email, to_name, subject, template, user_id, status)
             VALUES (:email, :name, :subject, :template, :uid, :status)"
        );
        $tpl = $this->getTemplate($template, []);
        $stmt->execute([
            ':email'    => $toEmail,
            ':name'     => $toName,
            ':subject'  => $tpl['subject'] ?? $template,
            ':template' => $template,
            ':uid'      => $userId,
            ':status'   => $status,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateLog(int $id, string $status, ?string $msgId, ?string $error, int $retries = 1): void
    {
        $this->pdo->prepare(
            "UPDATE email_logs SET status=:s, sendgrid_msg_id=:mid, error_message=:err,
             retry_count=:rc, sent_at=IF(:s2='sent', NOW(), NULL), updated_at=NOW()
             WHERE id=:id"
        )->execute([
            ':s'   => $status,
            ':mid' => $msgId,
            ':err' => $error,
            ':rc'  => $retries,
            ':s2'  => $status,
            ':id'  => $id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Templates (5 HTML email templates — mobile responsive)
    // ─────────────────────────────────────────────────────────────────────────
    public function getTemplate(string $key, array $vars): ?array
    {
        $fn = 'template_' . $key;
        if (!method_exists($this, $fn)) {
            return null;
        }
        return $this->$fn($vars);
    }

    // ── Helper: base HTML wrapper ─────────────────────────────────────────
    private function wrapHtml(string $subject, string $body): array
    {
        $siteUrl  = getenv('SITE_URL') ?: 'https://newsxpresslive.com';
        $year     = date('Y');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>{$subject}</title>
<style>
  body{margin:0;padding:0;background:#f5f5f5;font-family:'Segoe UI',Arial,sans-serif;color:#222;}
  .wrap{max-width:600px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);}
  .header{background:#0d47a1;padding:28px 24px;text-align:center;}
  .header h1{color:#fff;font-size:22px;margin:0;}
  .header p{color:rgba(255,255,255,.8);font-size:13px;margin:4px 0 0;}
  .body{padding:28px 28px 20px;}
  .body p{font-size:15px;line-height:1.7;margin:0 0 14px;}
  .btn{display:inline-block;background:#0d47a1;color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;margin:8px 0;}
  .footer{background:#f9f9f9;padding:16px 24px;text-align:center;font-size:12px;color:#888;}
  .divider{height:1px;background:#eee;margin:18px 0;}
  .badge{display:inline-block;background:#e3f2fd;color:#0d47a1;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;}
  @media(max-width:600px){.wrap{margin:0;border-radius:0;}.body{padding:20px 16px;}}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>NewsXpressLive</h1>
    <p>India's News Reporter Platform</p>
  </div>
  <div class="body">{$body}</div>
  <div class="footer">
    &copy; {$year} NewsXpressLive &nbsp;|&nbsp;
    <a href="{$siteUrl}/legal/privacy.html" style="color:#0d47a1;">Privacy Policy</a> &nbsp;|&nbsp;
    <a href="{$siteUrl}/legal/terms.html" style="color:#0d47a1;">Terms</a><br/>
    SoftDigi Technologies Pvt. Ltd., India
  </div>
</div>
</body>
</html>
HTML;
        return ['subject' => $subject, 'html' => $html];
    }

    // ── Template 1: Verification Approved (Hindi + English) ─────────────
    private function template_verification_approved(array $v): array
    {
        $name    = htmlspecialchars($v['reporter_name'] ?? 'Reporter');
        $plan    = htmlspecialchars($v['plan_name']     ?? 'Blue Tick Verified');
        $siteUrl = getenv('SITE_URL') ?: 'https://newsxpresslive.com';
        $body    = <<<HTML
<p>Dear <strong>{$name}</strong>,</p>
<p>🎉 <strong>Congratulations!</strong> Your Blue Tick verification has been <span class="badge">✅ Approved</span></p>
<p>आपकी Blue Tick वेरिफिकेशन सफलतापूर्वक हो गई है। अब आप NewsXpressLive पर <strong>Verified Reporter</strong> हैं।</p>
<div class="divider"></div>
<p><strong>Plan:</strong> {$plan}<br/>
<strong>Status:</strong> Active ✓</p>
<p>Your verified badge is now live on your profile. Start publishing news with confidence!</p>
<a href="{$siteUrl}" class="btn">Go to Dashboard →</a>
<div class="divider"></div>
<p style="font-size:13px;color:#666;">If you did not request this verification, please contact us immediately at <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></p>
HTML;
        return $this->wrapHtml('✅ Blue Tick Approved — NewsXpressLive', $body);
    }

    // ── Template 2: Verification Rejected ────────────────────────────────
    private function template_verification_rejected(array $v): array
    {
        $name    = htmlspecialchars($v['reporter_name'] ?? 'Reporter');
        $reason  = htmlspecialchars($v['reason']        ?? 'Documents could not be verified.');
        $siteUrl = getenv('SITE_URL') ?: 'https://newsxpresslive.com';
        $body    = <<<HTML
<p>Dear <strong>{$name}</strong>,</p>
<p>We regret to inform you that your Blue Tick verification application has been <span class="badge" style="background:#fce4ec;color:#c62828;">❌ Rejected</span></p>
<div class="divider"></div>
<p><strong>Reason for rejection:</strong></p>
<p style="background:#fff8e1;padding:12px;border-radius:6px;border-left:4px solid #f9a825;">{$reason}</p>
<p>आपका भुगतान (यदि कोई) 5-7 कार्य दिवसों के भीतर वापस कर दिया जाएगा।<br/>
Your payment (if any) will be refunded within 5–7 business days.</p>
<p>You may re-apply with correct documents after 7 days.</p>
<a href="{$siteUrl}/apply" class="btn">Re-Apply →</a>
<div class="divider"></div>
<p style="font-size:13px;color:#666;">Questions? Email <a href="mailto:support@newsxpresslive.com">support@newsxpresslive.com</a></p>
HTML;
        return $this->wrapHtml('Verification Update — NewsXpressLive', $body);
    }

    // ── Template 3: Payment Receipt ───────────────────────────────────────
    private function template_payment_receipt(array $v): array
    {
        $name   = htmlspecialchars($v['reporter_name']  ?? 'Reporter');
        $amount = htmlspecialchars($v['amount']         ?? '₹99');
        $txnId  = htmlspecialchars($v['transaction_id'] ?? 'N/A');
        $plan   = htmlspecialchars($v['plan_name']      ?? 'Blue Tick Verification');
        $date   = htmlspecialchars($v['date']           ?? date('d M Y'));
        $body   = <<<HTML
<p>Dear <strong>{$name}</strong>,</p>
<p>Thank you for your payment. Here is your receipt:</p>
<div class="divider"></div>
<table style="width:100%;border-collapse:collapse;font-size:14px;">
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">Description</td><td style="padding:8px;">{$plan}</td></tr>
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">Amount Paid</td><td style="padding:8px;color:#1b5e20;font-weight:700;">{$amount}</td></tr>
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">Transaction ID</td><td style="padding:8px;font-family:monospace;">{$txnId}</td></tr>
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">Date</td><td style="padding:8px;">{$date}</td></tr>
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">Payment Gateway</td><td style="padding:8px;">Razorpay</td></tr>
  <tr><td style="padding:8px;background:#f5f5f5;font-weight:600;">GST (18%)</td><td style="padding:8px;">Included</td></tr>
</table>
<div class="divider"></div>
<p>Your application is under review. You will receive an update within 5–7 business days.</p>
<p style="font-size:13px;color:#666;">Save this email as your payment proof. For refund queries: <a href="mailto:refunds@newsxpresslive.com">refunds@newsxpresslive.com</a></p>
HTML;
        return $this->wrapHtml("Payment Receipt #{$txnId} — NewsXpressLive", $body);
    }

    // ── Template 4: Weekly Earnings Digest ───────────────────────────────
    private function template_weekly_earnings_digest(array $v): array
    {
        $name      = htmlspecialchars($v['reporter_name']    ?? 'Reporter');
        $earnings  = htmlspecialchars($v['total_earnings']   ?? '₹0.00');
        $articles  = htmlspecialchars($v['articles_count']   ?? '0');
        $views     = htmlspecialchars($v['total_views']      ?? '0');
        $week      = htmlspecialchars($v['week_label']       ?? date('W, Y'));
        $siteUrl   = getenv('SITE_URL') ?: 'https://newsxpresslive.com';
        $body      = <<<HTML
<p>Hi <strong>{$name}</strong>,</p>
<p>Here is your weekly earnings summary for <strong>Week {$week}</strong>:</p>
<div class="divider"></div>
<table style="width:100%;text-align:center;border-collapse:collapse;">
  <tr>
    <td style="padding:16px;background:#e3f2fd;border-radius:8px;">
      <div style="font-size:24px;font-weight:700;color:#0d47a1;">{$earnings}</div>
      <div style="font-size:12px;color:#666;margin-top:4px;">Total Earnings</div>
    </td>
    <td style="padding:16px;background:#e8f5e9;border-radius:8px;margin-left:8px;">
      <div style="font-size:24px;font-weight:700;color:#1b5e20;">{$articles}</div>
      <div style="font-size:12px;color:#666;margin-top:4px;">Articles Published</div>
    </td>
    <td style="padding:16px;background:#fff3e0;border-radius:8px;">
      <div style="font-size:24px;font-weight:700;color:#e65100;">{$views}</div>
      <div style="font-size:12px;color:#666;margin-top:4px;">Total Views</div>
    </td>
  </tr>
</table>
<div class="divider"></div>
<p>Keep publishing great stories to grow your earnings! 🚀</p>
<a href="{$siteUrl}/dashboard" class="btn">View Dashboard →</a>
<p style="font-size:13px;color:#666;margin-top:14px;">To unsubscribe from weekly digests, update your notification preferences in the app.</p>
HTML;
        return $this->wrapHtml("Weekly Earnings Digest — Week {$week}", $body);
    }

    // ── Template 5: Password Reset ────────────────────────────────────────
    private function template_password_reset(array $v): array
    {
        $name    = htmlspecialchars($v['reporter_name'] ?? 'User');
        $link    = $v['reset_link']                     ?? '#';
        $expires = htmlspecialchars($v['expires_in']    ?? '30 minutes');
        $body    = <<<HTML
<p>Hi <strong>{$name}</strong>,</p>
<p>We received a request to reset your NewsXpressLive password. Click the button below to set a new password:</p>
<a href="{$link}" class="btn">Reset Password →</a>
<div class="divider"></div>
<p>This link expires in <strong>{$expires}</strong>.</p>
<p>If you did not request a password reset, you can safely ignore this email. Your password will not change.</p>
<p style="font-size:13px;color:#666;">For security, never share this link with anyone. NewsXpressLive will never ask for your password via email.</p>
HTML;
        return $this->wrapHtml('Reset Your NewsXpressLive Password', $body);
    }
}
