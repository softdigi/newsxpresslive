<?php
// ============================================================
// helpers/ai_summarizer.php
//
// AI-powered news summarizer for NewsXpressLive.
//
// Supports (priority order):
//   1. OpenAI GPT-3.5-turbo  — OPENAI_API_KEY env var
//   2. Google Gemini 1.5 Flash — GEMINI_API_KEY env var
//   3. Extractive fallback    — first 3 key sentences
//
// Functions exported:
//   aiSummarizeArticle(string $title, string $body, string $lang = 'hi'): string
//   aiSummarizeDigest(array $articles, string $type, string $lang = 'hi'): string
//
// $articles = [['title'=>..., 'description'=>...], ...]
// $type     = 'local_district' | 'local_state' | 'national' | 'international'
// $lang     = 'hi' (Hindi) | 'en' (English)
// ============================================================

declare(strict_types=1);

if (!function_exists('aiSummarizeArticle')) {

    // ------------------------------------------------------------------
    // Single-article summary (stored on news.ai_summary column)
    // ------------------------------------------------------------------
    function aiSummarizeArticle(string $title, string $body, string $lang = 'hi'): string
    {
        $text = trim($title . '. ' . $body);
        if (mb_strlen($text) < 80) {
            return $text; // too short, nothing to summarise
        }

        $prompt = $lang === 'hi'
            ? "Neeche di gayi news ko 2-3 lines mein simple Hindi mein summarise karo. Sirf summary likho, koi heading ya extra text nahi:\n\n{$text}"
            : "Summarise the following news in 2-3 clear English sentences. Only the summary, no heading:\n\n{$text}";

        return _callAI($prompt) ?: _extractiveSummary($text, 2);
    }

    // ------------------------------------------------------------------
    // Multi-article digest summary (sent as push notification body)
    // ------------------------------------------------------------------
    function aiSummarizeDigest(array $articles, string $type, string $lang = 'hi'): string
    {
        if (empty($articles)) {
            return $lang === 'hi' ? 'Aaj koi khabar nahi mili.' : 'No news available.';
        }

        // Build bullet list of headlines
        $bullets = '';
        foreach (array_slice($articles, 0, 15) as $i => $a) {
            $headline = trim($a['title'] ?? '');
            if ($headline !== '') {
                $bullets .= ($i + 1) . '. ' . $headline . "\n";
            }
        }

        $typeLabel = match ($type) {
            'local_district' => $lang === 'hi' ? 'aapke jile ki' : 'your district',
            'local_state'    => $lang === 'hi' ? 'aapke state ki' : 'your state',
            'national'       => $lang === 'hi' ? 'rashtriya' : 'national',
            'international'  => $lang === 'hi' ? 'antarrashtriya' : 'international',
            default          => '',
        };

        $prompt = $lang === 'hi'
            ? "Ye {$typeLabel} khabron ki list hai:\n{$bullets}\nIn sab khabron ko 3-4 lines ki ek sundar, spashtसummary mein likho jo user ko notification mein push ki ja sake. Sirf summary likho:"
            : "These are {$typeLabel} news headlines:\n{$bullets}\nWrite a 3-4 sentence digest summary suitable for a push notification. Only the summary:";

        $summary = _callAI($prompt);
        if ($summary) {
            return $summary;
        }

        // Extractive fallback: join first 3 headlines
        $heads = array_column(array_slice($articles, 0, 3), 'title');
        return implode(' | ', $heads);
    }

    // ------------------------------------------------------------------
    // Internal: call AI provider — OpenAI → Gemini → false
    // ------------------------------------------------------------------
    function _callAI(string $prompt): string|false
    {
        $openaiKey = getenv('OPENAI_API_KEY');
        if ($openaiKey) {
            return _callOpenAI($prompt, $openaiKey);
        }

        $geminiKey = getenv('GEMINI_API_KEY');
        if ($geminiKey) {
            return _callGemini($prompt, $geminiKey);
        }

        // Try DB-stored keys (admin_panel/settings table)
        $dbKeys = _getDbApiKeys();
        if (!empty($dbKeys['openai_api_key'])) {
            return _callOpenAI($prompt, $dbKeys['openai_api_key']);
        }
        if (!empty($dbKeys['gemini_api_key'])) {
            return _callGemini($prompt, $dbKeys['gemini_api_key']);
        }

        return false; // caller uses extractive fallback
    }

    // ------------------------------------------------------------------
    // OpenAI GPT-3.5-turbo
    // ------------------------------------------------------------------
    function _callOpenAI(string $prompt, string $apiKey): string|false
    {
        $payload = json_encode([
            'model'      => 'gpt-3.5-turbo',
            'messages'   => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 300,
            'temperature'=> 0.5,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $raw     = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('AI[OpenAI] curl error: ' . $curlErr);
            return false;
        }

        $json = json_decode($raw, true);
        $text = trim($json['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            error_log('AI[OpenAI] empty response: ' . $raw);
            return false;
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Google Gemini 1.5 Flash
    // ------------------------------------------------------------------
    function _callGemini(string $prompt, string $apiKey): string|false
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 300, 'temperature' => 0.5],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $raw     = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('AI[Gemini] curl error: ' . $curlErr);
            return false;
        }

        $json = json_decode($raw, true);
        $text = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text === '') {
            error_log('AI[Gemini] empty response: ' . $raw);
            return false;
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Load API keys from settings table (DB), cached per process
    // ------------------------------------------------------------------
    function _getDbApiKeys(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // $pdo may not be available in all contexts; try global
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            $cache = [];
            return $cache;
        }

        try {
            $stmt = $pdo->query(
                "SELECT setting_key, setting_value FROM settings
                 WHERE setting_key IN ('openai_api_key','gemini_api_key')"
            );
            $cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            error_log('AI _getDbApiKeys error: ' . $e->getMessage());
            $cache = [];
        }
        return $cache;
    }

    // ------------------------------------------------------------------
    // Extractive fallback: pick first $n sentences of at least 30 chars
    // ------------------------------------------------------------------
    function _extractiveSummary(string $text, int $n = 3): string
    {
        $sentences = preg_split('/(?<=[।.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $picked    = [];
        foreach ($sentences as $s) {
            $s = trim($s);
            if (mb_strlen($s) >= 30) {
                $picked[] = $s;
                if (count($picked) >= $n) {
                    break;
                }
            }
        }
        return implode(' ', $picked) ?: mb_substr($text, 0, 200);
    }

} // end if(!function_exists)
