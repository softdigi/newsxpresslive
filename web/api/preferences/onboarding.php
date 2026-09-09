<?php
/**
 * web/api/preferences/onboarding.php
 *
 * POST — Multi-step onboarding endpoint.
 *
 * Each call handles one step and saves partial progress.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *   Content-Type: application/json
 *
 * Body (common):
 * {
 *   step: "language"|"location"|"categories"|"notifications"
 *   ... step-specific fields (see below)
 * }
 *
 * step='language':
 *   { languages: string[] }           // e.g. ["hi","en"]
 *
 * step='location':
 *   { state_id: int, district_id: int }
 *
 * step='categories':
 *   { categories: string[] }          // min 3 slugs
 *
 * step='notifications':
 *   { notification_allowed: bool, fcm_token: string }
 *
 * Response:
 * {
 *   success: true,
 *   step_completed: string,
 *   next_step: string|null,
 *   onboarding_complete: bool
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
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$step  = (string) ($input['step'] ?? '');

$validSteps = ['language', 'location', 'categories', 'notifications'];
if (!in_array($step, $validSteps, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "Invalid step: {$step}"]);
    exit;
}

/* ── Step logic ────────────────────────────────────────────────────── */
$onboardingComplete = false;
$nextStep           = null;

try {
    $pdo = getPDO();

    // Ensure user_preferences row exists
    $pdo->prepare(
        "INSERT IGNORE INTO `user_preferences`
            (`user_id`, `created_at`, `updated_at`)
         VALUES (?, NOW(), NOW())"
    )->execute([$uid]);

    // Ensure onboarding_progress row exists
    $deviceType  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 50);
    $appVersion  = substr((string) ($input['app_version'] ?? ''), 0, 20);

    $pdo->prepare(
        "INSERT IGNORE INTO `onboarding_progress`
            (`user_id`, `started_at`, `device_type`, `app_version`)
         VALUES (?, NOW(), ?, ?)"
    )->execute([$uid, $deviceType, $appVersion]);

    switch ($step) {

        /* ── STEP 1: Language ──────────────────────────────────── */
        case 'language':
            $languages = array_values(array_filter(
                array_map('strval', (array) ($input['languages'] ?? [])),
                fn($l) => preg_match('/^[a-z]{2,5}$/i', $l)
            ));

            if (empty($languages)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'At least one language required']);
                exit;
            }

            $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `onboarding_languages` = ?, `updated_at` = NOW()
                 WHERE `user_id` = ?"
            )->execute([json_encode($languages), $uid]);

            $pdo->prepare(
                "UPDATE `onboarding_progress`
                 SET `step_language_done` = 1, `step_current` = 2
                 WHERE `user_id` = ?"
            )->execute([$uid]);

            $nextStep = 'location';
            break;

        /* ── STEP 2: Location ──────────────────────────────────── */
        case 'location':
            $stateId    = (int) ($input['state_id']    ?? 0);
            $districtId = (int) ($input['district_id'] ?? 0);

            $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `preferred_state_id`            = ?,
                     `preferred_district_id`          = ?,
                     `onboarding_location_state`      = ?,
                     `onboarding_location_district`   = ?,
                     `updated_at`                     = NOW()
                 WHERE `user_id` = ?"
            )->execute([$stateId, $districtId, $stateId, $districtId, $uid]);

            $pdo->prepare(
                "UPDATE `onboarding_progress`
                 SET `step_location_done` = 1, `step_current` = 3
                 WHERE `user_id` = ?"
            )->execute([$uid]);

            $nextStep = 'categories';
            break;

        /* ── STEP 3: Categories ────────────────────────────────── */
        case 'categories':
            $categories = array_values(array_filter(
                array_map('strval', (array) ($input['categories'] ?? [])),
                fn($s) => preg_match('/^[a-z0-9_\-]+$/i', $s)
            ));

            if (count($categories) < 3) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Minimum 3 categories required']);
                exit;
            }

            $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `onboarding_categories` = ?,
                     `last_mood_categories`  = ?,
                     `last_mood_set_at`      = NOW(),
                     `updated_at`            = NOW()
                 WHERE `user_id` = ?"
            )->execute([json_encode($categories), json_encode($categories), $uid]);

            $pdo->prepare(
                "UPDATE `onboarding_progress`
                 SET `step_category_done` = 1, `step_current` = 4
                 WHERE `user_id` = ?"
            )->execute([$uid]);

            $nextStep = 'notifications';
            break;

        /* ── STEP 4: Notifications (final) ─────────────────────── */
        case 'notifications':
            $notificationAllowed = (bool) ($input['notification_allowed'] ?? false);
            $fcmToken            = substr((string) ($input['fcm_token'] ?? ''), 0, 500);

            // Store FCM token if provided
            if (!empty($fcmToken)) {
                try {
                    $pdo->prepare(
                        "INSERT INTO `push_subscriptions`
                            (`user_id`, `token`, `created_at`)
                         VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                            `token` = VALUES(`token`),
                            `created_at` = NOW()"
                    )->execute([$uid, $fcmToken]);
                } catch (\Throwable $e) {
                    // push_subscriptions may have different schema — non-fatal
                }
            }

            $pdo->prepare(
                "UPDATE `user_preferences`
                 SET `onboarding_completed`    = 1,
                     `onboarding_completed_at` = NOW(),
                     `updated_at`              = NOW()
                 WHERE `user_id` = ?"
            )->execute([$uid]);

            $pdo->prepare(
                "UPDATE `onboarding_progress`
                 SET `step_notification_done` = 1,
                     `completed_at`           = NOW()
                 WHERE `user_id` = ?"
            )->execute([$uid]);

            // Sync onboarding categories to user_interests
            $prefStmt = $pdo->prepare(
                'SELECT onboarding_categories FROM user_preferences WHERE user_id = ?'
            );
            $prefStmt->execute([$uid]);
            $prefRow = $prefStmt->fetch(\PDO::FETCH_ASSOC);

            if (!empty($prefRow['onboarding_categories'])) {
                $onboardingCats = json_decode($prefRow['onboarding_categories'], true) ?? [];
                $interestStmt   = $pdo->prepare(
                    "INSERT INTO `user_interests`
                        (`user_id`, `category_id`, `tag`, `source`, `weight`)
                     SELECT :uid, id, NULL, 'onboarding', 2.00
                     FROM `categories`
                     WHERE slug = :slug
                     ON DUPLICATE KEY UPDATE
                        `weight` = GREATEST(`weight`, 2.00),
                        `source` = 'onboarding'"
                );
                foreach ($onboardingCats as $slug) {
                    if (preg_match('/^[a-z0-9_\-]+$/i', (string) $slug)) {
                        $interestStmt->execute([':uid' => $uid, ':slug' => $slug]);
                    }
                }
            }

            // Queue welcome notification (best-effort)
            try {
                if (!function_exists('getRedis')) {
                    require_once __DIR__ . '/../../../helpers/redis.php';
                }
                getRedis()->rpush('notification_queue', json_encode([
                    'type'    => 'welcome',
                    'user_id' => $uid,
                    'ts'      => time(),
                ]));
            } catch (\Throwable $e) {
                // Non-fatal
            }

            $onboardingComplete = true;
            $nextStep           = null;
            break;
    }

    // Bust Redis prefs cache
    try {
        if (!function_exists('getRedis')) {
            require_once __DIR__ . '/../../../helpers/redis.php';
        }
        getRedis()->del("user_prefs:{$uid}");
    } catch (\Throwable $e) {
        // Non-fatal
    }

} catch (\Throwable $e) {
    error_log('preferences/onboarding.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
}

echo json_encode([
    'success'             => true,
    'step_completed'      => $step,
    'next_step'           => $nextStep,
    'onboarding_complete' => $onboardingComplete,
]);
