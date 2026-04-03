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
