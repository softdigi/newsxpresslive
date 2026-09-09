<?php
/**
 * web/includes/fake_news_detector.php
 * NewsXpressLive — Fake News Detection Engine
 *
 * Pure-PHP NLP-style analyser. No external dependencies needed.
 *
 * Signals evaluated:
 *  1. Sensational / misinformation keywords in title + body
 *  2. Clickbait structural patterns (ALL-CAPS, excessive punctuation, …)
 *  3. Credibility signals (very short body, missing reporter attribution)
 *  4. Lexical sentiment (positive / negative word counts → extreme sentiment flag)
 *
 * Scoring (additive, capped at 100):
 *   Each flag adds a configurable number of points.
 *   Verdict:  0-25 → clean | 26-55 → suspicious | 56+ → likely_fake
 *
 * Usage:
 *   require_once __DIR__ . '/fake_news_detector.php';
 *   $result = detectFakeNews($title, $body, $reporterName);
 *   // $result['score']    float   0-100
 *   // $result['verdict']  string  clean|suspicious|likely_fake
 *   // $result['flags']    array   list of triggered signal keys
 *   // $result['sentiment'] string positive|negative|neutral
 */

declare(strict_types=1);

if (!function_exists('detectFakeNews')) {

    // ------------------------------------------------------------------ //
    //  Public entry-point
    // ------------------------------------------------------------------ //

    /**
     * Analyse a news article for fake-news risk signals.
     *
     * @param string $title        Article headline
     * @param string $body         Full article body (HTML stripped externally or here)
     * @param string $reporterName Reporter/author name (empty string = unknown)
     * @return array{score:float, verdict:string, flags:list<string>, sentiment:string}
     */
    function detectFakeNews(string $title, string $body, string $reporterName = ''): array
    {
        // Normalise inputs
        $plainBody = strip_tags($body);
        $titleLow  = mb_strtolower($title,     'UTF-8');
        $bodyLow   = mb_strtolower($plainBody,  'UTF-8');
        $combined  = $titleLow . ' ' . $bodyLow;

        $flags = [];
        $score = 0.0;

        // ── 1. Sensational / misinformation phrase matching ────────────
        $score += _checkSensationalPhrases($combined, $flags);

        // ── 2. Clickbait title-structure analysis ─────────────────────
        $score += _checkClickbaitStructure($title, $flags);

        // ── 3. Body credibility signals ───────────────────────────────
        $score += _checkCredibility($plainBody, $reporterName, $flags);

        // ── 4. Extreme sentiment detection ────────────────────────────
        [$sentimentLabel, $sentimentPenalty] = _checkSentiment($combined, $flags);
        $score += $sentimentPenalty;

        // Cap at 100
        $score   = (float)min(round($score, 2), 100.0);
        $verdict = _verdict($score);

        return [
            'score'     => $score,
            'verdict'   => $verdict,
            'flags'     => $flags,
            'sentiment' => $sentimentLabel,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Signal: sensational phrases
    // ------------------------------------------------------------------ //

    /** @param list<string> $flags */
    function _checkSensationalPhrases(string $text, array &$flags): float
    {
        // Each phrase is [pattern, weight]
        static $phrases = [
            // Misinformation / conspiracy language
            ['share before (they |it gets )?(delete|remov|ban)',    12],
            ['(government|media|they) (is |are )?(hiding|suppres)',  12],
            ['(banned|censored|suppressed) (by|from)',              12],
            ['you won\'t believe',                                   10],
            ['(shocking truth|dark truth|hidden truth)',             10],
            ['(they|media|government) (don\'t|doesn\'t) want you',  10],
            ['100%\s*(confirmed|proven|true)',                       10],
            ['(exclusive|breaking)[\s!:]+leak',                     10],
            ['(leaked|secret)\s+(video|document|recording)',         10],
            ['(miracle|instant)\s+cure',                             12],
            ['(cure|treatment) for (cancer|covid|hiv|aids)',         12],
            ['(scientists|doctors|experts) warn(ed)?',               6],
            ['must (share|watch|read)\s*(now|immediately|urgent)?',  8],
            ['going viral',                                           5],
            ['urgent\s+(warning|alert|news)',                        8],
            ['this is (not |fake )?what (really )?happened',         8],
            ['(exposed|reveals?)\s+(the )?truth',                    8],
            ['(fake|false)\s+(news|media)',                          5],  // defensive framing
            ['(illuminati|deep.?state|new world order)',             15],
            ['(reptilian|flat.?earth|chemtrail)',                    15],
            ['(they|elite|globalist)',                               4],
        ];

        $penalty = 0.0;
        foreach ($phrases as [$pattern, $weight]) {
            if (preg_match('/' . $pattern . '/iu', $text)) {
                $flagKey = 'sensational_phrase:' . mb_substr($pattern, 0, 30);
                if (!in_array($flagKey, $flags, true)) {
                    $flags[]  = $flagKey;
                    $penalty += $weight;
                }
                if ($penalty >= 40) break; // cap phrase contribution
            }
        }

        return min($penalty, 40.0);
    }

    // ------------------------------------------------------------------ //
    //  Signal: clickbait title structure
    // ------------------------------------------------------------------ //

    /** @param list<string> $flags */
    function _checkClickbaitStructure(string $title, array &$flags): float
    {
        $penalty = 0.0;
        $words   = preg_split('/\s+/', trim($title), -1, PREG_SPLIT_NO_EMPTY);
        $total   = count($words);

        // a) High ALL-CAPS word ratio (> 40% of words)
        if ($total > 0) {
            $capsCount = 0;
            foreach ($words as $w) {
                if (mb_strlen($w) >= 3 && $w === mb_strtoupper($w, 'UTF-8')) {
                    $capsCount++;
                }
            }
            if ($capsCount / $total > 0.40) {
                $flags[]  = 'title_high_caps_ratio';
                $penalty += 18;
            }
        }

        // b) Excessive exclamation marks (3+)
        if (preg_match('/!{3,}/', $title)) {
            $flags[]  = 'title_excessive_exclamation';
            $penalty += 12;
        }

        // c) Double question marks
        if (strpos($title, '??') !== false) {
            $flags[]  = 'title_double_question_mark';
            $penalty += 8;
        }

        // d) Listicle clickbait  e.g. "10 shocking reasons..."
        if (preg_match('/^\d+\s+(shocking|surprising|unbelievable|incredible|amazing|secret)/i', $title)) {
            $flags[]  = 'title_listicle_clickbait';
            $penalty += 10;
        }

        // e) "Here's why / What happened" tail-clickbait
        if (preg_match('/here\'s (why|what|how)/i', $title)) {
            $flags[]  = 'title_tail_clickbait';
            $penalty += 8;
        }

        // f) Entire title in caps
        if ($total >= 3 && mb_strtoupper($title, 'UTF-8') === $title) {
            if (!in_array('title_high_caps_ratio', $flags, true)) {
                $flags[]  = 'title_all_caps';
                $penalty += 15;
            }
        }

        return min($penalty, 40.0);
    }

    // ------------------------------------------------------------------ //
    //  Signal: body credibility
    // ------------------------------------------------------------------ //

    /** @param list<string> $flags */
    function _checkCredibility(string $plainBody, string $reporterName, array &$flags): float
    {
        $penalty = 0.0;

        // a) Very short body (< 80 words)
        $wordCount = str_word_count($plainBody);
        if ($wordCount < 80) {
            $flags[]  = 'body_too_short';
            $penalty += 20;
        } elseif ($wordCount < 150) {
            $flags[]  = 'body_very_short';
            $penalty += 10;
        }

        // b) No reporter / author attribution
        if (trim($reporterName) === '') {
            $flags[]  = 'no_author_attribution';
            $penalty += 8;
        }

        // c) No source / quotation markers anywhere in body
        $hasSource = preg_match('/(\baccording to\b|\bsources?\b|\bquoting\b|"[^"]{10,}")/i', $plainBody);
        if (!$hasSource) {
            $flags[]  = 'no_source_cited';
            $penalty += 8;
        }

        // d) Excessive emoji / special symbols (> 5 distinct emoji clusters)
        $emojiMatches = preg_match_all(
            '/[\x{1F300}-\x{1FAFF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u',
            $plainBody,
            $_
        );
        if ($emojiMatches > 5) {
            $flags[]  = 'body_excessive_emoji';
            $penalty += 8;
        }

        // e) Body contains ALL-CAPS sentences (> 20% of sentences in ALL CAPS)
        $sentences    = preg_split('/(?<=[.!?।])\s+/', $plainBody, -1, PREG_SPLIT_NO_EMPTY);
        $sentCount    = count($sentences);
        if ($sentCount > 0) {
            $capsCount = 0;
            foreach ($sentences as $s) {
                if (mb_strlen($s) > 10 && $s === mb_strtoupper($s, 'UTF-8')) {
                    $capsCount++;
                }
            }
            if ($capsCount / $sentCount > 0.20) {
                $flags[]  = 'body_excessive_caps_sentences';
                $penalty += 12;
            }
        }

        return min($penalty, 40.0);
    }

    // ------------------------------------------------------------------ //
    //  Signal: extreme sentiment via AFINN-style mini word list
    // ------------------------------------------------------------------ //

    /**
     * Returns [sentimentLabel, penaltyPoints].
     * Extreme negativity in a news article is a weak misinformation signal.
     *
     * @param  list<string> $flags
     * @return array{0:string, 1:float}
     */
    function _checkSentiment(string $text, array &$flags): array
    {
        static $positiveWords = [
            'great','amazing','wonderful','excellent','best','good','positive','success',
            'win','victory','hope','joy','love','peace','freedom','progress','truth',
            'confirmed','official','verified','announced','approved',
        ];
        static $negativeWords = [
            'bad','terrible','horrible','awful','worst','evil','dangerous','deadly',
            'kill','murder','attack','bomb','terror','fear','panic','collapse',
            'fraud','corrupt','lie','liar','cheat','scandal','crisis','disaster',
            'shocking','outrage','disgusting','filthy','criminal','illegal',
        ];

        $tokens  = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $posHits = 0;
        $negHits = 0;

        foreach ($tokens as $tok) {
            if (in_array($tok, $positiveWords, true)) $posHits++;
            if (in_array($tok, $negativeWords, true)) $negHits++;
        }

        $total = $posHits + $negHits;
        if ($total === 0) {
            return ['neutral', 0.0];
        }

        $negRatio = $negHits / $total;
        if ($negRatio >= 0.75) {
            $flags[] = 'extreme_negative_sentiment';
            return ['negative', 10.0];
        }
        if ($negRatio <= 0.25) {
            return ['positive', 0.0];  // positive news is fine
        }
        return ['neutral', 0.0];
    }

    // ------------------------------------------------------------------ //
    //  Verdict helper
    // ------------------------------------------------------------------ //

    function _verdict(float $score): string
    {
        if ($score >= 56) return 'likely_fake';
        if ($score >= 26) return 'suspicious';
        return 'clean';
    }

} // end if (!function_exists)
