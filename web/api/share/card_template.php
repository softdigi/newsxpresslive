<?php
/**
 * web/api/share/card_template.php
 * Admin endpoint to customize OG card template settings
 * GET  — fetch current template settings
 * POST — update template settings (admin only)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../helpers/cache.php';

corsHeaders(['GET', 'POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

// Check admin auth via session or admin token
session_start();
$is_admin = !empty($_SESSION['admin_logged_in']);
if (!$is_admin) {
    $admin_token = getenv('ADMIN_API_TOKEN');
    $bearer = '';
    $ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($ah, 'Bearer ')) $bearer = substr($ah, 7);
    $is_admin = $admin_token && hash_equals($admin_token, $bearer);
}

// Default template settings
$default_settings = [
    'bg_color'          => '#121212',
    'accent_color'      => '#CC0000',
    'text_color'        => '#FFFFFF',
    'secondary_color'   => '#B4B4B4',
    'font_style'        => 'default',
    'logo_position'     => 'top_left',
    'watermark_opacity' => 80,
    'show_qr'           => true,
    'show_reporter'     => true,
];

$cache = ApiCache::getInstance();
$settings_key = 'og_card_template';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = $cache->get($settings_key);
    if (!$settings) $settings = $default_settings;
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $current = $cache->get($settings_key) ?? $default_settings;

    // Merge and validate
    $hex_fields = ['bg_color', 'accent_color', 'text_color', 'secondary_color'];
    foreach ($hex_fields as $f) {
        if (isset($input[$f]) && preg_match('/^#[0-9A-Fa-f]{6}$/', $input[$f])) {
            $current[$f] = $input[$f];
        }
    }
    if (isset($input['watermark_opacity'])) {
        $current['watermark_opacity'] = max(0, min(100, (int)$input['watermark_opacity']));
    }
    if (isset($input['show_qr'])) {
        $current['show_qr'] = (bool)$input['show_qr'];
    }
    if (isset($input['show_reporter'])) {
        $current['show_reporter'] = (bool)$input['show_reporter'];
    }

    $cache->set($settings_key, $current, 0); // Persist indefinitely

    // Invalidate all cached OG images
    $cache->flush();

    echo json_encode(['success' => true, 'settings' => $current, 'message' => 'Template updated and OG cache cleared']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
