<?php
/**
 * config/cdn.php — Cloudflare CDN Configuration
 *
 * Set the CDN_BASE_URL environment variable to your Cloudflare zone domain,
 * e.g. https://cdn.yourdomain.com
 *
 * Leave CDN_BASE_URL unset (or empty) to serve images from the local origin.
 * All helpers that build media URLs call cdnUrl() from this file.
 */

declare(strict_types=1);

// CDN base URL — no trailing slash; empty string = serve from origin
define('CDN_BASE_URL', rtrim((string)(getenv('CDN_BASE_URL') ?: ''), '/'));

/**
 * Prefix a local web path with the CDN base URL when configured.
 *
 * @param  string $localPath  Absolute web path, e.g. /uploads/news/images/medium/img_xxx.webp
 * @return string             CDN URL or original path if CDN not configured
 */
function cdnUrl(string $localPath): string
{
    if (CDN_BASE_URL !== '') {
        return CDN_BASE_URL . $localPath;
    }
    return $localPath;
}
