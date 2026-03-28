<?php
// ============================================================
// auth/rate_limit.php
// Simple DB-based Rate Limiting
//
// PROBLEM THIS SOLVES:
//   Currently NO rate limiting anywhere in the system.
//   - Login endpoint: brute-force attack possible
//   - submit_news: reporter can flood DB with articles
//   - wallet_withdraw: rapid withdrawal attempts
//   - Notification endpoints: spam FCM with 10k calls
//
// USAGE:
//   require_once __DIR__ . '/../auth/rate_limit.php';
//
//   // Block if more than 5 login attempts in 15 minutes:
//   rateLimit($pdo, 'login', $ip, 5, 900);
//
//   // Block if more than 10 news submissions per hour:
//   rateLimit($pdo, 'submit_news', $user_id, 10, 3600);
//
// Requires table (create once):
//   CREATE TABLE rate_limits (
//     id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//     action      VARCHAR(64)  NOT NULL,
//     identifier  VARCHAR(128) NOT NULL,
//     attempts    INT UNSIGNED NOT NULL DEFAULT 1,
//     window_start DATETIME    NOT NULL,
//     INDEX idx_rl_lookup (action, identifier, window_start)
//   );
// ============================================================

/**
 * Check and increment rate limit.
 * Exits with 429 JSON if limit exceeded.
 *
 * @param PDO    $pdo         Database connection
 * @param string $action      Unique action name e.g. 'login', 'submit_news'
 * @param string $identifier  IP address or user_id or firebase_uid
 * @param int    $max         Maximum allowed attempts in window
 * @param int    $window_sec  Time window in seconds
 */
function rateLimit(PDO $pdo, string $action, string $identifier, int $max = 10, int $window_sec = 60): void
{
    $identifier = substr(trim($identifier), 0, 128);
    $action     = substr(trim($action),     0, 64);
    $now        = date('Y-m-d H:i:s');
    $window_ago = date('Y-m-d H:i:s', time() - $window_sec);

    try {
        // Count existing attempts in window
        $stmt = $pdo->prepare("
            SELECT id, attempts
            FROM rate_limits
            WHERE action = ?
              AND identifier = ?
              AND window_start >= ?
            ORDER BY window_start DESC
            LIMIT 1
        ");
        $stmt->execute([$action, $identifier, $window_ago]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ((int)$row['attempts'] >= $max) {
                // Rate limit hit
                http_response_code(429);
                header('Content-Type: application/json');
                header('Retry-After: ' . $window_sec);
                echo json_encode([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $window_sec,
                ]);
                exit;
            }

            // Increment attempts
            $pdo->prepare(
                "UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?"
            )->execute([$row['id']]);

        } else {
            // First attempt in this window
            $pdo->prepare(
                "INSERT INTO rate_limits (action, identifier, attempts, window_start)
                 VALUES (?, ?, 1, ?)"
            )->execute([$action, $identifier, $now]);
        }

        // Cleanup old records (1% chance to avoid doing it every request)
        if (random_int(1, 100) === 1) {
            $pdo->prepare(
                "DELETE FROM rate_limits WHERE window_start < ?"
            )->execute([date('Y-m-d H:i:s', time() - 86400)]); // older than 1 day
        }

    } catch (PDOException $e) {
        // Rate limit table may not exist yet — log and allow request
        error_log('rate_limit error: ' . $e->getMessage());
    }
}

/**
 * Reset rate limit for an identifier (e.g. after successful login).
 */
function resetRateLimit(PDO $pdo, string $action, string $identifier): void
{
    try {
        $pdo->prepare(
            "DELETE FROM rate_limits WHERE action = ? AND identifier = ?"
        )->execute([$action, $identifier]);
    } catch (PDOException $e) {
        error_log('resetRateLimit error: ' . $e->getMessage());
    }
}

/**
 * Get remaining attempts for an action/identifier.
 *
 * @return int  Remaining attempts (0 = blocked)
 */
function getRemainingAttempts(PDO $pdo, string $action, string $identifier, int $max, int $window_sec): int
{
    try {
        $window_ago = date('Y-m-d H:i:s', time() - $window_sec);
        $stmt = $pdo->prepare("
            SELECT attempts FROM rate_limits
            WHERE action = ? AND identifier = ? AND window_start >= ?
            ORDER BY window_start DESC LIMIT 1
        ");
        $stmt->execute([$action, $identifier, $window_ago]);
        $attempts = (int)$stmt->fetchColumn();
        return max(0, $max - $attempts);
    } catch (PDOException $e) {
        return $max; // fail open
    }
}
