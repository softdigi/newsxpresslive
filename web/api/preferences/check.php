<?php
/**
 * web/api/preferences/check.php
 *
 * GET  — Should we show a preference popup to this user?
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *
 * Query params:
 *   duration_minutes  int  (session length, default 999)
 *
 * Response (show_popup = true):
 * {
 *   show_popup: true,
 *   popup_type: "onboarding"|"daily_mood",
 *   context: string,
 *   context_message: string,
 *   current_preferences: [slugs],
 *   suggested_categories: [{slug,name,emoji,color,is_currently_selected,
 *                            behavior_score,new_articles_count}],
 *   all_categories: [...same],
 *   skip_allowed: true,
 *   score: int,
 *   trigger_reasons: [strings]
 * }
 *
 * Response (show_popup = false):
 * { show_popup: false, reason: string }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../../helpers/cors.php';
corsHeaders();
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../helpers/popup_engine.php';

/* ── Auth ──────────────────────────────────────────────────────────── */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idToken    = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $idToken = trim($m[1]);
}

if (empty($idToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization required']);
    exit;
}

$payload = verifyFirebaseToken($idToken);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

$uid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in token']);
    exit;
}

/* ── Build sessionData ─────────────────────────────────────────────── */
$durationMinutes = isset($_GET['duration_minutes'])
    ? max(0, (int) $_GET['duration_minutes'])
    : 999;

$sessionData = ['duration_minutes' => $durationMinutes];

/* ── Run popup engine ──────────────────────────────────────────────── */
$result = SmartPopupEngine::shouldShowPopup($uid, $sessionData);

if (!$result['show']) {
    echo json_encode([
        'show_popup' => false,
        'reason'     => $result['reason'] ?? 'score_low',
        'score'      => $result['score']  ?? 0,
    ]);
    exit;
}

/* ── Build rich category list ─────────────────────────────────────── */
try {
    $pdo = getPDO();

    // 1. Fetch all mood-eligible categories
    $catStmt = $pdo->query(
        "SELECT id, slug, name, emoji, color_hex
         FROM categories
         WHERE is_mood_category = 1
         ORDER BY sort_order ASC, name ASC"
    );
    $allCats = $catStmt->fetchAll(\PDO::FETCH_ASSOC);

    // 2. Current mood selections
    $prefs      = SmartPopupEngine::getUserPrefs($uid);
    $currentRaw = $prefs['last_mood_categories'] ?? '[]';
    $currentCats = json_decode($currentRaw, true);
    $currentCats = is_array($currentCats) ? array_flip($currentCats) : [];

    // 3. Behavior scores for this user (slug → weight)
    $behaviorWeightsRaw = $prefs['behavior_weights'] ?? '{}';
    $behaviorWeights    = json_decode($behaviorWeightsRaw, true) ?? [];

    // 4. Recent article counts (last 3 hours) per category
    $threeHoursAgo = date('Y-m-d H:i:s', time() - 3 * 3600);
    $recentCountStmt = $pdo->prepare(
        "SELECT c.id, COUNT(n.id) AS cnt
         FROM categories c
         LEFT JOIN news n
           ON n.category_id = c.id
          AND n.created_at >= ?
          AND n.status = 'approved'
         WHERE c.is_mood_category = 1
         GROUP BY c.id"
    );
    $recentCountStmt->execute([$threeHoursAgo]);
    $recentCounts = [];
    foreach ($recentCountStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $recentCounts[(int) $row['id']] = (int) $row['cnt'];
    }

    // 5. Build enriched category list
    $enrichedCats = [];
    foreach ($allCats as $cat) {
        $slug          = (string) $cat['slug'];
        $behaviorScore = (float) ($behaviorWeights[$slug] ?? 0.5);
        $newCount      = $recentCounts[(int) $cat['id']] ?? 0;
        $isSelected    = isset($currentCats[$slug]);

        $enrichedCats[] = [
            'slug'                 => $slug,
            'name'                 => $cat['name'],
            'emoji'                => $cat['emoji'] ?? '',
            'color'                => $cat['color_hex'] ?? '#4A90D9',
            'is_currently_selected'=> $isSelected,
            'behavior_score'       => round($behaviorScore, 3),
            'new_articles_count'   => $newCount,
        ];
    }

    // 6. Suggested: top 3 by behavior_score + new_articles_count
    $suggested = $enrichedCats;
    usort($suggested, function ($a, $b) {
        $scoreA = $a['behavior_score'] + ($a['new_articles_count'] * 0.1);
        $scoreB = $b['behavior_score'] + ($b['new_articles_count'] * 0.1);
        return $scoreB <=> $scoreA;
    });
    $suggestedCats = array_slice($suggested, 0, 3);

} catch (\Throwable $e) {
    error_log('preferences/check.php error: ' . $e->getMessage());
    $enrichedCats  = [];
    $suggestedCats = [];
}

/* ── Context message ──────────────────────────────────────────────── */
$contextMessages = [
    'morning_brief'    => 'Subah ki shuruaat! Aaj kya padhna chahte ho?',
    'morning'          => 'Subah ki shuruaat! Aaj kya padhna chahte ho?',
    'afternoon_update' => 'Dopahar ka update! Kya dekhna chahte ho?',
    'evening'          => 'Shaam ka time! Kya dekhna chahte ho?',
    'night_digest'     => 'Raat ka digest! Kya padhna chahte ho?',
    'breaking_news'    => 'Breaking news aaya hai!',
    'general'          => 'Apni pasand batao!',
];
$context        = $result['context'] ?? 'general';
$contextMessage = $contextMessages[$context] ?? 'Apni pasand batao!';

/* ── Response ─────────────────────────────────────────────────────── */
echo json_encode([
    'show_popup'            => true,
    'popup_type'            => $result['type'],
    'context'               => $context,
    'context_message'       => $contextMessage,
    'current_preferences'   => array_keys($currentCats ?? []),
    'suggested_categories'  => $suggestedCats,
    'all_categories'        => $enrichedCats,
    'skip_allowed'          => true,
    'score'                 => $result['score'],
    'trigger_reasons'       => $result['reasons'] ?? [],
]);
