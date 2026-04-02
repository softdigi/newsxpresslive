<?php
/**
 * helpers/security_headers.php
 *
 * FIX 3: Centralized HTTP security headers.
 * Previously NO response (API or admin panel) sent any security headers,
 * leaving clients vulnerable to clickjacking, MIME-sniffing, protocol
 * downgrade, and broad cross-origin information leakage.
 *
 * Call setSecurityHeaders() once at each entry point before any output:
 *   require_once __DIR__ . '/../helpers/security_headers.php';
 *   setSecurityHeaders();
 */

function setSecurityHeaders(): void
{
    // Prevent browsers from guessing a different MIME type than declared.
    header('X-Content-Type-Options: nosniff');

    // Deny embedding in frames — stops clickjacking attacks.
    header('X-Frame-Options: DENY');

    // Force HTTPS for 1 year and apply to all sub-domains.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Limit referrer information to origin only for cross-origin requests.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Restrict resource loading to same origin only.
    // Note: admin panel pages that load CDN assets (fonts, charts) may need
    // to extend this policy — adjust per-page rather than weakening globally.
    header("Content-Security-Policy: default-src 'self'");
}
