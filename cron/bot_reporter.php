<?php
/**
 * cron/bot_reporter.php
 * CLI cron — AI Reporter Bot: fetch RSS feeds and generate Hindi news drafts.
 *
 * Run hourly: 0 * * * * php /path/to/cron/bot_reporter.php
 *
 * Process:
 *   1. Fetch active RSS sources from bot_news_sources
 *   2. For each new (unprocessed) item, call Gemini AI to write a Hindi article
 *   3. Save as news row with status='bot_draft' for human review
 *
 * Required env vars:
 *   GEMINI_API_KEY
 *   BOT_REPORTER_ENABLED (default true)
 *   BOT_REVIEW_REQUIRED  (default true)
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

if (getenv('BOT_REPORTER_ENABLED') === 'false') {
    exit(0);
}

require_once __DIR__ . '/../web/includes/config.php';

$gemini_key = getenv('GEMINI_API_KEY');
if (!$gemini_key) {
    echo "GEMINI_API_KEY not set. Exiting.\n";
    exit(1);
}

// Fetch sources due for processing
$now_ts = time();
$sources = $pdo->query(
    "SELECT * FROM bot_news_sources
     WHERE is_active = 1
       AND (last_fetched IS NULL
            OR TIMESTAMPDIFF(MINUTE, last_fetched, NOW()) >= fetch_interval_minutes)
     ORDER BY last_fetched ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($sources)) {
    echo "No sources due for processing.\n";
    exit(0);
}

echo "[" . date('c') . "] Bot Reporter: processing " . count($sources) . " sources...\n";

foreach ($sources as $source) {
    echo "  Source: {$source['source_name']}\n";

    $items = fetchRssItems($source['source_url']);
    if (empty($items)) {
        echo "  No items fetched from {$source['source_url']}\n";
    } else {
        foreach ($items as $item) {
            $guid = $item['guid'] ?: $item['link'] ?: md5($item['title']);
            if (empty($guid)) continue;

            // Check if already processed
            $check = $pdo->prepare('SELECT id FROM bot_processed_items WHERE source_id = ? AND item_guid = ?');
            $check->execute([$source['id'], $guid]);
            if ($check->fetch()) continue;

            $article_text = ($item['title'] ?? '') . "\n\n" . mb_substr($item['description'] ?? '', 0, 1500);

            $hindi_article = generateHindiArticle(
                $article_text,
                $source['source_name'],
                $gemini_key
            );

            if ($hindi_article) {
                // Determine publish status
                $status = (getenv('BOT_REVIEW_REQUIRED') === 'false') ? 'approved' : 'bot_draft';

                $pdo->prepare(
                    "INSERT INTO news (title, description, status, bot_source_name, created_at, author_uid)
                     VALUES (?, ?, ?, ?, NOW(), 'bot')"
                )->execute([
                    $hindi_article['title'],
                    $hindi_article['content'],
                    $status,
                    $source['source_name'],
                ]);

                echo "    Saved draft: " . mb_substr($hindi_article['title'], 0, 60) . "\n";
            }

            // Mark as processed
            $pdo->prepare('INSERT IGNORE INTO bot_processed_items (source_id, item_guid) VALUES (?, ?)')
                ->execute([$source['id'], $guid]);

            usleep(800000); // 0.8s between Gemini calls
        }
    }

    // Update last_fetched timestamp
    $pdo->prepare('UPDATE bot_news_sources SET last_fetched = NOW() WHERE id = ?')
        ->execute([$source['id']]);
}

echo "Bot Reporter run complete.\n";

// ─────────────────────────────────────────────────────────────────────────────

function fetchRssItems(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'NewsXpressLive-BotReporter/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $xml_str   = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$xml_str || $http_code !== 200) {
        return [];
    }

    $items = [];
    try {
        // Suppress errors for malformed XML
        $prev = libxml_use_internal_errors(true);
        $xml  = new SimpleXMLElement($xml_str);
        libxml_use_internal_errors($prev);

        foreach ($xml->channel->item ?? [] as $item) {
            $items[] = [
                'title'       => (string)$item->title,
                'description' => strip_tags((string)$item->description),
                'link'        => (string)$item->link,
                'guid'        => (string)$item->guid ?: (string)$item->link,
                'pub_date'    => (string)$item->pubDate,
            ];
        }
    } catch (Exception $e) {
        // Silently skip malformed feeds
    }

    return array_slice($items, 0, 5); // Process max 5 new items per source per run
}

function generateHindiArticle(string $raw_text, string $source_name, string $api_key): ?array
{
    $prompt = "Convert this press release / news item into a news article in simple Hindi. "
        . "Max 200 words. Write an engaging headline and body. "
        . "Source: {$source_name}. "
        . "Return ONLY a JSON object with fields: title (string), content (string). "
        . "No markdown.\n\n"
        . $raw_text;

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0.6,
            'maxOutputTokens'  => 600,
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
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return null;
    }

    $resp = json_decode($response, true);
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);

    $data = json_decode(trim($text), true);
    if (!is_array($data) || empty($data['title']) || empty($data['content'])) {
        return null;
    }

    return ['title' => (string)$data['title'], 'content' => (string)$data['content']];
}
