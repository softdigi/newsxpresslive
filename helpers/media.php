<?php
/**
 * helpers/media.php — Media Optimization Layer
 *
 * Responsibilities:
 *   1. Validate an uploaded image (PHP errors, MIME, file size)
 *   2. Convert to WebP format using PHP GD
 *   3. Generate 3 size variants:
 *        thumbnail — 300 × 200 px, center-cropped  (news cards / previews)
 *        medium    — max 800 px wide, proportional  (article detail)
 *        original  — original dimensions, WebP only (lightbox / full view)
 *   4. Prefix all URLs with the Cloudflare CDN base URL when configured
 *   5. Return a structured array suitable for JSON storage + API responses
 *
 * Usage:
 *   require_once __DIR__ . '/media.php';
 *   $result = processUploadedImage($_FILES['image'], 'news/images');
 *   if (isset($result['error'])) { ... handle error ... }
 *   // $result['image_sizes']  → ['thumbnail'=>url, 'medium'=>url, 'original'=>url]
 *   // $result['primary_url']  → medium URL (backward-compat single image field)
 *   // $result['lazy_load']    → true (hint for client-side lazy loading)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/cdn.php';

// ── Size constants ────────────────────────────────────────────────────────────

/** Thumbnail: fixed 300 × 200, center-cropped */
const MEDIA_THUMB_W   = 300;
const MEDIA_THUMB_H   = 200;

/** Medium: max 800 px wide, height proportional */
const MEDIA_MEDIUM_W  = 800;

/** WebP encoding quality (0 – 100) */
const MEDIA_WEBP_QUALITY = 85;

/** Maximum accepted upload size: 10 MB */
const MEDIA_MAX_BYTES = 10 * 1024 * 1024;

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Process an uploaded image: validate → resize × 3 → save as WebP → return URLs.
 *
 * @param  array  $file    Single entry from $_FILES, e.g. $_FILES['image']
 * @param  string $folder  Logical sub-folder, e.g. 'news/images'
 * @return array  Success: ['success'=>true, 'image_sizes'=>[...], 'primary_url'=>string, 'lazy_load'=>true]
 *                Failure: ['error'=>string]
 */
function processUploadedImage(array $file, string $folder): array
{
    // ── 1. PHP upload error ───────────────────────────────────────────────────
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension',
        ];
        return ['error' => $messages[$file['error']] ?? 'Unknown upload error'];
    }

    // ── 2. MIME type — read from file content, never trust client header ──────
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return ['error' => 'Invalid image type — only JPG, PNG, WebP, GIF allowed'];
    }

    // ── 3. File size ──────────────────────────────────────────────────────────
    if ($file['size'] > MEDIA_MAX_BYTES) {
        return ['error' => 'Image too large (max 10 MB)'];
    }

    // ── 4. GD availability ────────────────────────────────────────────────────
    if (!function_exists('imagecreatetruecolor')) {
        return ['error' => 'GD library not available on this server'];
    }

    // ── 5. Load into GD ───────────────────────────────────────────────────────
    $src = _mediaLoadGd($file['tmp_name'], $mime);
    if ($src === false) {
        return ['error' => 'Could not decode image — file may be corrupt'];
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // ── 6. Sanitize folder, build base filename ───────────────────────────────
    $safeFolder = preg_replace('/[^a-zA-Z0-9\/_-]/', '', trim(trim($folder), '/'));
    $baseName   = 'img_' . uniqid('', true) . '_' . bin2hex(random_bytes(4));

    // ── 7. Size definitions: [targetW, targetH, crop] ────────────────────────
    //   height = 0 means proportional resize (no fixed height)
    $sizeDefs = [
        'thumbnail' => [MEDIA_THUMB_W,  MEDIA_THUMB_H, true],
        'medium'    => [MEDIA_MEDIUM_W, 0,             false],
        'original'  => [$srcW,          $srcH,         false],
    ];

    // ── 8. Generate each size, save as WebP ──────────────────────────────────
    $urls        = [];
    $resizedPool = []; // track extra GD resources for cleanup

    foreach ($sizeDefs as $sizeName => [$targetW, $targetH, $crop]) {

        if ($sizeName === 'original') {
            // No resize — convert source as-is
            $canvas = $src;
        } else {
            $canvas = _mediaResize($src, $srcW, $srcH, $targetW, $targetH, $crop);
            if ($canvas === false) {
                _mediaCleanup($src, $resizedPool);
                return ['error' => "Failed to resize image for size: {$sizeName}"];
            }
            $resizedPool[] = $canvas;
        }

        // Create output directory
        $subDir = __DIR__ . '/../uploads/' . $safeFolder . '/' . $sizeName . '/';
        if (!is_dir($subDir) && !mkdir($subDir, 0755, true)) {
            _mediaCleanup($src, $resizedPool);
            return ['error' => "Failed to create upload directory for size: {$sizeName}"];
        }

        $filename  = $baseName . '.webp';
        $fullPath  = $subDir . $filename;
        $webPath   = '/uploads/' . $safeFolder . '/' . $sizeName . '/' . $filename;

        if (!imagewebp($canvas, $fullPath, MEDIA_WEBP_QUALITY)) {
            _mediaCleanup($src, $resizedPool);
            return ['error' => "Failed to save WebP image for size: {$sizeName}"];
        }

        $urls[$sizeName] = cdnUrl($webPath);
    }

    // ── 9. Cleanup GD resources ───────────────────────────────────────────────
    _mediaCleanup($src, $resizedPool);

    return [
        'success'     => true,
        'image_sizes' => $urls,
        'primary_url' => $urls['medium'],  // medium as primary (backward compat)
        'lazy_load'   => true,
    ];
}

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Load a GD image resource from a file path using its detected MIME type.
 *
 * @return \GdImage|false
 */
function _mediaLoadGd(string $path, string $mime)
{
    $resource = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png'  => imagecreatefrompng($path),
        'image/webp' => imagecreatefromwebp($path),
        'image/gif'  => imagecreatefromgif($path),
        default      => false,
    };

    // Preserve alpha channel for PNG / WebP sources
    if ($resource !== false && in_array($mime, ['image/png', 'image/webp'], true)) {
        imagealphablending($resource, false);
        imagesavealpha($resource, true);
    }

    return $resource;
}

/**
 * Resize (and optionally center-crop) a GD image to the target dimensions.
 *
 * @param  \GdImage $src     Source GD image
 * @param  int      $srcW    Source width
 * @param  int      $srcH    Source height
 * @param  int      $targetW Target width
 * @param  int      $targetH Target height (ignored when $crop=false)
 * @param  bool     $crop    true = center-crop to exact dimensions
 *                           false = proportional scale-down (no upscale)
 * @return \GdImage|false
 */
function _mediaResize($src, int $srcW, int $srcH, int $targetW, int $targetH, bool $crop)
{
    if ($crop) {
        // ── Center-crop to exact targetW × targetH ────────────────────────────
        $srcRatio = $srcW / $srcH;
        $tgtRatio = $targetW / $targetH;

        if ($srcRatio > $tgtRatio) {
            // Source is wider — clip left/right
            $cropH = $srcH;
            $cropW = (int) round($srcH * $tgtRatio);
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Source is taller — clip top/bottom
            $cropW = $srcW;
            $cropH = (int) round($srcW / $tgtRatio);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) / 2);
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        if ($canvas === false) {
            return false;
        }
        // Transparent background (for PNG/WebP with alpha)
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetW - 1, $targetH - 1, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas, $src,
            0, 0,
            $cropX, $cropY,
            $targetW, $targetH,
            $cropW, $cropH
        );
        return $canvas;

    } else {
        // ── Proportional scale-down; never upscale ────────────────────────────
        if ($srcW <= $targetW) {
            // Already within target width — create a copy at original size
            $canvas = imagecreatetruecolor($srcW, $srcH);
            if ($canvas === false) {
                return false;
            }
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopy($canvas, $src, 0, 0, 0, 0, $srcW, $srcH);
            return $canvas;
        }

        $scale  = $targetW / $srcW;
        $newW   = $targetW;
        $newH   = (int) round($srcH * $scale);

        $canvas = imagecreatetruecolor($newW, $newH);
        if ($canvas === false) {
            return false;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newW - 1, $newH - 1, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas, $src,
            0, 0,
            0, 0,
            $newW, $newH,
            $srcW, $srcH
        );
        return $canvas;
    }
}

/**
 * Free GD resources — the source image and any resized canvases.
 *
 * @param \GdImage   $src
 * @param \GdImage[] $pool
 */
function _mediaCleanup($src, array $pool): void
{
    foreach ($pool as $r) {
        if ($r !== false) {
            @imagedestroy($r);
        }
    }
    @imagedestroy($src);
}
