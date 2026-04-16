<?php
/**
 * web/api/horoscope/today.php
 * Public API — today's daily horoscope for a given zodiac sign.
 *
 * GET ?sign=aries&lang=hi
 *
 * Response:
 * {
 *   "success": true,
 *   "sign": "aries", "sign_hi": "मेष", "date": "Jan 15, 2025",
 *   "content": "...", "lucky_number": 7, "lucky_color": "Laal",
 *   "lucky_time": "10:00 - 11:00 AM",
 *   "scores": { "general": 8, "love": 6, "career": 9, "health": 7 }
 * }
 *
 * Cache: Redis 6 hours (refreshed by cron/fetch_horoscope.php at 6am).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Zodiac sign map ───────────────────────────────────────────────────────────
const ZODIAC_MAP = [
    'aries'       => ['hi' => 'मेष',      'symbol' => '♈'],
    'taurus'      => ['hi' => 'वृषभ',     'symbol' => '♉'],
    'gemini'      => ['hi' => 'मिथुन',    'symbol' => '♊'],
    'cancer'      => ['hi' => 'कर्क',     'symbol' => '♋'],
    'leo'         => ['hi' => 'सिंह',     'symbol' => '♌'],
    'virgo'       => ['hi' => 'कन्या',    'symbol' => '♍'],
    'libra'       => ['hi' => 'तुला',     'symbol' => '♎'],
    'scorpio'     => ['hi' => 'वृश्चिक',  'symbol' => '♏'],
    'sagittarius' => ['hi' => 'धनु',      'symbol' => '♐'],
    'capricorn'   => ['hi' => 'मकर',      'symbol' => '♑'],
    'aquarius'    => ['hi' => 'कुम्भ',    'symbol' => '♒'],
    'pisces'      => ['hi' => 'मीन',      'symbol' => '♓'],
];

$sign = strtolower(trim($_GET['sign'] ?? ''));
$lang = strtolower(trim($_GET['lang'] ?? 'hi'));

if (!isset(ZODIAC_MAP[$sign])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid sign. Valid signs: ' . implode(', ', array_keys(ZODIAC_MAP)),
    ]);
    exit;
}

$today     = date('Y-m-d');
$cache     = ApiCache::getInstance();
$cache_key = "horoscope:{$sign}:{$today}:{$lang}";

$data = $cache->remember($cache_key, 21600, function () use ($pdo, $sign, $today, $lang): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM daily_horoscope
         WHERE zodiac_sign = ? AND horoscope_date = ? AND language_code = ?
         LIMIT 1'
    );
    $stmt->execute([$sign, $today, $lang]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
});

if (!$data) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Horoscope not available yet. Please check back after 6am.']);
    exit;
}

echo json_encode([
    'success'      => true,
    'sign'         => $data['zodiac_sign'],
    'sign_hi'      => $data['zodiac_hi'],
    'symbol'       => ZODIAC_MAP[$sign]['symbol'],
    'date'         => date('M j, Y', strtotime($data['horoscope_date'])),
    'content'      => $data['content'],
    'lucky_number' => $data['lucky_number'] !== null ? (int)$data['lucky_number'] : null,
    'lucky_color'  => $data['lucky_color'],
    'lucky_time'   => $data['lucky_time'],
    'scores'       => [
        'general' => $data['general_score'] !== null ? (int)$data['general_score'] : null,
        'love'    => $data['love_score']    !== null ? (int)$data['love_score']    : null,
        'career'  => $data['career_score']  !== null ? (int)$data['career_score']  : null,
        'health'  => $data['health_score']  !== null ? (int)$data['health_score']  : null,
    ],
]);
