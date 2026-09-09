<?php
/**
 * web/api/share/og_image.php
 * Generate OG image for article sharing (WhatsApp, Instagram, etc.)
 * GET ?article_id=42&lang=hi
 *
 * Requires GD library: php-gd
 * Output: image/png (1200x630)
 * Cached in Redis for 24 hours
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../helpers/cache.php';

$article_id = (int)($_GET['article_id'] ?? 0);
$lang = preg_replace('/[^a-z]/', '', strtolower($_GET['lang'] ?? 'en'));

if ($article_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'article_id required']);
    exit;
}

$cache_key = "og_image:{$article_id}:{$lang}";

// Check Redis cache
$cache = ApiCache::getInstance();
$cached_path = $cache->get($cache_key);

if ($cached_path && file_exists($cached_path)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('X-Cache: HIT');
    readfile($cached_path);
    exit;
}

// Fetch article
$stmt = $pdo->prepare("SELECT n.*, u.display_name as reporter_name, c.name as category_name
    FROM news n
    LEFT JOIN users u ON u.uid = n.reporter_uid
    LEFT JOIN categories c ON c.id = n.category_id
    WHERE n.id = ? LIMIT 1");
$stmt->execute([$article_id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Article not found']);
    exit;
}

// Check blue tick
$stmt_bt = $pdo->prepare("SELECT 1 FROM blue_tick_assignments WHERE user_uid = ? AND is_active = 1 LIMIT 1");
$stmt_bt->execute([$article['reporter_uid'] ?? '']);
$has_blue_tick = (bool)$stmt_bt->fetchColumn();

// OG image dimensions
$W = 1200;
$H = 630;

$im = imagecreatetruecolor($W, $H);
if (!$im) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'GD library not available']);
    exit;
}

// Colors
$c_bg       = imagecolorallocate($im, 18, 18, 18);
$c_accent   = imagecolorallocate($im, 204, 0, 0);
$c_white    = imagecolorallocate($im, 255, 255, 255);
$c_gray     = imagecolorallocate($im, 180, 180, 180);
$c_yellow   = imagecolorallocate($im, 255, 200, 0);
$c_dark_red = imagecolorallocate($im, 140, 0, 0);

// Background fill
imagefill($im, 0, 0, $c_bg);

// Try to load article thumbnail
$thumb_loaded = false;
if (!empty($article['image_url'])) {
    $img_url = $article['image_url'];
    if (!str_starts_with($img_url, 'http')) {
        $img_url = SITE_URL . '/' . ltrim($img_url, '/');
    }
    $img_data = @file_get_contents($img_url, false, stream_context_create(['http' => ['timeout' => 3]]));
    if ($img_data) {
        $thumb_src = @imagecreatefromstring($img_data);
        if ($thumb_src) {
            // Draw thumbnail on right side (600x630)
            imagecopyresampled($im, $thumb_src, 580, 0, 0, 0, 620, 630, imagesx($thumb_src), imagesy($thumb_src));
            // Gradient overlay on thumbnail edge
            for ($x = 580; $x < 640; $x++) {
                $alpha = (int)(127 * ($x - 580) / 60);
                $col = imagecolorallocatealpha($im, 18, 18, 18, 127 - $alpha);
                imageline($im, $x, 0, $x, $H, $col);
            }
            imagedestroy($thumb_src);
            $thumb_loaded = true;
        }
    }
}

if (!$thumb_loaded) {
    // Gradient background
    for ($y = 0; $y < $H; $y++) {
        $r = (int)(18 + ($y / $H) * 50);
        $col = imagecolorallocate($im, $r, 0, 0);
        imageline($im, 0, $y, $W, $y, $col);
    }
}

// Red accent bar left
imagefilledrectangle($im, 0, 0, 8, $H, $c_accent);

// Platform logo area (top-left)
imagefilledrectangle($im, 20, 20, 220, 65, $c_accent);
imagestring($im, 5, 30, 33, 'NewsXpressLive', $c_white);

// Category badge
$cat = strtoupper(substr($article['category_name'] ?? 'NEWS', 0, 20));
imagefilledrectangle($im, 20, 80, 160, 108, $c_yellow);
imagestring($im, 3, 28, 88, $cat, $c_bg);

// Article title (word-wrap)
$title = $article['title'] ?? 'Breaking News';
if (mb_strlen($title) > 100) {
    $title = mb_substr($title, 0, 97) . '...';
}
$font_size   = 5;
$line_height = 24;
$max_width   = 540;
$words       = explode(' ', $title);
$lines       = [];
$current_line = '';
foreach ($words as $word) {
    $test_line = $current_line ? $current_line . ' ' . $word : $word;
    if (imagefontwidth($font_size) * strlen($test_line) > $max_width) {
        if ($current_line) $lines[] = $current_line;
        $current_line = $word;
    } else {
        $current_line = $test_line;
    }
}
if ($current_line) $lines[] = $current_line;
$lines = array_slice($lines, 0, 6);

$title_y = 130;
foreach ($lines as $line) {
    imagestring($im, $font_size, 20, $title_y, $line, $c_white);
    $title_y += $line_height;
}

// Reporter name + blue tick
$reporter_text = 'By ' . ($article['reporter_name'] ?? 'NewsXpressLive');
if ($has_blue_tick) $reporter_text .= ' ✓';
imagestring($im, 3, 20, $title_y + 20, $reporter_text, $c_gray);

// Date
$date = date('d M Y', strtotime($article['created_at'] ?? 'now'));
imagestring($im, 2, 20, $title_y + 45, $date, $c_gray);

// Bottom bar
imagefilledrectangle($im, 0, $H - 50, $W, $H, $c_dark_red);
imagestring($im, 4, 20, $H - 33, SITE_URL, $c_white);

// QR code placeholder (bottom-right)
imagefilledrectangle($im, $W - 100, $H - 100, $W - 20, $H - 20, $c_white);
imagestring($im, 1, $W - 95, $H - 65, 'SCAN', $c_bg);
imagestring($im, 1, $W - 95, $H - 50, 'TO', $c_bg);
imagestring($im, 1, $W - 95, $H - 35, 'READ', $c_bg);

// Watermark
imagestring($im, 2, $W - 150, 20, 'NewsXpressLive', $c_gray);

// Save to cache dir
$cache_dir = __DIR__ . '/../../../uploads/og_cache/';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}
$cache_file = $cache_dir . "og_{$article_id}_{$lang}.png";
imagepng($im, $cache_file, 6);
imagedestroy($im);

// Cache path in Redis for 24h
$cache->set($cache_key, $cache_file, 86400);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Cache: MISS');
readfile($cache_file);
