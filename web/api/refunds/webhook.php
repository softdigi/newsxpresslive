<?php
/**
 * web/api/refunds/webhook.php
 *
 * Razorpay Webhook Handler — handles refund.processed, refund.failed,
 * payment.dispute.* events.
 *
 * POST /web/api/refunds/webhook.php
 * Razorpay Dashboard: Add this URL and set a webhook secret.
 *
 * Environment variable: RAZORPAY_WEBHOOK_SECRET
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../helpers/refund_service.php';
require_once __DIR__ . '/../../../helpers/email_service.php';

// Only POST accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawBody  = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$secret    = getenv('RAZORPAY_WEBHOOK_SECRET') ?: '';

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Bad request');
}

$refundService = RefundService::getInstance($pdo);
$ok = $refundService->handleWebhook($payload, $signature, $secret);

http_response_code($ok ? 200 : 400);
echo json_encode(['success' => $ok]);
