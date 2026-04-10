<?php
/**
 * web/api/languages.php
 * Multi-language support API
 *
 * GET  → Returns all active supported_languages ordered by sort_order.
 *   Response: { "success": true, "languages": [ { id, code, name, native_name, script } ] }
 *
 * POST → Save user language preferences.
 *   Body (JSON): { "languages": ["hi", "en", "bho"] }
 *   Headers: Authorization: Bearer <firebase_id_token>
 *   Response: { "success": true, "message": "Languages saved", "languages": ["hi","en"] }
 *
 * Rules:
 *   - First element in the array is treated as primary language.
 *   - If no languages supplied or user is unauthenticated, returns error.
 *   - Default languages (hi + en) are returned as fallback for unauthenticated reads.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../auth/firebase.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list all active languages ────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT id, code, name, native_name, script, sort_order
               FROM supported_languages
              WHERE is_active = 1
              ORDER BY sort_order ASC, name ASC"
        );
        $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalise types
        foreach ($languages as &$lang) {
            $lang['id']         = (int)$lang['id'];
            $lang['sort_order'] = (int)$lang['sort_order'];
        }
        unset($lang);

        echo json_encode([
            'success'   => true,
            'languages' => $languages,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// ── POST: save user language preferences ─────────────────────────────────────
if ($method === 'POST') {
    // Authenticate user
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

    // Verify Firebase token
    $firebaseUid = null;
    try {
        $decoded     = verifyFirebaseToken($idToken);
        $firebaseUid = $decoded['sub'] ?? null;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    if (empty($firebaseUid)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    // Parse request body
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $langCodes = $body['languages'] ?? [];

    if (!is_array($langCodes) || empty($langCodes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'At least one language is required']);
        exit;
    }

    // Sanitise: strip non-alphanumeric, limit length
    $langCodes = array_unique(array_map(function ($c) {
        return preg_replace('/[^a-z]/i', '', mb_strtolower(trim((string)$c)));
    }, $langCodes));
    $langCodes = array_values(array_filter($langCodes, fn($c) => strlen($c) >= 2 && strlen($c) <= 10));

    if (empty($langCodes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid language codes']);
        exit;
    }

    // Validate codes exist in supported_languages
    $placeholders = implode(',', array_fill(0, count($langCodes), '?'));
    $validStmt    = $pdo->prepare(
        "SELECT code FROM supported_languages WHERE code IN ($placeholders) AND is_active = 1"
    );
    $validStmt->execute($langCodes);
    $validCodes = $validStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validCodes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid language codes provided']);
        exit;
    }

    // Get internal user_id from users table
    try {
        $userStmt = $pdo->prepare(
            "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
        );
        $userStmt->execute([$firebaseUid]);
        $userId = $userStmt->fetchColumn();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }

    if (!$userId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Save preferences: replace existing entries for this user
    try {
        $pdo->beginTransaction();

        // Remove old preferences
        $delStmt = $pdo->prepare("DELETE FROM user_languages WHERE user_id = ?");
        $delStmt->execute([$userId]);

        // Insert new preferences; first code is primary
        $insStmt = $pdo->prepare(
            "INSERT INTO user_languages (user_id, language_code, is_primary)
             VALUES (?, ?, ?)"
        );
        foreach ($validCodes as $i => $code) {
            $insStmt->execute([$userId, $code, ($i === 0) ? 1 : 0]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save languages']);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Languages saved',
        'languages' => array_values($validCodes),
    ]);
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
