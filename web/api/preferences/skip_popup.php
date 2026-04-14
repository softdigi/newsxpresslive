<?php
/**
 * web/api/preferences/skip_popup.php
 *
 * POST — User skipped / dismissed the preference popup.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type: application/json
 *
 * Body:
 * {
 *   context:    string,
 *   popup_type: string  ("onboarding"|"daily_mood"|"category_change")
 * }
 *
 * Response:
 * {
 *   success:      true,
 *   streak:       int,
 *   snoozed_until: null | "Y-m-d H:i:s"
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

/* ── Parse input ───────────────────────────────────────────────────── */
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$context   = substr((string) ($input['context']    ?? 'general'), 0, 50);
$popupType = (string) ($input['popup_type'] ?? 'daily_mood');

$allowedTypes = ['onboarding', 'daily_mood', 'category_change'];
if (!in_array($popupType, $allowedTypes, true)) {
    $popupType = 'daily_mood';
}

/* ── DB operations ─────────────────────────────────────────────────── */
$snoozedUntil = null;
$newStreak    = 0;

try {
    $pdo = getPDO();

    // 1. Increment skip streak and total shown, upsert if not exists
    $stmt = $pdo->prepare(
        "INSERT INTO `user_preferences`
            (`user_id`, `popup_skip_streak`, `popup_total_shown`,
             `created_at`, `updated_at`)
         VALUES (:uid, 1, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            `popup_skip_streak`  = `popup_skip_streak` + 1,
            `popup_total_shown`  = `popup_total_shown` + 1,
            `updated_at`         = NOW()"
    );
    $stmt->execute([':uid' => $uid]);

    // 2. Read back the new streak
    $streakStmt = $pdo->prepare(
        'SELECT popup_skip_streak, popup_snoozed_until
         FROM user_preferences
         WHERE user_id = ?
         LIMIT 1'
    );
    $streakStmt->execute([$uid]);
    $row       = $streakStmt->fetch(\PDO::FETCH_ASSOC);
    $newStreak = (int) ($row['popup_skip_streak'] ?? 1);

    // 3. Auto-snooze if streak >= 3
    if ($newStreak >= 3) {
        $snoozeStmt = $pdo->prepare(
            "UPDATE `user_preferences`
             SET `popup_snoozed_until` = DATE_ADD(NOW(), INTERVAL 3 DAY),
                 `popup_skip_streak`   = 0,
                 `updated_at`          = NOW()
             WHERE `user_id` = ?"
        );
        $snoozeStmt->execute([$uid]);

        $snoozedUntil = date('Y-m-d H:i:s', time() + 3 * 86400);
        $newStreak    = 0;
    }

    // 4. Log to analytics
    $analyticsStmt = $pdo->prepare(
        "INSERT INTO `preference_popup_analytics`
            (`user_id`, `popup_type`, `shown_at`, `action`)
         VALUES (?, ?, NOW(), 'skipped')"
    );
    $analyticsStmt->execute([$uid, $popupType]);

    // 5. Bust Redis prefs cache
    try {
        if (!function_exists('getRedis')) {
            require_once __DIR__ . '/../../../helpers/redis.php';
        }
        getRedis()->del("user_prefs:{$uid}");
    } catch (\Throwable $e) {
        // Non-fatal
    }

} catch (\Throwable $e) {
    error_log('preferences/skip_popup.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
}

echo json_encode([
    'success'      => true,
    'streak'       => $newStreak,
    'snoozed_until'=> $snoozedUntil,
]);
