<?php
/**
 * helpers/image_cdn.php
 *
 * TIER 2 — Performance: Image CDN helper
 *
 * Transforms raw uploaded image paths into CDN-optimised URLs.
 * Supports three backends (configured via environment variables):
 *
 *   1. Cloudflare Images  (IMAGE_CDN=cloudflare)
 *      Requires: CLOUDFLARE_ACCOUNT_HASH
 *      URL format: https://imagedelivery.net/{hash}/{image_id}/public
 *
 *   2. BunnyCDN Pull Zone (IMAGE_CDN=bunny)
 *      Requires: BUNNY_CDN_URL  e.g. https://nxl.b-cdn.net
 *      URL format: {BUNNY_CDN_URL}/{path}
 *
 *   3. Self-hosted (IMAGE_CDN=none or unset)
 *      Falls back to SITE_URL + /uploads/news/{filename}
 *      No transformation — useful in local development.
 *
 * Usage:
 *   require_once __DIR__ . '/image_cdn.php';
 *   $url = imageUrl('photo.jpg');              // full URL, no resize
 *   $url = imageUrl('photo.jpg', 800, 450);    // Cloudflare resized URL
 *   $url = imageUrl('photo.jpg', 400, 225, 'cover');  // with fit mode
 *
 * @param  string $filename   Stored filename (e.g. "2024/01/photo.jpg")
 * @param  int    $width      Desired width (0 = original)
 * @param  int    $height     Desired height (0 = original)
 * @param  string $fit        Resize mode: cover|contain|crop|pad|scale-down
 * @return string             Absolute image URL
 */
function imageUrl(
    string $filename,
    int    $width  = 0,
    int    $height = 0,
    string $fit    = 'cover'
): string {
    if ($filename === '') return '';

    $cdn = strtolower(getenv('IMAGE_CDN') ?: 'none');

    switch ($cdn) {
        case 'cloudflare':
            return _cloudflareCdnUrl($filename, $width, $height, $fit);

        case 'bunny':
            return _bunnyCdnUrl($filename);

        default:
            return _selfHostedUrl($filename);
    }
}

/**
 * Cloudflare Images URL with optional on-the-fly transforms.
 * Docs: https://developers.cloudflare.com/images/transform-images/
 */
function _cloudflareCdnUrl(string $filename, int $w, int $h, string $fit): string
{
    $accountHash = getenv('CLOUDFLARE_ACCOUNT_HASH') ?: '';
    if ($accountHash === '') {
        return _selfHostedUrl($filename);
    }

    // When using Cloudflare Images direct upload, filename is the image ID.
    // When using Cloudflare Image Resizing on your own origin, we proxy
    // through /cdn-cgi/image/...
    $origin = rtrim(defined('SITE_URL') ? SITE_URL : (getenv('SITE_URL') ?: ''), '/');
    $srcUrl  = $origin . '/uploads/news/' . ltrim($filename, '/');

    $params = [];
    if ($w > 0) $params[] = "width=$w";
    if ($h > 0) $params[] = "height=$h";
    if (!empty($params)) $params[] = "fit=$fit";
    $params[] = 'format=auto';
    $params[] = 'quality=85';

    $paramStr = implode(',', $params);
    return $paramStr
        ? "{$origin}/cdn-cgi/image/{$paramStr}/" . rawurlencode($srcUrl)
        : $srcUrl;
}

/**
 * BunnyCDN Pull Zone URL.
 */
function _bunnyCdnUrl(string $filename): string
{
    $base = rtrim(getenv('BUNNY_CDN_URL') ?: '', '/');
    if ($base === '') return _selfHostedUrl($filename);
    return $base . '/uploads/news/' . ltrim($filename, '/');
}

/**
 * Self-hosted fallback URL.
 */
function _selfHostedUrl(string $filename): string
{
    $base = defined('UPLOADS_URL') ? UPLOADS_URL : (
        rtrim(defined('SITE_URL') ? SITE_URL : (getenv('SITE_URL') ?: ''), '/')
        . '/uploads/news/'
    );
    return rtrim($base, '/') . '/' . ltrim($filename, '/');
}

/**
 * Generate a srcset string for responsive images.
 *
 * @param  string $filename
 * @param  int[]  $widths     Array of widths to generate e.g. [400, 800, 1200]
 * @param  int    $aspectH    Height divisor: height = width / aspectRatio
 * @return string             srcset attribute value
 */
function imageSrcset(string $filename, array $widths = [400, 800, 1200], int $aspectH = 0): string
{
    $parts = [];
    foreach ($widths as $w) {
        $h = $aspectH > 0 ? (int)round($w * $aspectH / $widths[0]) : 0;
        $parts[] = imageUrl($filename, $w, $h) . " {$w}w";
    }
    return implode(', ', $parts);
}
