<?php
// ============================================================
// FIXED: helpers/kill_switch.php
// ISSUES:
//   1. Platform kill check runs a DB query on EVERY API call.
//      With 1000 req/sec → 1000 DB queries/sec just for this.
//      Fixed: PHP static variable cache — queries DB once per
//      process (PHP-FPM request), not once per checkKillSwitch
//      call (some files called it multiple times).
//
//   2. fetchColumn() returns FALSE when row not found.
//      $platformKill === 'on' check is correct but if the
//      system_settings row is missing entirely, fetchColumn()
//      returns false and platform stays live. Good default but
//      should be explicit.
//
//   3. Missing Content-Type header on error responses —
//      if caller hasn't set it yet, client gets JSON as
//      text/html.
//
//   4. news kill_switch check: fetchColumn() returns false
//      if news not found — in_array(false, ['killed','shadow_ban'])
//      = false, so missing news passes through silently.
//      Should 404 if news not found.
//
//   5. Exception from DB queries not caught — if system_settings
//      table missing, entire request crashes with 500.
// ============================================================

function checkKillSwitch(PDO $pdo, array $opts = []): void
{
    // FIXED: Static cache — platform kill queried once per PHP process
    static $platform_kill_checked = false;
    static $platform_killed       = false;

    if (!$platform_kill_checked) {
        $platform_kill_checked = true;
        try {
            $stmt = $pdo->query(
                "SELECT value FROM system_settings WHERE `key` = 'platform_kill' LIMIT 1"
            );
            $val = $stmt->fetchColumn();
            $platform_killed = ($val === 'on');
        } catch (Exception $e) {
            // system_settings table may not exist — default to not killed
            error_log('kill_switch platform check failed: ' . $e->getMessage());
            $platform_killed = false;
        }
    }

    if ($platform_killed) {
        http_response_code(503);
        header('Content-Type: application/json'); // FIXED
        echo json_encode([
            'success' => false,
            'message' => 'Platform temporarily unavailable',
        ]);
        exit;
    }

    // Content kill
    if (!empty($opts['news_id'])) {
        $news_id = (int)$opts['news_id'];
        try {
            $stmt = $pdo->prepare(
                "SELECT kill_switch FROM news WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$news_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // FIXED: explicit not-found check
            if ($row === false) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Content not found']);
                exit;
            }

            if (in_array($row['kill_switch'], ['killed', 'shadow_ban'], true)) {
                http_response_code(410);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Content unavailable']);
                exit;
            }
        } catch (Exception $e) {
            error_log('kill_switch news check failed: ' . $e->getMessage());
        }
    }

    // User / reporter kill
    if (!empty($opts['user_id'])) {
        $user_id = (int)$opts['user_id'];
        try {
            $stmt = $pdo->prepare(
                "SELECT status FROM users WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$user_id]);
            $status = $stmt->fetchColumn();

            if (in_array($status, ['suspended', 'banned', 'blocked'], true)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Account suspended']);
                exit;
            }
        } catch (Exception $e) {
            error_log('kill_switch user check failed: ' . $e->getMessage());
        }
    }
}
