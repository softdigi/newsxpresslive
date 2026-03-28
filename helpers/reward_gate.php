<?php
// ============================================================
// FIXED: helpers/reward_gate.php
// ISSUES:
//   1. $points parameter is int-typed but never validated > 0.
//      creditReward($pdo, $user_id, -500, 'test') would insert
//      a negative points record — balance manipulation.
//
//   2. trust_score fetchColumn() returns false if no row —
//      intval(false) = 0, so new users with no trust score
//      record are silently blocked (< 200 check).
//      This may be intentional but should be explicit.
//
//   3. fraud signals array only has actions_last_10m = 0 —
//      always hardcoded to 0, so rapid_actions fraud signal
//      never triggers from reward_gate. This means reward
//      abuse via rapid actions is not caught here.
//      Fixed: query actual recent actions from DB.
//
//   4. No transaction — INSERT could fail silently.
// ============================================================

require_once __DIR__ . '/fraud_guard.php';

function creditReward(PDO $pdo, int $user_id, int $points, string $reason): bool
{
    // FIXED: validate points is positive
    if ($points <= 0) {
        error_log("creditReward called with non-positive points: {$points} for user {$user_id}");
        return false;
    }

    // Cap reason length
    $reason = mb_substr(trim($reason), 0, 255, 'UTF-8');

    // Trust gate
    $trust = $pdo->prepare(
        "SELECT trust_score FROM user_trust_scores WHERE user_id = ? LIMIT 1"
    );
    $trust->execute([$user_id]);
    $row        = $trust->fetch(PDO::FETCH_ASSOC);
    $trustScore = $row ? (int)$row['trust_score'] : 0;

    if ($trustScore < 200) {
        return false; // below trust threshold — manual review
    }

    // FIXED: get real actions_last_10m from DB instead of hardcoded 0
    $actStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reward_points
         WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
    $actStmt->execute([$user_id]);
    $actions_last_10m = (int)$actStmt->fetchColumn();

    $signals = [
        'actions_last_10m' => $actions_last_10m,
    ];

    $fraud = evaluateFraud($pdo, $user_id, 'reward', $signals);
    if (in_array($fraud['level'], ['high', 'critical'], true)) {
        return false;
    }

    // FIXED: wrap in try/catch — if insert fails, return false cleanly
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO reward_points (user_id, points, reason, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$user_id, $points, $reason]);
        return true;
    } catch (PDOException $e) {
        error_log('creditReward insert failed: ' . $e->getMessage());
        return false;
    }
}
