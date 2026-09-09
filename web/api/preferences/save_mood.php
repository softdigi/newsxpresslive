<?php
/**
 * web/api/preferences/save_mood.php
 *
 * POST — Save the user's current mood / category selection from the popup.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type: application/json
 *
 * Body:
 * {
 *   categories:           string[],   // category slugs selected
 *   context:              string,     // current context string
 *   was_popup:            bool,       // whether this came from a popup
 *   time_to_select_seconds: int       // seconds user took to make selection
 * }
 *
 * Response:
 * { success: true, feed_updated: true, message: "Aapka feed update ho gaya!" }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';
require_once __DIR__ . '/../../../helpers/popup_engine.php';
require_once __DIR__ . '/../../../helpers/behavior_engine.php';

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

/* ── Parse input ───────────────────────────────────────────────────── */
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$categories        = array_values(array_filter(
    array_map('strval', (array) ($input['categories'] ?? [])),
    fn($s) => preg_match('/^[a-z0-9_\-]+$/i', $s)
));
$context           = substr((string) ($input['context'] ?? 'general'), 0, 50);
$wasPopup          = (bool) ($input['was_popup'] ?? false);
$timeToSelect      = max(0, min(32767, (int) ($input['time_to_select_seconds'] ?? 0)));

if (empty($categories)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'At least one category required']);
    exit;
}

/* ── DB operations ─────────────────────────────────────────────────── */
try {
    $pdo = getPDO();

    // 1. Update user_preferences
    $stmt = $pdo->prepare(
        "INSERT INTO `user_preferences`
            (`user_id`, `last_mood_categories`, `last_mood_set_at`,
             `last_mood_context`,
             `popup_skip_streak`, `popup_total_engaged`,
             `created_at`, `updated_at`)
         VALUES
            (:uid, :cats, NOW(), :ctx, 0, :engaged, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            `last_mood_categories` = :cats2,
            `last_mood_set_at`     = NOW(),
            `last_mood_context`    = :ctx2,
            `popup_skip_streak`    = 0,
            `popup_total_engaged`  = `popup_total_engaged` + :engaged2,
            `updated_at`           = NOW()"
    );

    $catsJson = json_encode($categories);
    $engaged  = $wasPopup ? 1 : 0;

    $stmt->execute([
        ':uid'      => $uid,
        ':cats'     => $catsJson,
        ':ctx'      => $context,
        ':engaged'  => $engaged,
        ':cats2'    => $catsJson,
        ':ctx2'     => $context,
        ':engaged2' => $engaged,
    ]);

    // 2. user_mood_log INSERT
    $hour      = (int) date('G');
    $timeSlot  = SmartPopupEngine::getTimeSlot($hour);
    $dayOfWeek = (int) date('N'); // 1=Mon … 7=Sun

    $logStmt = $pdo->prepare(
        "INSERT INTO `user_mood_log`
            (`user_id`, `selected_categories`, `context`, `time_slot`,
             `day_of_week`, `was_skipped`, `created_at`)
         VALUES (?, ?, ?, ?, ?, 0, NOW())"
    );
    $logStmt->execute([$uid, $catsJson, $context, $timeSlot, $dayOfWeek]);

    // 3. BehaviorLearningEngine::trackMoodSelection
    //    Resolve category slugs → IDs
    if (!empty($categories)) {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $idStmt = $pdo->prepare(
            "SELECT id FROM categories WHERE slug IN ({$placeholders})"
        );
        $idStmt->execute(array_values($categories));
        $catIds = array_column($idStmt->fetchAll(\PDO::FETCH_ASSOC), 'id');

        if (!empty($catIds)) {
            BehaviorLearningEngine::trackMoodSelection($uid, array_map('intval', $catIds));
        }
    }

    // 4. Sync user_interests table (category-based, weight 2.0)
    $upsertInterestStmt = $pdo->prepare(
        "INSERT INTO `user_interests`
            (`user_id`, `category_id`, `tag`, `source`, `weight`)
         SELECT :uid, id, NULL, 'mood', 2.00
         FROM `categories`
         WHERE slug = :slug
         ON DUPLICATE KEY UPDATE
            `weight` = GREATEST(`weight`, 2.00),
            `source` = 'mood'"
    );
    foreach ($categories as $slug) {
        $upsertInterestStmt->execute([':uid' => $uid, ':slug' => $slug]);
    }

    // 5. preference_popup_analytics INSERT
    if ($wasPopup) {
        $analyticsStmt = $pdo->prepare(
            "INSERT INTO `preference_popup_analytics`
                (`user_id`, `popup_type`, `shown_at`, `action`,
                 `time_to_action_seconds`, `categories_after`)
             VALUES (?, 'daily_mood', NOW(), 'submitted', ?, ?)"
        );
        $analyticsStmt->execute([$uid, $timeToSelect, $catsJson]);
    }

    // 6. Bust Redis cache
    try {
        if (!function_exists('getRedis')) {
            require_once __DIR__ . '/../../../helpers/redis.php';
        }
        getRedis()->del("user_prefs:{$uid}");
    } catch (\Throwable $e) {
        // Non-fatal
    }

} catch (\Throwable $e) {
    error_log('preferences/save_mood.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
}

echo json_encode([
    'success'      => true,
    'feed_updated' => true,
    'message'      => 'Aapka feed update ho gaya!',
]);
