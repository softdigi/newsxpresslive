<?php
/**
 * helpers/security_headers.php
 *
 * FIX 6: CSP per-context (replaces the previous one-size-fits-all policy).
 *
 * Different entry points serve different content:
 *   'api'     — Pure JSON API. No HTML, no scripts, no resources needed.
 *               Strictest possible policy.
 *   'admin'   — Admin panel: loads CDN fonts, Chart.js, inline styles.
 *               Loosened only where absolutely required.
 *   'web'     — Public-facing news pages: loads CDN images, Google Fonts,
 *               AdSense, YouTube embeds.
 *   'default' — Conservative baseline for anything else.
 *
 * Usage (call once before any output):
 *   require_once __DIR__ . '/../helpers/security_headers.php';
 *   setSecurityHeaders('api');     // in web/api/*.php
 *   setSecurityHeaders('admin');   // in admin_panel/*.php
 *   setSecurityHeaders('web');     // in news/detail.php etc.
 */

function setSecurityHeaders(string $context = 'default'): void
{
    // ── Universal headers (apply regardless of context) ─────────────────
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // ── Content-Security-Policy (per-context) ───────────────────────────
    switch ($context) {
        case 'api':
            // API responses are pure JSON — no scripts, styles, or resources.
            // The most restrictive policy possible.
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
            break;

        case 'admin':
            // Admin panel uses:
            //   - Inline scripts for initialisation (nonce preferred but not yet set up)
            //   - Google Fonts (fonts.googleapis.com + fonts.gstatic.com)
            //   - Chart.js / CDN assets via cdnjs.cloudflare.com
            //   - Firebase Auth SDK
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://www.gstatic.com https://cdnjs.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: blob: https:",
                "connect-src 'self' https://*.googleapis.com https://*.firebaseio.com",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
            header("Content-Security-Policy: $csp");
            break;

        case 'web':
            // Public news pages use:
            //   - Google Fonts, AdSense, YouTube embeds, social share widgets
            //   - Uploaded images from our CDN / uploads path
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://pagead2.googlesyndication.com https://www.googletagmanager.com https://www.gstatic.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: blob: https:",
                "frame-src https://www.youtube.com https://www.youtube-nocookie.com https://googleads.g.doubleclick.net",
                "connect-src 'self' https://*.googleapis.com",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
            header("Content-Security-Policy: $csp");
            break;

        default:
            // Conservative baseline: same-origin resources only.
            header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
            break;
    }
}

