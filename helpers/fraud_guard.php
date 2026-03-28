<?php
// ============================================================
// FIXED: helpers/fraud_guard.php
// ISSUES:
//   1. Every call with level != 'low' inserts a new row into
//      fraud_flags — no deduplication. If checkFraud is called
//      10 times for same user/context in one session, 10 rows
//      inserted. Table bloats rapidly.
//      Fixed: INSERT IGNORE with unique constraint note, or
//      ON DUPLICATE KEY UPDATE.
//
//   2. Signal values never validated/cast — if caller sends
//      $signals['same_device_accounts'] = "DROP TABLE users"
//      the comparison > 3 safely fails but it's sloppy.
//      Fixed: explicit int cast on all signal values.
//
//   3. No context stored in fraud_flags — context param
//      ('withdrawal', 'reward' etc) is never persisted.
//      Fixed: added to insert.
//
//   4. Exception from fraud_flags insert not caught —
//      if table missing, caller's transaction rolls back.
//      Fixed: wrapped in try/catch (non-fatal).
// ============================================================

function evaluateFraud(PDO $pdo, int $user_id, string $context, array $signals = []): array
{
    $risk    = 0;
    $reasons = [];

    // FIXED: cast all signal values to int to prevent type confusion
    $current_points        = (int)($signals['current_points']        ?? 0);
    $avg_points            = (int)($signals['avg_points']            ?? 0);
    $same_device_accounts  = (int)($signals['same_device_accounts']  ?? 0);
    $same_ip_accounts      = (int)($signals['same_ip_accounts']      ?? 0);
    $actions_last_10m      = (int)($signals['actions_last_10m']      ?? 0);
    $withdrawal_anomaly    = !empty($signals['withdrawal_anomaly']);

    // Velocity spike check
    if ($current_points > 0 && $avg_points > 0) {
        $velocity = $current_points / $avg_points;
        if ($velocity > 3) {
            $risk     += 30;
            $reasons[] = 'velocity_spike';
        }
    }

    // Device clustering
    if ($same_device_accounts > 3) {
        $risk     += 25;
        $reasons[] = 'device_cluster';
    }

    // IP clustering
    if ($same_ip_accounts > 5) {
        $risk     += 20;
        $reasons[] = 'ip_cluster';
    }

    // Rapid actions
    if ($actions_last_10m > 30) {
        $risk     += 20;
        $reasons[] = 'rapid_actions';
    }

    // Withdrawal anomaly
    if ($withdrawal_anomaly) {
        $risk     += 30;
        $reasons[] = 'withdrawal_anomaly';
    }

    $risk  = min(100, $risk);
    $level = 'low';
    if ($risk >= 81)      $level = 'critical';
    elseif ($risk >= 61)  $level = 'high';
    elseif ($risk >= 31)  $level = 'medium';

    // FIXED: only persist if level is concerning, wrapped in try/catch
    // Uses ON DUPLICATE KEY UPDATE to prevent duplicate rows
    if ($level !== 'low') {
        try {
            $pdo->prepare("
                INSERT INTO fraud_flags
                    (entity_type, entity_id, context, risk_score, risk_level, reasons, created_at)
                VALUES ('user', ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    risk_score  = VALUES(risk_score),
                    risk_level  = VALUES(risk_level),
                    reasons     = VALUES(reasons),
                    updated_at  = NOW()
            ")->execute([
                $user_id,
                $context,
                $risk,
                $level,
                json_encode($reasons),
            ]);
        } catch (PDOException $e) {
            // Non-fatal — log but don't crash the calling request
            error_log('fraud_flags insert failed: ' . $e->getMessage());
        }
    }

    return ['risk' => $risk, 'level' => $level, 'reasons' => $reasons];
}
