<?php
/**
 * helpers/cors.php
 *
 * FIX 2: CORS wildcard restrict.
 *
 * Previously every API file sent:
 *   header('Access-Control-Allow-Origin: *');
 * This allows any origin to make cross-origin requests and read
 * credentialed responses — a direct violation of the principle of
 * least privilege and potentially exposing authenticated data.
 *
 * This helper restricts CORS to an explicit allow-list read from the
 * ALLOWED_ORIGINS environment variable (comma-separated).  Only
 * origins that match the list receive the Access-Control-Allow-Origin
 * header.  Unknown origins receive no CORS header, so browsers block
 * the cross-origin read.
 *
 * Usage (replace `header('Access-Control-Allow-Origin: *');` in every
 * API file):
 *   require_once __DIR__ . '/../../helpers/cors.php';
 *   corsHeaders();
 *
 * Environment variable:
 *   ALLOWED_ORIGINS=https://yourdomain.com,https://admin.yourdomain.com
 *   (if unset the function falls back to SITE_URL so existing deployments
 *   are not broken immediately)
 */

function corsHeaders(array $allowed_methods = ['GET', 'POST', 'OPTIONS']): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Build the allow-list from the environment variable.
    // Fall back to SITE_URL constant (defined in config.php) so that
    // existing single-domain deployments need no extra configuration.
    $raw = getenv('ALLOWED_ORIGINS') ?: (defined('SITE_URL') ? SITE_URL : '');
    $allowed = array_filter(array_map('trim', explode(',', $raw)));

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: ' . implode(', ', $allowed_methods));
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }
    // Requests from unknown origins get no CORS header — browser blocks them.

    // Handle pre-flight OPTIONS without executing any DB logic.
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
