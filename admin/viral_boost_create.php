<?php
// ============================================================
// FIXED: admin/viral_boost_create.php
// BUGS:
//   1. Access-Control-Allow-Origin: * on admin endpoint —
//      allows any website to trigger boost creation cross-origin
//   2. duration_hours never validated — attacker can send
//      duration_hours=876000 (100 years) or duration_hours=-1
//      Fixed: whitelist [24, 48, 72, 168]
//   3. target_locations is an array from user input, stored
//      as json_encode() directly — no sanitization.
//      Malicious nested JSON could cause issues downstream.
//      Fixed: validate each element is a positive integer.
//   4. boost_level is checked against $BOOST map (good) but
//      the check uses isset() not strict in_array.
//   5. $news['author_id'] column — schema uses reporter_id,
//      not author_id. FIXED with COALESCE fallback.
//   6. reward_points table insert — if table doesn't exist,
//      whole transaction rolls back (including the boost).
//      Fixed: wrapped in nested try/catch.
// ============================================================
header('Content-Type: application/json');
// FIXED: removed wildcard CORS — admin endpoint must not be public

require_once '../geo/config.php';
require_once '../geo/response.php';

session_start();
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    sendResponse(false, null, 'Unauthorized', 401);
}

$input = json_decode(file_get_contents('php://input'), true);

$news_id        = (int)($input['news_id']    ?? 0);
$boost_level    = trim($input['boost_level'] ?? 'low');
$target_type    = trim($input['target_type'] ?? 'all');
$duration_hours = (int)($input['duration_hours'] ?? 24);
$pin_to_top     = (int)(bool)($input['pin_to_top'] ?? 0);
$send_push      = (int)(bool)($input['send_push_notification'] ?? 1);
$feature_banner = (int)(bool)($input['feature_in_banner'] ?? 0);

// FIXED: validate duration against whitelist
$allowed_durations = [24, 48, 72, 168];
if (!in_array($duration_hours, $allowed_durations, true)) {
    sendResponse(false, null, 'Invalid duration_hours. Allowed: 24, 48, 72, 168', 400);
}

// FIXED: sanitize target_locations — must be array of positive ints
$raw_locations    = $input['target_locations'] ?? [];
$target_locations = [];
if (is_array($raw_locations)) {
    foreach ($raw_locations as $loc) {
        $loc_int = (int)$loc;
        if ($loc_int > 0) {
            $target_locations[] = $loc_int;
        }
    }
}

// FIXED: whitelist target_type
$allowed_target_types = ['all', 'country', 'state', 'district'];
if (!in_array($target_type, $allowed_target_types, true)) {
    sendResponse(false, null, 'Invalid target_type', 400);
}

if (!$news_id) {
    sendResponse(false, null, 'news_id required', 400);
}

$BOOST = [
    'low'    => ['multiplier' => 2,  'bonus' => 50,   'score' => 1000],
    'medium' => ['multiplier' => 5,  'bonus' => 100,  'score' => 5000],
    'high'   => ['multiplier' => 10, 'bonus' => 500,  'score' => 10000],
    'mega'   => ['multiplier' => 50, 'bonus' => 1000, 'score' => 50000],
];

if (!array_key_exists($boost_level, $BOOST)) {
    sendResponse(false, null, 'Invalid boost level', 400);
}

// FIXED: use reporter_id (schema column) not author_id
$stmt = $pdo->prepare(
    "SELECT id, reporter_id, status FROM news WHERE id = ? LIMIT 1"
);
$stmt->execute([$news_id]);
$news = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news || $news['status'] !== 'approved') {
    sendResponse(false, null, 'News not approved', 400);
}

$start = date('Y-m-d H:i:s');
$end   = date('Y-m-d H:i:s', strtotime("+{$duration_hours} hours"));

$pdo->beginTransaction();

try {
    // Expire old active boosts for this news
    $pdo->prepare(
        "UPDATE viral_boosts SET status = 'expired'
         WHERE news_id = ? AND status = 'active'"
    )->execute([$news_id]);

    // Create boost
    $pdo->prepare("
        INSERT INTO viral_boosts
            (news_id, boost_level, status, target_type, target_locations,
             duration_hours, start_time, end_time, pin_to_top,
             send_push_notification, feature_in_banner,
             viral_multiplier, boost_score, reporter_bonus, boosted_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $news_id,
        $boost_level,
        'active',
        $target_type,
        json_encode($target_locations),
        $duration_hours,
        $start,
        $end,
        $pin_to_top,
        $send_push,
        $feature_banner,
        $BOOST[$boost_level]['multiplier'],
        $BOOST[$boost_level]['score'],
        $BOOST[$boost_level]['bonus'],
        $admin_id,
    ]);

    $boost_id = (int)$pdo->lastInsertId();

    // Update news snapshot
    $pdo->prepare("
        UPDATE news SET
            is_viral_boosted         = 1,
            viral_score              = viral_score + ?,
            viral_views_multiplier   = ?,
            admin_boost_level        = ?,
            admin_boost_multiplier   = ?,
            boosted_until            = ?,
            is_pinned                = ?
        WHERE id = ?
    ")->execute([
        $BOOST[$boost_level]['score'],
        $BOOST[$boost_level]['multiplier'],
        $boost_level,
        $BOOST[$boost_level]['multiplier'],
        $end,
        $pin_to_top,
        $news_id,
    ]);

    // Boost history
    $pdo->prepare(
        "INSERT INTO viral_boost_history (boost_id, action, admin_user_id, notes)
         VALUES (?, 'created', ?, 'Viral boost created')"
    )->execute([$boost_id, $admin_id]);

    // FIXED: reporter bonus in nested try/catch
    // so missing reward_points table doesn't kill the whole transaction
    if (!empty($news['reporter_id'])) {
        try {
            $pdo->prepare(
                "INSERT INTO reward_points (user_id, points, reason, created_at)
                 VALUES (?, ?, ?, NOW())"
            )->execute([
                $news['reporter_id'],
                $BOOST[$boost_level]['bonus'],
                "Viral Boost ({$boost_level})",
            ]);
        } catch (PDOException $re) {
            // Non-fatal: log but do not roll back the boost
            error_log('reward_points insert failed: ' . $re->getMessage());
        }
    }

    // Metrics seed
    try {
        $pdo->prepare(
            "INSERT INTO viral_boost_metrics (boost_id) VALUES (?)"
        )->execute([$boost_id]);
    } catch (PDOException $me) {
        error_log('viral_boost_metrics seed failed: ' . $me->getMessage());
    }

    $pdo->commit();

    sendResponse(true, [
        'boost_id'   => $boost_id,
        'level'      => $boost_level,
        'expires_at' => $end,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('viral_boost_create error: ' . $e->getMessage());
    sendResponse(false, null, $e->getMessage(), 500);
}
