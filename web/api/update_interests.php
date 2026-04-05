<?php
/**
 * web/api/update_interests.php
 * Feature 1 — Update User Interests (onboarding + explicit)
 *
 * POST /web/api/update_interests.php
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 * Body (JSON):
 *   {
 *     "source":      "explicit",       // "onboarding" | "explicit"
 *     "categories": [                  // array of category objects
 *       {"category_id": 3, "weight": 2.0},
 *       {"category_id": 7, "weight": 1.5}
 *     ],
 *     "tags": [                        // optional tag interests
 *       {"tag": "cricket", "weight": 3.0}
 *     ]
 *   }
 *
 * Behaviour
 * ─────────
 * - Upserts each entry into user_interests (source, weight).
 * - When source = "explicit" any existing "onboarding" row for the same
 *   category is replaced so the explicit preference wins.
 * - Returns {"success": true, "upserted": N}
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../auth/firebase.php';

/* ── Auth ────────────────────────────────────────────────────────────── */
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

$firebaseUid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($firebaseUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token payload']);
    exit;
}

/* ── Input ───────────────────────────────────────────────────────────── */
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$source     = $input['source'] ?? 'explicit';
$categories = $input['categories'] ?? [];
$tags       = $input['tags']       ?? [];

$allowedSources = ['onboarding', 'explicit'];
if (!in_array($source, $allowedSources, true)) {
    $source = 'explicit';
}

if (!is_array($categories) && !is_array($tags)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'categories or tags array required']);
    exit;
}

/* ── Upsert ──────────────────────────────────────────────────────────── */
$upserted = 0;

try {
    $catStmt = $pdo->prepare(
        'INSERT INTO user_interests (user_id, category_id, tag, source, weight)
         VALUES (:uid, :cid, NULL, :src, :w)
         ON DUPLICATE KEY UPDATE
             source = VALUES(source),
             weight = VALUES(weight)'
    );

    foreach ($categories as $entry) {
        $categoryId = (int)($entry['category_id'] ?? 0);
        $weight     = min(5.0, max(0.1, (float)($entry['weight'] ?? 1.0)));

        if ($categoryId <= 0) {
            continue;
        }

        $catStmt->execute([
            ':uid' => $firebaseUid,
            ':cid' => $categoryId,
            ':src' => $source,
            ':w'   => number_format($weight, 2, '.', ''),
        ]);
        $upserted++;
    }

    $tagStmt = $pdo->prepare(
        'INSERT INTO user_interests (user_id, category_id, tag, source, weight)
         VALUES (:uid, NULL, :tag, :src, :w)
         ON DUPLICATE KEY UPDATE
             source = VALUES(source),
             weight = VALUES(weight)'
    );

    foreach ($tags as $entry) {
        $tag    = trim((string)($entry['tag'] ?? ''));
        $weight = min(5.0, max(0.1, (float)($entry['weight'] ?? 1.0)));

        if ($tag === '' || strlen($tag) > 100) {
            continue;
        }

        $tagStmt->execute([
            ':uid' => $firebaseUid,
            ':tag' => $tag,
            ':src' => $source,
            ':w'   => number_format($weight, 2, '.', ''),
        ]);
        $upserted++;
    }

    echo json_encode(['success' => true, 'upserted' => $upserted]);

} catch (PDOException $e) {
    error_log('update_interests: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
