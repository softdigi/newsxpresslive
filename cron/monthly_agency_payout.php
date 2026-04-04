<?php
// ============================================================
// cron/monthly_agency_payout.php
//
// Monthly cron — runs on the 1st of every month.
// Calls process_monthly_payouts() stored procedure, then sends
// FCM push + email notifications to each eligible agency, and
// sends an admin summary report.
//
// Schedule (crontab):
//   0 3 1 * * php /path/to/cron/monthly_agency_payout.php >> /var/log/agency_payout.log 2>&1
//
// Environment variables:
//   FCM_SERVER_KEY   — Firebase Cloud Messaging server key
//   ADMIN_EMAIL      — receives the summary report
//   APP_BASE_URL     — used in notification links
//
// Requires:
//   - process_monthly_payouts() stored procedure
//   - agency_payout_errors table (migration_v4_admob_payout.sql)
//   - agencies.email, agencies.fcm_token columns
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

// The payout period is always the previous month
$payoutMonth = date('Y-m', strtotime('first day of last month'));
$runDate     = date('Y-m-d');   // today (should be the 1st)

echo '[' . ts() . "] Starting monthly payout run for period: {$payoutMonth}" . PHP_EOL;

// ── Step 1: Call process_monthly_payouts() ────────────────────────────────────
try {
    $pdo->exec('CALL process_monthly_payouts()');
    echo '[' . ts() . '] process_monthly_payouts() executed successfully.' . PHP_EOL;
} catch (PDOException $e) {
    logPayoutError($pdo, $runDate, 0, 'SP_FAILURE', $e->getMessage(), null);
    echo '[' . ts() . '] FATAL: process_monthly_payouts() failed — ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ── Step 2: Fetch agencies that received a payout this run ───────────────────
$eligibleAgencies = fetchPayoutRecipients($pdo, $payoutMonth);
echo '[' . ts() . '] Found ' . count($eligibleAgencies) . ' eligible agency payout(s).' . PHP_EOL;

// ── Step 3: Notify each agency (FCM + email) ─────────────────────────────────
$notified     = 0;
$notifyErrors = 0;

foreach ($eligibleAgencies as $agency) {
    try {
        // FCM push notification
        if (!empty($agency['fcm_token'])) {
            $fcmSent = sendFcmNotification(
                $agency['fcm_token'],
                'Monthly Payout Processed! 💰',
                "Your payout of ₹{$agency['payout_amount']} for {$payoutMonth} has been processed.",
                ['type' => 'monthly_payout', 'amount' => $agency['payout_amount'], 'period' => $payoutMonth]
            );
            if (!$fcmSent) {
                echo '[' . ts() . "] WARNING: FCM failed for agency #{$agency['id']}." . PHP_EOL;
            }
        }

        // Email notification
        if (!empty($agency['email'])) {
            sendAgencyPayoutEmail($agency, $payoutMonth);
        }

        $notified++;
    } catch (Throwable $e) {
        $notifyErrors++;
        logPayoutError(
            $pdo,
            $runDate,
            (int)$agency['id'],
            'NOTIFY_FAILURE',
            $e->getMessage(),
            ['agency_name' => $agency['name'], 'payout_amount' => $agency['payout_amount']]
        );
        echo '[' . ts() . "] ERROR notifying agency #{$agency['id']}: " . $e->getMessage() . PHP_EOL;
    }
}

echo '[' . ts() . "] Notifications sent: {$notified} | errors: {$notifyErrors}" . PHP_EOL;

// ── Step 4: Admin summary email ───────────────────────────────────────────────
$adminEmail = getenv('ADMIN_EMAIL') ?: null;
$summary    = [
    'total_agencies'     => count($eligibleAgencies),
    'total_payout_amount'=> array_sum(array_column($eligibleAgencies, 'payout_amount')),
    'notified'           => $notified,
    'notify_errors'      => $notifyErrors,
];

if ($adminEmail) {
    sendAdminPayoutSummary($adminEmail, $payoutMonth, $summary);
    echo '[' . ts() . "] Summary report emailed to {$adminEmail}." . PHP_EOL;
}

echo '[' . ts() . '] Monthly payout run complete.' . PHP_EOL;
exit(0);

// ── Helper functions ──────────────────────────────────────────────────────────

function ts(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Fetch agencies that have pending_payout > 0 for the given month
 * (process_monthly_payouts SP should have set these to 'processed').
 */
function fetchPayoutRecipients(PDO $pdo, string $payoutMonth): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT ag.id, ag.name, ag.email, ag.fcm_token,
                    COALESCE(SUM(aw.amount), 0) AS payout_amount
             FROM   agencies ag
             JOIN   agency_withdrawals aw ON aw.agency_id = ag.id
             WHERE  aw.status = 'processed'
               AND  DATE_FORMAT(aw.created_at, '%Y-%m') = ?
             GROUP  BY ag.id"
        );
        $stmt->execute([$payoutMonth]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        echo '[' . ts() . '] WARNING: fetchPayoutRecipients failed — ' . $e->getMessage() . PHP_EOL;
        return [];
    }
}

/**
 * Send an FCM push notification.
 * Returns true on HTTP 200, false otherwise.
 */
function sendFcmNotification(string $token, string $title, string $body, array $data = []): bool
{
    $serverKey = getenv('FCM_SERVER_KEY');
    if (!$serverKey) {
        return false;
    }

    $payload = json_encode([
        'to'           => $token,
        'notification' => [
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
        ],
        'data' => $data,
    ]);

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $result = json_decode($response, true);
    return isset($result['success']) && $result['success'] > 0;
}

/**
 * Send payout notification email to an individual agency.
 */
function sendAgencyPayoutEmail(array $agency, string $payoutMonth): void
{
    $baseUrl = getenv('APP_BASE_URL') ?: 'https://newsxpresslive.com';
    $subject = "[NewsXpressLive] Your Payout for {$payoutMonth} Has Been Processed";
    $amount  = number_format((float)$agency['payout_amount'], 2);

    $body  = "Dear {$agency['name']},\n\n";
    $body .= "Great news! Your monthly payout for {$payoutMonth} has been processed.\n\n";
    $body .= "Payout amount : ₹{$amount}\n";
    $body .= "Period        : {$payoutMonth}\n\n";
    $body .= "You can view your full revenue and payout history in your agency dashboard:\n";
    $body .= "{$baseUrl}/agency/revenue\n\n";
    $body .= "If you have any questions, please contact our support team.\n\n";
    $body .= "Best regards,\nNewsXpressLive Team\n";

    $headers = implode("\r\n", [
        'From: noreply@newsxpresslive.com',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: NewsXpressLive-Cron/1.0',
    ]);

    @mail($agency['email'], $subject, $body, $headers);
}

/**
 * Send monthly payout summary to admin.
 */
function sendAdminPayoutSummary(string $adminEmail, string $payoutMonth, array $summary): void
{
    $baseUrl = getenv('APP_BASE_URL') ?: 'https://newsxpresslive.com';
    $subject = "[NewsXpressLive] Monthly Agency Payout Summary — {$payoutMonth}";
    $total   = number_format((float)$summary['total_payout_amount'], 2);

    $body  = "Monthly Agency Payout Summary\n";
    $body .= "Period         : {$payoutMonth}\n\n";
    $body .= "Agencies paid  : {$summary['total_agencies']}\n";
    $body .= "Total disbursed: ₹{$total}\n";
    $body .= "Notified       : {$summary['notified']}\n";
    $body .= "Notify errors  : {$summary['notify_errors']}\n\n";
    $body .= "Admin panel    : {$baseUrl}/admin/payouts\n\n";

    if ($summary['notify_errors'] > 0) {
        $body .= "WARNING: {$summary['notify_errors']} notification(s) failed. "
            . "Check agency_payout_errors table for details.\n";
    }

    $headers = implode("\r\n", [
        'From: noreply@newsxpresslive.com',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: NewsXpressLive-Cron/1.0',
    ]);

    @mail($adminEmail, $subject, $body, $headers);
}

/**
 * Insert a row into agency_payout_errors.
 */
function logPayoutError(PDO $pdo, string $runDate, int $agencyId, string $code, string $message, ?array $context): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO agency_payout_errors
                (payout_run_date, agency_id, error_code, error_message, context)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $runDate,
            $agencyId,
            $code,
            $message,
            $context ? json_encode($context) : null,
        ]);
    } catch (PDOException $inner) {
        // Last-resort: write to stderr so cron log captures it
        fwrite(STDERR, '[' . ts() . '] Could not write payout error log: ' . $inner->getMessage() . PHP_EOL);
    }
}
