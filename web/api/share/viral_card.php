<?php
/**
 * web/api/share/viral_card.php
 * Public API — generate a shareable news image card.
 *
 * GET ?article_id=42&style=1&lang=hi
 *
 * Styles:
 *   1 = Breaking news  (red header)
 *   2 = Feature story  (clean white)
 *   3 = Quote card     (dark background)
 *   4 = Stats card     (numbers prominent)
 *
 * Outputs a PNG image (Content-Type: image/png).
 * Cache: Redis key stores the image path; image file cached on disk for 24h.
 *
 * Requires: GD extension.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
// Allow image embedding cross-origin (OG cards)
header('Access-Control-Allow-Origin: *');
header('Content-Type: image/png');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

if (!extension_loaded('gd')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'GD library not available']);
    exit;
}

$article_id = filter_var($_GET['article_id'] ?? 0, FILTER_VALIDATE_INT);
$style      = max(1, min(4, (int)($_GET['style'] ?? 1)));
$lang       = strtolower(trim($_GET['lang'] ?? 'hi'));

if (!$article_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'article_id required']);
    exit;
}

// ── Cache check ───────────────────────────────────────────────────────────────
$cache_key  = "viral_card:{$article_id}:{$style}:{$lang}";
$cache      = ApiCache::getInstance();
$cache_dir  = __DIR__ . '/../../../uploads/viral_cards';
$cache_file = "{$cache_dir}/{$article_id}_{$style}_{$lang}.png";

if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

// Serve from disk cache if fresh (24h)
if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400) {
    header('X-Cache: HIT');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

// ── Fetch article ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.description, n.thumbnail_url, n.views, n.shares_count,
            n.created_at, c.name AS category_name,
            CONCAT(u.first_name, ' ', u.last_name) AS reporter_name,
            u.is_verified AS reporter_verified
     FROM news n
     LEFT JOIN categories c ON c.id = n.category_id
     LEFT JOIN users u ON u.uid = n.author_uid
     WHERE n.id = ? AND n.status = 'approved'
     LIMIT 1"
);
$stmt->execute([$article_id]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Article not found']);
    exit;
}

// ── Card dimensions ───────────────────────────────────────────────────────────
$W = 1200;
$H = 630;
$img = imagecreatetruecolor($W, $H);

// ── Style palettes ────────────────────────────────────────────────────────────
$palettes = [
    1 => ['bg' => [10, 10, 10],   'header' => [229, 9,  20],  'text' => [255,255,255], 'accent' => [255,200,0]],
    2 => ['bg' => [255,255,255], 'header' => [245,245,245], 'text' => [26, 26, 26],  'accent' => [229,9,20]],
    3 => ['bg' => [18, 18, 31],  'header' => [30, 30, 50],   'text' => [226,226,226], 'accent' => [74, 144,217]],
    4 => ['bg' => [8,  36, 62],  'header' => [0,  90, 170],  'text' => [255,255,255], 'accent' => [255,200,0]],
];

$pal       = $palettes[$style];
$col_bg    = imagecolorallocate($img, ...$pal['bg']);
$col_hdr   = imagecolorallocate($img, ...$pal['header']);
$col_text  = imagecolorallocate($img, ...$pal['text']);
$col_acc   = imagecolorallocate($img, ...$pal['accent']);
$col_grey  = imagecolorallocate($img, 150, 150, 150);
$col_white = imagecolorallocate($img, 255, 255, 255);

// ── Background ────────────────────────────────────────────────────────────────
imagefilledrectangle($img, 0, 0, $W, $H, $col_bg);

// ── Header bar (top 70px) ─────────────────────────────────────────────────────
imagefilledrectangle($img, 0, 0, $W, 70, $col_hdr);

// Site name (logo text)
$font_size = 5; // GD built-in font
imagestring($img, $font_size, 20, 22, 'NewsXpressLive', $col_text);

// Breaking badge for style 1
if ($style === 1) {
    $badge_text = ' BREAKING ';
    $bx = $W - strlen($badge_text) * 9 - 20;
    imagefilledrectangle($img, $bx - 5, 18, $W - 15, 52, $col_acc);
    imagestring($img, $font_size, $bx, 22, $badge_text, imagecolorallocate($img, 0,0,0));
}

// ── Thumbnail (right side, 400×400) ──────────────────────────────────────────
$thumb_x = $W - 420;
$thumb_y = 80;
$thumb_w = 400;
$thumb_h = 400;

$thumb_loaded = false;
if (!empty($article['thumbnail_url'])) {
    $thumb_url = $article['thumbnail_url'];
    // Only load http/https URLs
    if (preg_match('/^https?:\/\//i', $thumb_url)) {
        $ch = curl_init($thumb_url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true]);
        $img_data = curl_exec($ch);
        curl_close($ch);
        if ($img_data) {
            $src = @imagecreatefromstring($img_data);
            if ($src) {
                imagecopyresampled($img, $src, $thumb_x, $thumb_y, 0, 0, $thumb_w, $thumb_h, imagesx($src), imagesy($src));
                imagedestroy($src);
                $thumb_loaded = true;
            }
        }
    }
}
if (!$thumb_loaded) {
    // Placeholder
    imagefilledrectangle($img, $thumb_x, $thumb_y, $thumb_x + $thumb_w, $thumb_y + $thumb_h,
        imagecolorallocate($img, 50, 50, 80));
}

// ── Category badge ────────────────────────────────────────────────────────────
$cat = strtoupper($article['category_name'] ?? 'NEWS');
imagefilledrectangle($img, 20, 85, 20 + strlen($cat) * 9 + 20, 115, $col_acc);
imagestring($img, 4, 30, 91, $cat, imagecolorallocate($img, 0, 0, 0));

// ── Article title (wrapped) ───────────────────────────────────────────────────
$title      = mb_substr(strip_tags($article['title']), 0, 120);
$title_x    = 20;
$title_y    = 130;
$max_width  = $thumb_x - 40;
$char_width = 9; // approx px per char at font 5
$chars_per_line = (int)floor($max_width / $char_width);
$lines = [];
$words = explode(' ', $title);
$line  = '';
foreach ($words as $word) {
    if (strlen($line . ' ' . $word) > $chars_per_line) {
        if ($line !== '') $lines[] = $line;
        $line = $word;
    } else {
        $line = $line === '' ? $word : $line . ' ' . $word;
    }
    if (count($lines) >= 4) break;
}
if ($line !== '' && count($lines) < 4) $lines[] = $line;

foreach ($lines as $i => $l) {
    imagestring($img, 5, $title_x, $title_y + $i * 30, $l, $col_text);
}

// ── Summary (2 lines) ─────────────────────────────────────────────────────────
$summary = mb_substr(strip_tags($article['description'] ?? ''), 0, 200);
$sum_y   = $title_y + count($lines) * 30 + 20;
$sum_chars = (int)floor($max_width / 8);
$sum_lines = str_split($summary, $sum_chars);
foreach (array_slice($sum_lines, 0, 2) as $i => $l) {
    imagestring($img, 3, $title_x, $sum_y + $i * 22, $l, $col_grey);
}

// ── Reporter line ─────────────────────────────────────────────────────────────
$reporter  = $article['reporter_name'] ?? 'NewsXpressLive';
$tick      = !empty($article['reporter_verified']) ? ' ✓' : '';
$rep_y     = $H - 100;
imagestring($img, 3, $title_x, $rep_y, "By {$reporter}{$tick}", $col_grey);

// ── Stats (style 4 specific) ──────────────────────────────────────────────────
if ($style === 4) {
    $views  = number_format((int)($article['views'] ?? 0));
    $shares = number_format((int)($article['shares_count'] ?? 0));
    imagestring($img, 4, $title_x, $rep_y - 35, "👁 {$views} views   🔁 {$shares} shares", $col_acc);
}

// ── Footer bar ────────────────────────────────────────────────────────────────
imagefilledrectangle($img, 0, $H - 55, $W, $H, $col_hdr);
$footer_text = 'Read more on NewsXpressLive  |  ' . SITE_URL;
imagestring($img, 3, 20, $H - 38, $footer_text, $col_text);

// ── Simple QR placeholder (text-based) ───────────────────────────────────────
$qr_label = 'QR: ' . SITE_URL . '/n/' . $article_id;
imagestring($img, 2, $W - strlen($qr_label) * 6 - 20, $H - 38, $qr_label, $col_grey);

// ── Output ────────────────────────────────────────────────────────────────────
header('Cache-Control: public, max-age=86400');
header('X-Cache: MISS');

ob_start();
imagepng($img);
$png_data = ob_get_clean();
imagedestroy($img);

// Save to disk cache
file_put_contents($cache_file, $png_data);

echo $png_data;
