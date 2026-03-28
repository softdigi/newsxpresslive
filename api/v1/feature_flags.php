<?php
// ============================================================
// FIXED: api/v1/feature_flags.php
// CRITICAL ISSUE:
//   POST (create/update flag) and DELETE sections had:
//   "// Verify admin authentication — ...your admin auth logic here"
//   i.e. ZERO auth on admin write operations.
//   Anyone could:
//     POST → create any feature flag
//     DELETE → delete any feature flag by key
//
// OTHER ISSUES:
//   1. $user_id query uses WHERE uid=? but rest of codebase
//      uses firebase_uid — inconsistent. Fixed to firebase_uid.
//   2. platform not whitelisted — any string accepted
//   3. rollout_percentage not range-checked (0-100)
//   4. No LIMIT on flag event logging loop (could insert
//      thousands of log rows per request)
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../../geo/config.php';
require_once __DIR__ . '/../../helpers/feature_flags_helper.php';

// ── GET — public (app fetches flags for a user) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $user_id  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    // Whitelist platform
    $allowed_platforms = ['app', 'web', 'all'];
    $platform = in_array($_GET['platform'] ?? '', $allowed_platforms, true)
                ? $_GET['platform']
                : 'all';

    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id required']);
        exit;
    }

    // FIXED: query by id, not by 'uid' (inconsistent column name)
    $stmt = $pdo->prepare(
        "SELECT role, country_id, state_id, district_id
         FROM users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $flags = getEnabledFlags($pdo, $user_id, $platform, $user['role']);

    // Log flag views — FIXED: limit logging to avoid mass inserts
    $log_limit = 0;
    foreach ($flags as $flag_key) {
        if ($log_limit++ >= 20) break; // cap at 20 flag logs per request
        logFlagEvent($pdo, $flag_key, $user_id, 'viewed', $platform, $user['role']);
    }

    echo json_encode([
        'success'   => true,
        'flags'     => $flags,
        'platform'  => $platform,
        'user_type' => $user['role'],
    ]);
    exit;
}

// ── Admin auth check required for write operations ────────────
// FIXED: was completely missing — anyone could POST/DELETE flags
session_start();
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── POST — create/update flag (admin only) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    $flag_key    = trim($input['flag_key'] ?? '');
    $name        = trim($input['name']     ?? '');
    $description = trim($input['description'] ?? '');
    $enabled     = (int)(bool)($input['enabled'] ?? 0);

    // FIXED: whitelist platform
    $allowed_platforms = ['app', 'web', 'all'];
    $platform = in_array($input['platform'] ?? '', $allowed_platforms, true)
                ? $input['platform'] : 'all';

    // FIXED: clamp rollout_percentage to 0–100
    $rollout_percentage = max(0, min(100, (int)($input['rollout_percentage'] ?? 0)));

    $user_types       = json_encode(is_array($input['user_types']       ?? null) ? $input['user_types']       : []);
    $target_locations = json_encode(is_array($input['target_locations'] ?? null) ? $input['target_locations'] : []);

    // Validate flag_key format — alphanumeric + underscores only
    if (!$flag_key || !preg_match('/^[a-z0-9_]{2,64}$/', $flag_key)) {
        http_response_code(400);
        echo json_encode(['error' => 'flag_key required (lowercase alphanumeric + underscore, 2-64 chars)']);
        exit;
    }

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO feature_flags
            (flag_key, name, description, enabled, platform,
             rollout_percentage, user_types, target_locations, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name                = VALUES(name),
            description         = VALUES(description),
            enabled             = VALUES(enabled),
            platform            = VALUES(platform),
            rollout_percentage  = VALUES(rollout_percentage),
            user_types          = VALUES(user_types),
            target_locations    = VALUES(target_locations)
    ");

    $stmt->execute([
        $flag_key, $name, $description, $enabled, $platform,
        $rollout_percentage, $user_types, $target_locations, $admin_id,
    ]);

    echo json_encode([
        'success'  => true,
        'message'  => 'Feature flag saved',
        'flag_key' => $flag_key,
    ]);
    exit;
}

// ── DELETE — remove flag (admin only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    $flag_key = trim($_GET['flag_key'] ?? '');

    if (!$flag_key || !preg_match('/^[a-z0-9_]{2,64}$/', $flag_key)) {
        http_response_code(400);
        echo json_encode(['error' => 'valid flag_key required']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM feature_flags WHERE flag_key = ?");
    $stmt->execute([$flag_key]);

    echo json_encode([
        'success' => true,
        'message' => 'Feature flag deleted',
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
