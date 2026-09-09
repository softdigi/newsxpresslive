<?php
/**
 * cron/fetch_horoscope.php
 * CLI cron — generate daily horoscope for all 12 zodiac signs via Gemini AI.
 *
 * Run daily at 6am: 0 6 * * * php /path/to/cron/fetch_horoscope.php
 *
 * Required env vars:
 *   GEMINI_API_KEY   — Google Gemini API key (HOROSCOPE_SOURCE=gemini, default)
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';

$source = getenv('HOROSCOPE_SOURCE') ?: 'gemini';
$today  = date('Y-m-d');
$lang   = 'hi';

$zodiac_signs = [
    'aries'       => ['hi' => 'मेष',     'hi_tr' => 'Mesh'],
    'taurus'      => ['hi' => 'वृषभ',    'hi_tr' => 'Vrishabh'],
    'gemini'      => ['hi' => 'मिथुन',   'hi_tr' => 'Mithun'],
    'cancer'      => ['hi' => 'कर्क',    'hi_tr' => 'Kark'],
    'leo'         => ['hi' => 'सिंह',    'hi_tr' => 'Simha'],
    'virgo'       => ['hi' => 'कन्या',   'hi_tr' => 'Kanya'],
    'libra'       => ['hi' => 'तुला',    'hi_tr' => 'Tula'],
    'scorpio'     => ['hi' => 'वृश्चिक', 'hi_tr' => 'Vrishchik'],
    'sagittarius' => ['hi' => 'धनु',     'hi_tr' => 'Dhanu'],
    'capricorn'   => ['hi' => 'मकर',     'hi_tr' => 'Makar'],
    'aquarius'    => ['hi' => 'कुम्भ',   'hi_tr' => 'Kumbh'],
    'pisces'      => ['hi' => 'मीन',     'hi_tr' => 'Meen'],
];

echo "[{$today}] Generating horoscopes via {$source}...\n";

$gemini_key = getenv('GEMINI_API_KEY');

foreach ($zodiac_signs as $sign => $meta) {
    // Skip if already generated
    $check = $pdo->prepare(
        'SELECT id FROM daily_horoscope WHERE zodiac_sign = ? AND horoscope_date = ? AND language_code = ?'
    );
    $check->execute([$sign, $today, $lang]);
    if ($check->fetch()) {
        echo "  [{$sign}] Already exists. Skipping.\n";
        continue;
    }

    $horoscope_data = null;

    if ($source === 'gemini' && $gemini_key) {
        $horoscope_data = generateViaGemini($sign, $meta['hi_tr'], $today, $gemini_key);
    }

    // Fallback placeholder if generation failed
    if (!$horoscope_data) {
        $colors = ['लाल', 'पीला', 'हरा', 'नीला', 'सफेद'];
        $times  = ['06:00 - 07:00 AM', '10:00 - 11:00 AM', '02:00 - 03:00 PM', '06:00 - 07:00 PM'];
        $horoscope_data = [
            'content'       => "आज का दिन {$meta['hi']} राशि के जातकों के लिए उत्तम है। मेहनत और लगन से कार्य करें तो सफलता अवश्य मिलेगी।",
            'lucky_number'  => rand(1, 9),
            'lucky_color'   => $colors[array_rand($colors)],
            'lucky_time'    => $times[array_rand($times)],
            'general_score' => rand(5, 9),
            'love_score'    => rand(4, 9),
            'career_score'  => rand(5, 9),
            'health_score'  => rand(5, 9),
        ];
    }

    $pdo->prepare(
        'INSERT INTO daily_horoscope
           (zodiac_sign, zodiac_hi, horoscope_date, language_code, content,
            lucky_number, lucky_color, lucky_time,
            general_score, love_score, career_score, health_score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           content=VALUES(content), lucky_number=VALUES(lucky_number),
           lucky_color=VALUES(lucky_color), lucky_time=VALUES(lucky_time),
           general_score=VALUES(general_score), love_score=VALUES(love_score),
           career_score=VALUES(career_score), health_score=VALUES(health_score)'
    )->execute([
        $sign, $meta['hi'], $today, $lang,
        $horoscope_data['content'],
        $horoscope_data['lucky_number'],
        $horoscope_data['lucky_color'],
        $horoscope_data['lucky_time'],
        $horoscope_data['general_score'],
        $horoscope_data['love_score'],
        $horoscope_data['career_score'],
        $horoscope_data['health_score'],
    ]);

    echo "  [{$sign}] Saved.\n";
    if ($source === 'gemini') {
        usleep(500000); // 0.5s delay to respect rate limits
    }
}

echo "Horoscope generation complete for {$today}.\n";

// ─────────────────────────────────────────────────────────────────────────────

function generateViaGemini(string $sign, string $sign_tr, string $date, string $api_key): ?array
{
    $prompt = "Generate daily horoscope for {$sign} ({$sign_tr}) for {$date} in Hindi language. "
        . "Return ONLY a valid JSON object with these exact fields: "
        . "content (2-3 sentences Hindi horoscope prediction), "
        . "lucky_number (integer 1-9), "
        . "lucky_color (one Hindi color name string), "
        . "lucky_time (time range string like \"10:00 - 11:00 AM\"), "
        . "general_score (integer 1-10), "
        . "love_score (integer 1-10), "
        . "career_score (integer 1-10), "
        . "health_score (integer 1-10). "
        . "No markdown, no explanation — raw JSON only.";

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0.8,
            'maxOutputTokens'  => 400,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$api_key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        echo "  Gemini API error for {$sign} (HTTP {$http_code}): {$curl_err}\n";
        return null;
    }

    $resp = json_decode($response, true);
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') {
        return null;
    }

    // Strip possible markdown code fences
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);

    $data = json_decode(trim($text), true);
    if (!is_array($data) || empty($data['content'])) {
        echo "  [{$sign}] Invalid Gemini response format. Using fallback.\n";
        return null;
    }

    return [
        'content'       => (string)($data['content']       ?? ''),
        'lucky_number'  => max(1, min(9, (int)($data['lucky_number']  ?? rand(1, 9)))),
        'lucky_color'   => (string)($data['lucky_color']   ?? 'लाल'),
        'lucky_time'    => (string)($data['lucky_time']    ?? '10:00 - 11:00 AM'),
        'general_score' => max(1, min(10, (int)($data['general_score'] ?? 7))),
        'love_score'    => max(1, min(10, (int)($data['love_score']    ?? 6))),
        'career_score'  => max(1, min(10, (int)($data['career_score']  ?? 7))),
        'health_score'  => max(1, min(10, (int)($data['health_score']  ?? 7))),
    ];
}
