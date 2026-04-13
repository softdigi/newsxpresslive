<?php
/**
 * cron/generate_audio_digest.php
 * CLI-only cron — generate daily audio podcast digest via Google TTS.
 *
 * Run at 7am: 0 7 * * * php /path/to/cron/generate_audio_digest.php
 *
 * Required env vars:
 *   GOOGLE_TTS_API_KEY   — Google Cloud Text-to-Speech API key
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 *   SITE_URL
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

declare(strict_types=1);

require_once __DIR__ . '/../web/includes/config.php';

$language     = $argv[1] ?? 'hi';
$today        = date('Y-m-d');
$podcasts_dir = __DIR__ . '/../uploads/podcasts';

if (!is_dir($podcasts_dir)) {
    mkdir($podcasts_dir, 0755, true);
}

$output_file = "{$podcasts_dir}/{$today}.mp3";
$file_url    = rtrim(SITE_URL, '/') . "/uploads/podcasts/{$today}.mp3";

echo "[{$today}] Generating audio digest (lang={$language})...\n";

// ── Check if already generated ────────────────────────────────────────────────
$existing = $pdo->prepare('SELECT id FROM audio_digests WHERE digest_date = ? AND language_code = ?');
$existing->execute([$today, $language]);
if ($existing->fetch()) {
    echo "Digest already exists for {$today}. Exiting.\n";
    exit(0);
}

// ── Fetch top 5 approved articles from today ──────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT id, title, description
     FROM news
     WHERE status = 'approved' AND DATE(created_at) = ?
     ORDER BY views DESC, created_at DESC
     LIMIT 5"
);
$stmt->execute([$today]);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($articles)) {
    // Fall back to yesterday's top articles
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt->execute([$yesterday]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($articles)) {
    echo "No articles found. Exiting.\n";
    exit(1);
}

$article_ids = array_column($articles, 'id');

// ── Build TTS script ──────────────────────────────────────────────────────────
$intro = $language === 'hi'
    ? 'नमस्ते! यह है आपकी आज की न्यूज़ डाइजेस्ट।'
    : 'Hello! Here is your daily news digest.';

$script_parts = [$intro];
foreach ($articles as $i => $article) {
    $num    = $i + 1;
    $title  = strip_tags($article['title']);
    $desc   = strip_tags(mb_substr($article['description'] ?? '', 0, 200));
    $script_parts[] = "{$num}. {$title}. {$desc}";
}
$outro = $language === 'hi'
    ? 'यह था आज का समाचार संकलन। धन्यवाद!'
    : 'That was your daily digest. Thank you!';
$script_parts[] = $outro;

$text = implode(' ', $script_parts);

// ── Call Google TTS API ───────────────────────────────────────────────────────
$api_key = getenv('GOOGLE_TTS_API_KEY');
$duration_seconds = 0;

if (!$api_key) {
    echo "WARNING: GOOGLE_TTS_API_KEY not set. Saving placeholder record.\n";
    // Write a placeholder so the record exists
    file_put_contents($output_file, '');
} else {
    $tts_lang  = $language === 'hi' ? 'hi-IN' : 'en-IN';
    $voice_name = $language === 'hi' ? 'hi-IN-Wavenet-A' : 'en-IN-Wavenet-A';

    $payload = json_encode([
        'input'       => ['text' => $text],
        'voice'       => ['languageCode' => $tts_lang, 'name' => $voice_name],
        'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 1.0],
    ]);

    $ch = curl_init("https://texttospeech.googleapis.com/v1/text:synthesize?key={$api_key}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        echo "TTS API error (HTTP {$http_code}): {$curl_err}\n";
        exit(1);
    }

    $data = json_decode($response, true);
    if (empty($data['audioContent'])) {
        echo "TTS returned empty audio content.\n";
        exit(1);
    }

    $audio_bytes = base64_decode($data['audioContent']);
    file_put_contents($output_file, $audio_bytes);

    // Estimate duration: ~150 words per minute at 1x speed
    $word_count      = str_word_count($text);
    $duration_seconds = (int)round($word_count / 150 * 60);

    echo "Audio saved: {$output_file} (~{$duration_seconds}s)\n";
}

// ── Record in audio_digests table ─────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO audio_digests (digest_date, language_code, duration_seconds, file_url, article_ids)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE duration_seconds=VALUES(duration_seconds),
       file_url=VALUES(file_url), article_ids=VALUES(article_ids)'
)->execute([$today, $language, $duration_seconds, $file_url, json_encode($article_ids)]);

echo "Digest recorded for {$today}. Done.\n";
