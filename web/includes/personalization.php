<?php
/**
 * Personalization Engine
 * NewsXpressLive
 *
 * Provides two functions:
 *
 *  getPersonalizedFeed($pdo, $sessionId, $limit)
 *    – Returns articles ranked by a weighted interest score derived from
 *      the current user's behavior profile (category + tag affinity).
 *    – Falls back to recency when no profile exists.
 *
 *  getCollaborativeArticles($pdo, $sessionId, $limit)
 *    – Finds sessions whose category/tag interests overlap with the current
 *      session, then surfaces articles those users read that the current
 *      session has not seen yet (classic item-based collaborative filtering).
 *
 * Scoring formula (getPersonalizedFeed):
 *   article_score = Σ(category_weight * cat_score) + Σ(tag_weight * tag_score)
 *                 + recency_boost
 *
 *   category_weight = 0.6
 *   tag_weight      = 0.4
 *   recency_boost   = 1 / (1 + hours_since_published / 24)  (0–1 extra point)
 */

/**
 * Derive the daily session fingerprint for the current request.
 * Identical logic to track.php so both files match the same session key.
 */
function getSessionId(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR']    ?? '';
    return hash('sha256', $ip . $ua . date('Ymd'));
}

/**
 * Return personalized article feed for $sessionId, ranked by interest score.
 *
 * @param PDO    $pdo
 * @param string $sessionId  SHA-256 fingerprint of the session
 * @param int    $limit      Maximum number of articles to return
 * @return array             Array of news rows (same fields as homepage queries)
 */
function getPersonalizedFeed(PDO $pdo, string $sessionId, int $limit = 12): array
{
    /* ── 1. Load interest profile ──────────────────────────────────── */
    try {
        $profileStmt = $pdo->prepare(
            'SELECT category_id, tag_id, interest_score
             FROM user_profiles
             WHERE session_id = :sid
             ORDER BY interest_score DESC
             LIMIT 50'
        );
        $profileStmt->execute([':sid' => $sessionId]);
        $profile = $profileStmt->fetchAll();
    } catch (PDOException $e) {
        $profile = [];
    }

    /* ── 2. If no profile, fall back to recency ────────────────────── */
    if (empty($profile)) {
        return _fetchLatestNews($pdo, $limit);
    }

    // Separate category vs tag interests
    $catScores = [];
    $tagScores = [];
    foreach ($profile as $row) {
        if ($row['category_id'] !== null) {
            $catScores[(int)$row['category_id']] = (float)$row['interest_score'];
        }
        if ($row['tag_id'] !== null) {
            $tagScores[(int)$row['tag_id']] = (float)$row['interest_score'];
        }
    }

    /* ── 3. Fetch recent candidates (last 7 days, max 200) ─────────── */
    try {
        $candidateStmt = $pdo->prepare(
            'SELECT n.id, n.title, n.slug, n.featured_image,
                    n.content, n.created_at,
                    c.name AS category_name, c.slug AS category_slug,
                    n.category_id
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE n.status = :status
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY n.created_at DESC
             LIMIT 200'
        );
        $candidateStmt->execute([':status' => 'approved']);
        $candidates = $candidateStmt->fetchAll();
    } catch (PDOException $e) {
        return _fetchLatestNews($pdo, $limit);
    }

    if (empty($candidates)) {
        return _fetchLatestNews($pdo, $limit);
    }

    /* ── 4. Fetch tags for all candidate articles ──────────────────── */
    $candidateIds = array_column($candidates, 'id');
    $tagMap = [];   // news_id => [tag_id, …]
    try {
        $in  = implode(',', array_map('intval', $candidateIds));
        $tagRows = $pdo->query(
            "SELECT news_id, tag_id FROM news_tags WHERE news_id IN ($in)"
        )->fetchAll();
        foreach ($tagRows as $tr) {
            $tagMap[(int)$tr['news_id']][] = (int)$tr['tag_id'];
        }
    } catch (PDOException $e) {
        // Tag scoring not critical; continue without it
    }

    /* ── 5. Score each candidate ────────────────────────────────────── */
    // Normalise category scores to max 1
    $maxCat = $catScores ? max($catScores) : 1;
    $maxTag = $tagScores ? max($tagScores) : 1;

    $scored = [];
    $now    = time();
    foreach ($candidates as $article) {
        $catId   = (int)($article['category_id'] ?? 0);
        $catNorm = isset($catScores[$catId]) ? ($catScores[$catId] / $maxCat) : 0;

        $tagNorm = 0;
        if (!empty($tagMap[$article['id']])) {
            foreach ($tagMap[$article['id']] as $tid) {
                if (isset($tagScores[$tid])) {
                    $tagNorm += $tagScores[$tid] / $maxTag;
                }
            }
            $tagNorm = min($tagNorm, 1.0);   // cap at 1
        }

        // Recency boost: newest article gets 1, article published 7 days ago gets ~0.04
        $hoursSince   = ($now - strtotime($article['created_at'])) / 3600;
        $recencyBoost = 1.0 / (1.0 + $hoursSince / 24.0);

        $score = (0.6 * $catNorm) + (0.4 * $tagNorm) + (0.2 * $recencyBoost);
        $article['_score'] = $score;
        $scored[] = $article;
    }

    // Sort descending by score
    usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

    return array_slice($scored, 0, $limit);
}

/**
 * Collaborative filtering: find what similar users read that this session hasn't.
 *
 * @param PDO    $pdo
 * @param string $sessionId
 * @param int    $limit
 * @return array
 */
function getCollaborativeArticles(PDO $pdo, string $sessionId, int $limit = 6): array
{
    try {
        /* ── 1. Top categories this session is interested in ───────── */
        $myCats = $pdo->prepare(
            'SELECT category_id FROM user_profiles
             WHERE session_id = :sid AND category_id IS NOT NULL
             ORDER BY interest_score DESC LIMIT 5'
        );
        $myCats->execute([':sid' => $sessionId]);
        $catIds = array_column($myCats->fetchAll(), 'category_id');

        if (empty($catIds)) {
            return [];
        }

        /* ── 2. Find similar sessions (share ≥2 top categories) ────── */
        $in = implode(',', array_map('intval', $catIds));
        $similarStmt = $pdo->prepare(
            "SELECT session_id, COUNT(*) AS overlap
             FROM user_profiles
             WHERE category_id IN ($in)
               AND session_id != :sid
               AND category_id IS NOT NULL
             GROUP BY session_id
             HAVING overlap >= 2
             ORDER BY overlap DESC
             LIMIT 20"
        );
        $similarStmt->execute([':sid' => $sessionId]);
        $similarSessions = array_column($similarStmt->fetchAll(), 'session_id');

        if (empty($similarSessions)) {
            return [];
        }

        /* ── 3. Articles this session already saw ───────────────────── */
        $seenStmt = $pdo->prepare(
            'SELECT DISTINCT news_id FROM user_behavior WHERE session_id = :sid'
        );
        $seenStmt->execute([':sid' => $sessionId]);
        $seenIds = array_column($seenStmt->fetchAll(), 'news_id');

        /* ── 4. Articles those similar sessions read, minus already-seen ─ */
        $simIn = implode(',', array_fill(0, count($similarSessions), '?'));
        $query =
            "SELECT n.id, n.title, n.slug, n.featured_image,
                    n.content, n.created_at,
                    c.name AS category_name, c.slug AS category_slug,
                    COUNT(ub.session_id) AS collab_score
             FROM user_behavior ub
             JOIN news n ON n.id = ub.news_id AND n.status = 'approved'
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE ub.session_id IN ($simIn)
               AND ub.event_type IN ('read','scroll')";

        if (!empty($seenIds)) {
            $seenIn = implode(',', array_map('intval', $seenIds));
            $query .= " AND ub.news_id NOT IN ($seenIn)";
        }

        $query .= ' GROUP BY ub.news_id ORDER BY collab_score DESC LIMIT ' . (int)$limit;

        $stmt = $pdo->prepare($query);
        $stmt->execute(array_values($similarSessions));
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log('CollaborativeFilter error: ' . $e->getMessage());
        return [];
    }
}

// ============================================================
// ENHANCED FEED — preference-system aware (Phase 2)
// ============================================================

/**
 * Build a personalized feed that merges mood preferences, behavior weights,
 * location relevance, viral score, and breaking-news bonus into a single
 * ranked SQL query.
 *
 * @param  string $uid     Firebase user ID (same as user_preferences.user_id)
 * @param  array  $filters {
 *   limit: int  (default 20)
 * }
 * @return array  Ranked news rows with additional fields:
 *                is_mood_match (bool), relevance_reason (string),
 *                relevance_score (float)
 */
function getEnhancedFeed(string $uid, array $filters = []): array
{
    $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));

    /* ── 1. Load user preferences ──────────────────────────────── */
    try {
        $pdo  = _getEnhancedPDO();
        $stmt = $pdo->prepare(
            'SELECT last_mood_categories, behavior_weights,
                    preferred_state_id, preferred_district_id
             FROM user_preferences
             WHERE user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$uid]);
        $prefs = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return _fetchLatestNews(_getEnhancedPDO(), $limit);
    }

    /* ── 2. Decode mood categories and behavior weights ─────────── */
    $moodCats = [];
    if (!empty($prefs['last_mood_categories'])) {
        $decoded = json_decode($prefs['last_mood_categories'], true);
        if (is_array($decoded)) {
            $moodCats = $decoded;
        }
    }

    $weights = [];
    if (!empty($prefs['behavior_weights'])) {
        $decoded = json_decode($prefs['behavior_weights'], true);
        if (is_array($decoded)) {
            $weights = $decoded;
        }
    }

    $preferredStateId    = isset($prefs['preferred_state_id'])
        ? (int) $prefs['preferred_state_id'] : null;
    $preferredDistrictId = isset($prefs['preferred_district_id'])
        ? (int) $prefs['preferred_district_id'] : null;

    /* ── 3. Build the ranked query ──────────────────────────────── */
    // Mood score: +4.0 if category slug is in mood list
    $moodCaseWhen = '';
    $moodParams   = [];
    if (!empty($moodCats)) {
        $moodPlaceholders = implode(',', array_fill(0, count($moodCats), '?'));
        $moodCaseWhen     = "CASE WHEN c.slug IN ({$moodPlaceholders}) THEN 4.0 ELSE 0.0 END";
        $moodParams       = array_values($moodCats);
    } else {
        $moodCaseWhen = '0.0';
    }

    // behavior_score: JSON_EXTRACT from the per-user JSON, fallback 0.5, * 3.0
    $behaviorScore = "COALESCE(
        CAST(JSON_UNQUOTE(
            JSON_EXTRACT(
                (SELECT behavior_weights FROM user_preferences WHERE user_id = ?),
                CONCAT('$.\"', c.slug, '\"')
            )
        ) AS DECIMAL(5,3)),
        0.500
    ) * 3.0";
    $behaviorParams = [$uid];

    // Location score
    $locationScore  = '0.0';
    $locationParams = [];
    if ($preferredDistrictId !== null && $preferredStateId !== null) {
        $locationScore  = "CASE
            WHEN n.district_id = ? THEN 2.0
            WHEN n.state_id    = ? THEN 1.0
            ELSE 0.0
        END";
        $locationParams = [$preferredDistrictId, $preferredStateId];
    } elseif ($preferredStateId !== null) {
        $locationScore  = "CASE WHEN n.state_id = ? THEN 1.0 ELSE 0.0 END";
        $locationParams = [$preferredStateId];
    }

    // Viral score (normalised to 0–1)
    $viralScoreNorm = 'COALESCE(n.viral_score, 0) / 100.0';

    // Breaking bonus
    $breakingBonus = 'CASE WHEN n.is_breaking = 1 THEN 1.5 ELSE 0.0 END';

    $sql = "
        SELECT
            n.id, n.title, n.slug AS news_slug,
            n.featured_image, n.content, n.created_at,
            n.is_breaking, n.viral_score,
            n.state_id, n.district_id,
            c.name  AS category_name,
            c.slug  AS category_slug,
            c.emoji AS category_emoji,
            c.color_hex AS category_color,
            ({$moodCaseWhen})  AS mood_score,
            ({$behaviorScore}) AS behavior_score,
            ({$locationScore}) AS location_score,
            ({$viralScoreNorm}) AS viral_score_norm,
            ({$breakingBonus}) AS breaking_bonus,
            (
                ({$moodCaseWhen})   +
                ({$behaviorScore})  +
                ({$locationScore})  +
                ({$viralScoreNorm}) +
                ({$breakingBonus})
            ) AS relevance_score
        FROM `news` n
        INNER JOIN `categories` c ON c.id = n.category_id
        WHERE n.status = 'approved'
          AND n.id NOT IN (
              SELECT article_id
              FROM user_read_history
              WHERE user_id = ?
                AND read_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
          )
        ORDER BY relevance_score DESC, n.created_at DESC
        LIMIT {$limit}
    ";

    // Assemble params — each expression that references ? needs its params
    // in the order they appear in the SQL string.
    // Mood appears TWICE in SELECT + relevance_score sum
    $params = array_merge(
        $moodParams,       // mood_score alias
        $behaviorParams,   // behavior_score alias
        $locationParams,   // location_score alias
        [],                // viral_score_norm — no params
        [],                // breaking_bonus   — no params
        $moodParams,       // relevance_score: mood part
        $behaviorParams,   // relevance_score: behavior part
        $locationParams,   // relevance_score: location part
        [$uid]             // NOT IN subquery
    );

    try {
        $pdo  = _getEnhancedPDO();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $articles = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('getEnhancedFeed query error: ' . $e->getMessage());
        return _fetchLatestNews(_getEnhancedPDO(), $limit);
    }

    /* ── 4 & 5. Enrich each article ─────────────────────────────── */
    foreach ($articles as &$article) {
        $slug                      = (string) ($article['category_slug'] ?? '');
        $article['is_mood_match']  = in_array($slug, $moodCats, true);
        $article['relevance_reason'] = _getRelevanceReason(
            $article,
            $moodCats
        );
    }
    unset($article);

    return $articles;
}

/**
 * Determine a human-readable reason for why this article was surfaced.
 *
 * @param  array    $article  Row from getEnhancedFeed query
 * @param  string[] $moodCats Current mood category slugs
 * @return string
 */
function _getRelevanceReason(array $article, array $moodCats): string
{
    if ((int) ($article['is_breaking'] ?? 0) === 1) {
        return 'breaking';
    }
    if (in_array((string) ($article['category_slug'] ?? ''), $moodCats, true)) {
        return 'mood_match';
    }
    if ((float) ($article['location_score'] ?? 0) > 0) {
        return 'local';
    }
    if ((float) ($article['behavior_score'] ?? 0) > 1.5) {
        return 'your_interest';
    }
    return 'trending';
}

/**
 * Internal helper: obtain a PDO instance without relying on a function
 * being defined in the outer scope.
 */
function _getEnhancedPDO(): \PDO
{
    if (function_exists('getPDO')) {
        return getPDO();
    }
    require_once __DIR__ . '/config.php';
    return getPDO();
}

// ============================================================
// END ENHANCED FEED
// ============================================================

/**
 * Simple recency fallback.
 */
function _fetchLatestNews(PDO $pdo, int $limit): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT n.id, n.title, n.slug, n.featured_image,
                    n.content, n.created_at,
                    c.name AS category_name, c.slug AS category_slug
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE n.status = :status
             ORDER BY n.created_at DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute([':status' => 'approved']);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
