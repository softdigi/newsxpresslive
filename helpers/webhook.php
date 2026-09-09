<?php
/**
 * helpers/webhook.php
 *
 * HMAC signature verification for payment-gateway webhooks.
 * Import this file in every webhook endpoint before processing payload.
 *
 * Usage (Razorpay):
 *   require_once __DIR__ . '/../helpers/webhook.php';
 *   $payload = file_get_contents('php://input');
 *   $sig     = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
 *   if (!verifyRazorpayWebhook($payload, $sig)) {
 *       http_response_code(403); exit('Invalid signature');
 *   }
 *
 * Usage (Stripe):
 *   $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
 *   if (!verifyStripeWebhook($payload, $sig)) {
 *       http_response_code(403); exit('Invalid signature');
 *   }
 */

declare(strict_types=1);

/**
 * Verify a Razorpay webhook signature.
 *
 * Razorpay signs the raw request body with HMAC-SHA256 using the webhook
 * secret and sends the hex digest in the X-Razorpay-Signature header.
 *
 * @param string $payload   Raw request body (file_get_contents('php://input'))
 * @param string $signature Value of HTTP_X_RAZORPAY_SIGNATURE header
 * @return bool             true if the signature is valid
 */
function verifyRazorpayWebhook(string $payload, string $signature): bool
{
    $secret = getenv('RAZORPAY_WEBHOOK_SECRET');
    if (!$secret || !$signature) {
        return false;
    }
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Verify a Stripe webhook signature.
 *
 * Stripe sends a Stripe-Signature header of the form:
 *   t=<timestamp>,v1=<hex_digest>[,v1=<hex_digest>]
 * The signed payload is "<timestamp>.<raw_body>".
 *
 * @param string $payload   Raw request body (file_get_contents('php://input'))
 * @param string $signature Value of HTTP_STRIPE_SIGNATURE header
 * @return bool             true if at least one v1 digest matches
 */
function verifyStripeWebhook(string $payload, string $signature): bool
{
    $secret = getenv('STRIPE_WEBHOOK_SECRET');
    if (!$secret || !$signature) {
        return false;
    }

    $parts     = explode(',', $signature);
    $timestamp = '';
    $sigs      = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if (str_starts_with($part, 't=')) {
            $timestamp = substr($part, 2);
        } elseif (str_starts_with($part, 'v1=')) {
            $sigs[] = substr($part, 3);
        }
    }

    if ($timestamp === '' || empty($sigs)) {
        return false;
    }

    $signed   = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);

    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}
