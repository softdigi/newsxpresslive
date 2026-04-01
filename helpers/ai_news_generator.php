<?php
// ============================================================
// helpers/ai_news_generator.php
//
// AI-powered news article generator for NewsXpressLive.
//
// Supports (priority order):
//   1. OpenAI GPT-3.5-turbo  — OPENAI_API_KEY env var / DB setting
//   2. Google Gemini 1.5 Flash — GEMINI_API_KEY env var / DB setting
//
// Functions exported:
//   aiGenerateNewsArticle(string $topic, string $lang = 'en'): array
//     Returns: [
//       'title'            => string,
//       'content'          => string,  (HTML body, ~400 words)
//       'meta_title'       => string,
//       'meta_description' => string,
//       'image_suggestions'=> array,   (search query strings for images)
//       'tags'             => array,   (suggested tag words)
//       'error'            => string|null,
//     ]
//
//   aiImageSuggestions(string $topic): array
//     Returns list of Unsplash/Pexels-ready search queries for topic.
// ============================================================

declare(strict_types=1);

if (!function_exists('aiGenerateNewsArticle')) {

    /**
     * Generate a full news article for a given topic/keyword using AI.
     */
    function aiGenerateNewsArticle(string $topic, string $lang = 'en'): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return _aiNewsError('Topic cannot be empty.');
        }

        $prompt = _buildGeneratorPrompt($topic, $lang);
        $raw    = _callAIForNews($prompt);

        if ($raw === false) {
            return _aiNewsError('AI service unavailable. Check API keys in Settings → API Keys.');
        }

        return _parseGeneratorResponse($raw, $topic);
    }

    /**
     * Return simple Unsplash/Pexels image search queries for a topic.
     */
    function aiImageSuggestions(string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        // Generate 4 diverse search queries using AI
        $prompt = "Generate exactly 4 diverse, specific image search queries suitable for a Pexels or Unsplash search "
                . "about the following news topic: \"{$topic}\". "
                . "Return only a JSON array of 4 short strings (3-5 words each), no explanation:\n"
                . "Example format: [\"query one\",\"query two\",\"query three\",\"query four\"]";

        $raw = _callAIForNews($prompt);
        if ($raw !== false) {
            // Try to extract JSON array from response
            if (preg_match('/\[.*?\]/s', $raw, $m)) {
                $arr = json_decode($m[0], true);
                if (is_array($arr) && count($arr) >= 1) {
                    return array_slice(array_map('strval', $arr), 0, 6);
                }
            }
        }

        // Fallback: split topic into simple queries
        return [
            $topic,
            $topic . ' news',
            $topic . ' photo',
            'breaking news ' . $topic,
        ];
    }

    // ------------------------------------------------------------------
    // Build the system prompt for article generation
    // ------------------------------------------------------------------
    function _buildGeneratorPrompt(string $topic, string $lang): string
    {
        $langLabel = ($lang === 'hi') ? 'Hindi' : 'English';

        return <<<PROMPT
You are a professional news journalist. Generate a complete, factual-style news article in {$langLabel} about the following topic: "{$topic}".

Return ONLY a valid JSON object (no markdown, no explanation) with these exact keys:
{
  "title": "A compelling, factual news headline (max 100 characters)",
  "meta_title": "SEO-optimised page title (max 70 characters)",
  "meta_description": "SEO meta description summarising the article (max 160 characters)",
  "content": "Full article body in HTML format (~400 words, 3-5 paragraphs, use <p> tags only, no <h1> or <h2>)",
  "image_suggestions": ["3 to 5 specific Pexels/Unsplash image search query strings related to the topic"],
  "tags": ["5 to 8 relevant tag words (single words or short phrases)"]
}

Rules:
- Write in journalistic, neutral tone.
- Content must be structured as HTML paragraphs using <p> tags only.
- Do NOT include the title inside the content.
- Keep all strings properly escaped for JSON.
- image_suggestions should be diverse, specific phrases usable as Pexels/Unsplash search queries.
PROMPT;
    }

    // ------------------------------------------------------------------
    // Parse the JSON response from AI into a structured array
    // ------------------------------------------------------------------
    function _parseGeneratorResponse(string $raw, string $topic): array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($raw));

        $data = json_decode($cleaned, true);

        if (!is_array($data) || empty($data['title']) || empty($data['content'])) {
            error_log('AI[Generator] JSON parse failed. Raw: ' . substr($raw, 0, 500));

            // Attempt to extract title/content heuristically
            return _aiNewsError(
                'AI returned an unexpected format. Please try again.',
                ['raw' => substr($raw, 0, 1000)]
            );
        }

        return [
            'title'             => _sanitizeText($data['title'] ?? ''),
            'meta_title'        => _sanitizeText($data['meta_title'] ?? ''),
            'meta_description'  => _sanitizeText($data['meta_description'] ?? ''),
            'content'           => _sanitizeHtmlContent($data['content'] ?? ''),
            'image_suggestions' => is_array($data['image_suggestions'] ?? null)
                                    ? array_map('strval', $data['image_suggestions'])
                                    : aiImageSuggestions($topic),
            'tags'              => is_array($data['tags'] ?? null)
                                    ? array_map('strval', $data['tags'])
                                    : [],
            'error'             => null,
        ];
    }

    // ------------------------------------------------------------------
    // Call the best available AI backend (OpenAI → Gemini → false)
    // Uses higher token limit than the summariser (800 tokens)
    // ------------------------------------------------------------------
    function _callAIForNews(string $prompt): string|false
    {
        $openaiKey = getenv('OPENAI_API_KEY');
        if ($openaiKey) {
            return _callOpenAINews($prompt, $openaiKey);
        }

        $geminiKey = getenv('GEMINI_API_KEY');
        if ($geminiKey) {
            return _callGeminiNews($prompt, $geminiKey);
        }

        // Try DB-stored keys
        $dbKeys = _getNewsDbApiKeys();
        if (!empty($dbKeys['openai_api_key'])) {
            return _callOpenAINews($prompt, $dbKeys['openai_api_key']);
        }
        if (!empty($dbKeys['gemini_api_key'])) {
            return _callGeminiNews($prompt, $dbKeys['gemini_api_key']);
        }

        return false;
    }

    // ------------------------------------------------------------------
    // OpenAI GPT-3.5-turbo (higher token limit for full articles)
    // ------------------------------------------------------------------
    function _callOpenAINews(string $prompt, string $apiKey): string|false
    {
        $payload = json_encode([
            'model'       => 'gpt-3.5-turbo',
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => 1200,
            'temperature' => 0.7,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
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
            error_log('AINews[OpenAI] curl error: ' . $curlErr);
            return false;
        }

        $json = json_decode($raw, true);
        $text = trim($json['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            error_log('AINews[OpenAI] empty response: ' . substr($raw, 0, 500));
            return false;
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Google Gemini 1.5 Flash
    // ------------------------------------------------------------------
    function _callGeminiNews(string $prompt, string $apiKey): string|false
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='
             . urlencode($apiKey);

        $payload = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 1500, 'temperature' => 0.7],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $raw     = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('AINews[Gemini] curl error: ' . $curlErr);
            return false;
        }

        $json = json_decode($raw, true);
        $text = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text === '') {
            error_log('AINews[Gemini] empty response: ' . substr($raw, 0, 500));
            return false;
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Load API keys from settings DB table (cached per request)
    // ------------------------------------------------------------------
    function _getNewsDbApiKeys(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

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
            error_log('AINews _getNewsDbApiKeys error: ' . $e->getMessage());
            $cache = [];
        }
        return $cache;
    }

    // ------------------------------------------------------------------
    // Sanitise plain text (remove tags, trim, cap length)
    // ------------------------------------------------------------------
    function _sanitizeText(string $text, int $maxLen = 255): string
    {
        return mb_substr(trim(strip_tags($text)), 0, $maxLen, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // Sanitise HTML article content — allow only <p> and <br> tags
    // ------------------------------------------------------------------
    function _sanitizeHtmlContent(string $html): string
    {
        // Allow only safe inline tags
        $clean = strip_tags($html, '<p><br><strong><em><ul><ol><li><a>');
        return trim($clean);
    }

    // ------------------------------------------------------------------
    // Return a consistent error result array
    // ------------------------------------------------------------------
    function _aiNewsError(string $message, array $extra = []): array
    {
        return array_merge([
            'title'             => '',
            'meta_title'        => '',
            'meta_description'  => '',
            'content'           => '',
            'image_suggestions' => [],
            'tags'              => [],
            'error'             => $message,
        ], $extra);
    }

} // end if(!function_exists)
