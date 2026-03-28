<?php
// ============================================================
// FIXED: admin/viral_boost_control.php
// BUGS:
//   1. Access-Control-Allow-Origin: * on admin endpoint
//   2. status mismatch: action 'stop' → status 'stopped'
//      BUT viral_boosts_active.php checks status='active','scheduled','paused'
//      AND viral_boost_create.php expires status='active' boosts.
//      'stopped' is not in the schema enum — should be 'completed'
//      to match the rest of the system. FIXED to 'completed'.
//   3. action whitelist: ['pause','resume','stop'] — but
//      dashboard sends action='stop' and admin panel sends
//      action='complete'. Added 'complete' as alias for 'stop'.
//   4. viral_boost_history table insert — if table missing,
//      transaction rolls back silently. Wrapped in try/catch.
//   5. UPDATE news SET is_viral_boosted=0 etc on 'stop' —
//      columns may not exist. Wrapped in try/catch.
// ============================================================
header('Content-Type: application/json');
// FIXED: removed wildcard CORS

require_once '../geo/config.php';
require_once '../geo/response.php';

session_start();
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    sendResponse(false, null, 'Unauthorized', 401);
}

$input    = json_decode(file_get_contents('php://input'), true);
$boost_id = (int)($input['boost_id'] ?? 0);
$action   = trim($input['action']   ?? '');

// FIXED: accept 'complete' as alias for 'stop' (used by admin panel)
$allowed_actions = ['pause', 'resume', 'stop', 'complete'];
if (!$boost_id || !in_array($action, $allowed_actions, true)) {
    sendResponse(false, null, 'Invalid request. action must be: pause|resume|stop|complete', 400);
}

// Normalize 'complete' → 'stop' internally
if ($action === 'complete') {
    $action = 'stop';
}

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        "SELECT id, news_id, duration_hours FROM viral_boosts WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$boost_id]);
    $boost = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$boost) {
        throw new Exception('Boost not found');
    }

    // FIXED: 'stop' maps to 'completed' not 'stopped'
    $new_status = match($action) {
        'pause'  => 'paused',
        'resume' => 'active',
        'stop'   => 'completed',
        default  => 'completed',
    };

    $pdo->prepare(
        "UPDATE viral_boosts
         SET status = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$new_status, $boost_id]);

    // Resume → extend expiry
    if ($action === 'resume') {
        $pdo->prepare(
            "UPDATE viral_boosts
             SET end_time = DATE_ADD(NOW(), INTERVAL duration_hours HOUR)
             WHERE id = ?"
        )->execute([$boost_id]);
    }

    // Stop → reset news viral columns (non-fatal if columns missing)
    if ($action === 'stop') {
        try {
            $pdo->prepare("
                UPDATE news SET
                    is_viral_boosted       = 0,
                    viral_views_multiplier = 1.00,
                    admin_boost_level      = 'none',
                    admin_boost_multiplier = 1,
                    boosted_until          = NULL,
                    is_pinned              = 0
                WHERE id = ?
            ")->execute([$boost['news_id']]);
        } catch (PDOException $e) {
            error_log('viral_boost_control news reset failed: ' . $e->getMessage());
        }
    }

    // History log (non-fatal)
    try {
        $pdo->prepare(
            "INSERT INTO viral_boost_history (boost_id, action, admin_user_id, notes)
             VALUES (?, ?, ?, ?)"
        )->execute([
            $boost_id,
            $action,
            $admin_id,
            "Boost {$action} by admin",
        ]);
    } catch (PDOException $e) {
        error_log('viral_boost_history insert failed: ' . $e->getMessage());
    }

    $pdo->commit();

    sendResponse(true, [
        'boost_id' => $boost_id,
        'status'   => $new_status,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('viral_boost_control error: ' . $e->getMessage());
    sendResponse(false, null, $e->getMessage(), 500);
}
