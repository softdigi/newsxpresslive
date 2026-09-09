<?php
/**
 * web/api/weather/current.php
 * Public API — current weather + 3-day forecast.
 *
 * GET /web/api/weather/current.php?lat=26.8&lng=80.9
 * GET /web/api/weather/current.php?city=Lucknow
 *
 * Required env var: OPENWEATHER_API_KEY
 *
 * Response: { success, city, temp, feels_like, condition,
 *   condition_hi, humidity, wind_speed, icon,
 *   forecast: [{day, high, low, icon}] }
 * Cache: 30 minutes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$api_key = getenv('OPENWEATHER_API_KEY');
if (!$api_key) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Weather service not configured']);
    exit;
}

// ── Build query params ────────────────────────────────────────────────────────
$city = trim($_GET['city'] ?? '');
$lat  = filter_var($_GET['lat'] ?? '', FILTER_VALIDATE_FLOAT);
$lng  = filter_var($_GET['lng'] ?? '', FILTER_VALIDATE_FLOAT);

if ($city === '' && ($lat === false || $lng === false)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'city or lat+lng required']);
    exit;
}

$location_key = $city !== '' ? 'city_' . preg_replace('/[^a-z0-9]/i', '_', $city)
                             : "coord_{$lat}_{$lng}";
$cache_key    = "weather:current:{$location_key}";
$cache        = ApiCache::getInstance();

/** Hindi translations for common conditions */
$condition_hi_map = [
    'Clear'               => 'साफ़ मौसम',
    'Clouds'              => 'बादल',
    'Rain'                => 'बारिश',
    'Drizzle'             => 'बूंदाबांदी',
    'Thunderstorm'        => 'आंधी-तूफान',
    'Snow'                => 'बर्फ',
    'Mist'                => 'कोहरा',
    'Fog'                 => 'घना कोहरा',
    'Haze'                => 'धुंध',
    'Smoke'               => 'धुआं',
    'Dust'                => 'धूल',
    'Sand'                => 'रेत',
    'Ash'                 => 'राख',
    'Squall'              => 'आंधी',
    'Tornado'             => 'बवंडर',
    'Extreme'             => 'चरम मौसम',
    'Hot'                 => 'गर्म',
    'Cold'                => 'ठंडा',
    'Windy'               => 'हवादार',
    'Partly Cloudy'       => 'आंशिक बादल',
    'Overcast Clouds'     => 'घने बादल',
];

$result = $cache->remember($cache_key, 1800, function () use (
    $api_key, $city, $lat, $lng, $condition_hi_map
): array {
    $base = 'https://api.openweathermap.org/data/2.5';

    // ── Current weather ───────────────────────────────────────────────────
    if ($city !== '') {
        $current_url  = "{$base}/weather?q=" . urlencode($city) . "&appid={$api_key}&units=metric&lang=en";
        $forecast_url = "{$base}/forecast?q=" . urlencode($city) . "&appid={$api_key}&units=metric&cnt=24";
    } else {
        $current_url  = "{$base}/weather?lat={$lat}&lon={$lng}&appid={$api_key}&units=metric&lang=en";
        $forecast_url = "{$base}/forecast?lat={$lat}&lon={$lng}&appid={$api_key}&units=metric&cnt=24";
    }

    $fetch = function (string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($resp && $code === 200) ? json_decode($resp, true) : null;
    };

    $current  = $fetch($current_url);
    $forecast = $fetch($forecast_url);

    if (!$current || empty($current['main'])) {
        return ['found' => false];
    }

    $condition     = $current['weather'][0]['main'] ?? 'Clear';
    $condition_desc = ucfirst($current['weather'][0]['description'] ?? $condition);

    // Build 3-day forecast from 3-hour slots
    $forecast_days = [];
    if ($forecast && !empty($forecast['list'])) {
        $day_data = [];
        foreach ($forecast['list'] as $slot) {
            $day = date('Y-m-d', $slot['dt']);
            if (date('Y-m-d') === $day) continue; // skip today
            if (!isset($day_data[$day])) {
                $day_data[$day] = ['highs' => [], 'lows' => [], 'icon' => $slot['weather'][0]['icon'] ?? '01d'];
            }
            $day_data[$day]['highs'][] = (float)$slot['main']['temp_max'];
            $day_data[$day]['lows'][]  = (float)$slot['main']['temp_min'];
        }
        foreach (array_slice($day_data, 0, 3, true) as $day => $d) {
            $forecast_days[] = [
                'day'  => date('D', strtotime($day)),
                'high' => (int)round(max($d['highs'])),
                'low'  => (int)round(min($d['lows'])),
                'icon' => $d['icon'],
            ];
        }
    }

    return [
        'found'        => true,
        'city'         => $current['name'] ?? ($city ?: 'Unknown'),
        'temp'         => (int)round((float)$current['main']['temp']),
        'feels_like'   => (int)round((float)$current['main']['feels_like']),
        'condition'    => $condition_desc,
        'condition_hi' => $condition_hi_map[$condition] ?? $condition_desc,
        'humidity'     => (int)$current['main']['humidity'],
        'wind_speed'   => round((float)($current['wind']['speed'] ?? 0), 1),
        'icon'         => $current['weather'][0]['icon'] ?? '01d',
        'forecast'     => $forecast_days,
    ];
});

if (empty($result['found'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Location not found']);
    exit;
}

echo json_encode(array_merge(['success' => true], $result));
