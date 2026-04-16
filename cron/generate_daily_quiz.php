<?php
/**
 * cron/generate_daily_quiz.php
 * CLI cron — generate today's news trivia quiz questions via Gemini AI.
 *
 * Run daily at 7am: 0 7 * * * php /path/to/cron/generate_daily_quiz.php
 *
 * Process:
 *   1. Fetch top 10 approved news articles from yesterday
 *   2. For each article, call Gemini AI to generate 5 MCQ questions in Hindi
 *   3. Insert questions into news_quiz_questions for today's date
 *
 * Required env vars:
 *   GEMINI_API_KEY
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';

$gemini_key = getenv('GEMINI_API_KEY');
if (!$gemini_key) {
    echo "GEMINI_API_KEY not set. Exiting.\n";
    exit(1);
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Check if already generated
$existing = $pdo->prepare('SELECT COUNT(*) FROM news_quiz_questions WHERE quiz_date = ?');
$existing->execute([$today]);
if ((int)$existing->fetchColumn() >= 5) {
    echo "Quiz already generated for {$today}. Exiting.\n";
    exit(0);
}

echo "[{$today}] Generating daily quiz from yesterday's top articles...\n";

// Fetch top 10 articles from yesterday
$stmt = $pdo->prepare(
    "SELECT id, title, description, content
     FROM news
     WHERE status = 'approved' AND DATE(created_at) = ?
     ORDER BY views DESC, created_at DESC
     LIMIT 10"
);
$stmt->execute([$yesterday]);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($articles)) {
    echo "No articles found for {$yesterday}. Exiting.\n";
    exit(1);
}

$insert = $pdo->prepare(
    'INSERT IGNORE INTO news_quiz_questions
       (quiz_date, article_id, question, option_a, option_b, option_c, option_d,
        correct_option, explanation, difficulty, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$sort_order = 1;
$total_inserted = 0;

foreach (array_slice($articles, 0, 3) as $article) { // Use top 3 articles → ~15 questions
    $content = mb_substr(strip_tags($article['description'] . ' ' . $article['content']), 0, 1500);

    $prompt = "Create exactly 5 multiple-choice questions based on this news article. "
        . "Questions must be in Hindi language. "
        . "Return ONLY a valid JSON array of 5 objects, each with fields: "
        . "question (string), option_a (string), option_b (string), option_c (string), "
        . "option_d (string), correct_option (\"a\", \"b\", \"c\", or \"d\"), "
        . "explanation (string in Hindi), difficulty (\"easy\", \"medium\", or \"hard\"). "
        . "No markdown, no extra text — raw JSON array only.\n\n"
        . "Article: " . $article['title'] . "\n\n" . $content;

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0.5,
            'maxOutputTokens'  => 2000,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$gemini_key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        echo "  Gemini error for article {$article['id']} (HTTP {$http_code}). Skipping.\n";
        usleep(1000000);
        continue;
    }

    $resp = json_decode($response, true);
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Strip markdown fences
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);

    $questions = json_decode(trim($text), true);
    if (!is_array($questions)) {
        echo "  JSON parse error for article {$article['id']}. Skipping.\n";
        usleep(1000000);
        continue;
    }

    foreach (array_slice($questions, 0, 5) as $q) {
        if (empty($q['question']) || !in_array($q['correct_option'] ?? '', ['a','b','c','d'], true)) {
            continue;
        }
        $diff = in_array($q['difficulty'] ?? '', ['easy','medium','hard'], true)
            ? $q['difficulty'] : 'easy';

        $insert->execute([
            $today,
            (int)$article['id'],
            $q['question'],
            $q['option_a']  ?? '',
            $q['option_b']  ?? '',
            $q['option_c']  ?? '',
            $q['option_d']  ?? '',
            $q['correct_option'],
            $q['explanation'] ?? null,
            $diff,
            $sort_order++,
        ]);
        $total_inserted++;
    }

    echo "  Article {$article['id']}: questions inserted.\n";
    usleep(800000); // 0.8s delay between API calls
}

echo "Quiz generated for {$today}: {$total_inserted} questions total.\n";
