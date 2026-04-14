<?php
/**
 * web/api/preferences/get.php
 *
 * GET — Fetch the full user preference profile.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *
 * Response:
 * {
 *   onboarding_completed: bool,
 *   selected_categories:  string[],
 *   languages:            string[],
 *   location: {
 *     state:    string|null,
 *     district: string|null
 *   },
 *   behavior_insights: {
 *     top_categories: [
 *       { name, emoji, reads_this_month, avg_read_time, your_weight }
 *     ]
 *   },
 *   popup_settings: {
 *     skip_streak:     int,
 *     snoozed_until:   string|null,
 *     total_shown:     int,
 *     engagement_rate: float   // popup_total_engaged / popup_total_shown * 100
 *   }
 * }
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

/* ── Fetch preferences ─────────────────────────────────────────────── */
try {
    $pdo = getPDO();

    $prefStmt = $pdo->prepare(
        'SELECT up.*,
                s.name AS state_name,
                d.name AS district_name
         FROM user_preferences up
         LEFT JOIN states    s ON s.id = up.preferred_state_id
         LEFT JOIN districts d ON d.id = up.preferred_district_id
         WHERE up.user_id = ?
         LIMIT 1'
    );
    $prefStmt->execute([$uid]);
    $prefs = $prefStmt->fetch(\PDO::FETCH_ASSOC);

} catch (\Throwable $e) {
    // states/districts tables may not exist — fallback without join
    try {
        $prefStmt = $pdo->prepare(
            'SELECT * FROM user_preferences WHERE user_id = ? LIMIT 1'
        );
        $prefStmt->execute([$uid]);
        $prefs = $prefStmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e2) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
        exit;
    }
}

if (!$prefs) {
    // User not found — return empty defaults
    echo json_encode([
        'success'              => true,
        'onboarding_completed' => false,
        'selected_categories'  => [],
        'languages'            => [],
        'location'             => ['state' => null, 'district' => null],
        'behavior_insights'    => ['top_categories' => []],
        'popup_settings'       => [
            'skip_streak'     => 0,
            'snoozed_until'   => null,
            'total_shown'     => 0,
            'engagement_rate' => 0.0,
        ],
    ]);
    exit;
}

/* ── Decode JSON fields ────────────────────────────────────────────── */
$selectedCategories = [];
if (!empty($prefs['last_mood_categories'])) {
    $decoded = json_decode($prefs['last_mood_categories'], true);
    if (is_array($decoded)) {
        $selectedCategories = $decoded;
    }
}

$languages = [];
if (!empty($prefs['onboarding_languages'])) {
    $decoded = json_decode($prefs['onboarding_languages'], true);
    if (is_array($decoded)) {
        $languages = $decoded;
    }
}

/* ── Behavior insights: top categories (this month) ──────────────── */
$topCategories = [];
try {
    $firstOfMonth = date('Y-m-01 00:00:00');
    $bhvStmt      = $pdo->prepare(
        "SELECT ucb.category_id,
                ucb.articles_read,
                ucb.total_read_seconds,
                ucb.behavior_weight,
                c.name,
                c.emoji
         FROM user_category_behavior ucb
         INNER JOIN categories c ON c.id = ucb.category_id
         WHERE ucb.user_id = ?
         ORDER BY ucb.behavior_weight DESC, ucb.articles_read DESC
         LIMIT 5"
    );
    $bhvStmt->execute([$uid]);
    $bhvRows = $bhvStmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($bhvRows as $row) {
        $reads   = (int) $row['articles_read'];
        $avgTime = $reads > 0 ? round((int) $row['total_read_seconds'] / $reads) : 0;

        $topCategories[] = [
            'name'             => $row['name'],
            'emoji'            => $row['emoji'] ?? '',
            'reads_this_month' => $reads,
            'avg_read_time'    => $avgTime,
            'your_weight'      => (float) $row['behavior_weight'],
        ];
    }
} catch (\Throwable $e) {
    // Non-fatal — return empty list
}

/* ── Engagement rate ───────────────────────────────────────────────── */
$totalShown    = (int) ($prefs['popup_total_shown']    ?? 0);
$totalEngaged  = (int) ($prefs['popup_total_engaged']  ?? 0);
$engagementRate = $totalShown > 0
    ? round($totalEngaged / $totalShown * 100, 2)
    : 0.0;

/* ── Response ─────────────────────────────────────────────────────── */
echo json_encode([
    'success'              => true,
    'onboarding_completed' => (bool) ($prefs['onboarding_completed'] ?? false),
    'selected_categories'  => $selectedCategories,
    'languages'            => $languages,
    'location'             => [
        'state'    => $prefs['state_name']    ?? null,
        'district' => $prefs['district_name'] ?? null,
    ],
    'behavior_insights' => [
        'top_categories' => $topCategories,
    ],
    'popup_settings' => [
        'skip_streak'     => (int) ($prefs['popup_skip_streak']   ?? 0),
        'snoozed_until'   => $prefs['popup_snoozed_until'] ?: null,
        'total_shown'     => $totalShown,
        'engagement_rate' => $engagementRate,
    ],
]);
