<?php
// ============================================================
// FIXED: helpers/feature_flags_helper.php
// ISSUES:
//   1. getEnabledFlags() location query uses WHERE uid = ?
//      but rest of codebase uses 'id' as primary key and
//      'firebase_uid' for Firebase UID. 'uid' column likely
//      doesn't exist → query silently returns no location →
//      location targeting never works.
//      Fixed: WHERE id = ?  (integer user_id passed in)
//
//   2. logFlagEvent() called for EVERY flag in the loop in
//      feature_flags.php — N inserts per request. With 20
//      flags → 20 INSERT queries on every app launch.
//      This function should be async/batched; for now,
//      made it non-fatal and caller caps at 20 (already done
//      in fixed feature_flags.php). No change here but noted.
//
//   3. getFlagAnalytics() $days not validated — any value
//      accepted. Fixed: clamp to 1–90.
//
//   4. addFlagOverride() $expires_hours not validated —
//      negative value gives past date, 0 gives immediate
//      expiry. Fixed: clamp to 1–8760 (1 year max).
//
//   5. logFlagEvent() exception not caught — if
//      feature_flag_events table missing, entire request
//      crashes. Fixed: try/catch.
// ============================================================

/**
 * Get all enabled feature flags for a specific user.
 */
function getEnabledFlags(PDO $pdo, int $user_id, string $platform = 'all', string $user_role = 'user'): array
{
    $enabled_flags = [];

    // FIXED: WHERE id = ? (not uid = ?)
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('IN')                                       AS country_code,
            COALESCE(CONCAT('IN-', s.state_code),    '')      AS state_code,
            COALESCE(CONCAT('IN-', s.state_code, '-', d.district_code), '') AS district_code
        FROM users u
        LEFT JOIN states    s ON u.state_id    = s.id
        LEFT JOIN districts d ON u.district_id = d.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'country_code'  => 'IN',
        'state_code'    => '',
        'district_code' => '',
    ];

    // Fetch active flags for this platform
    $stmt = $pdo->prepare("
        SELECT id, flag_key, user_types, target_locations, rollout_percentage
        FROM feature_flags
        WHERE enabled = 1
          AND (platform = ? OR platform = 'all')
          AND (start_date IS NULL OR start_date <= NOW())
          AND (end_date   IS NULL OR end_date   >= NOW())
    ");
    $stmt->execute([$platform]);
    $flags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($flags as $flag) {
        // Check user override
        $override = checkOverride($pdo, $flag['id'], $user_id);
        if ($override !== null) {
            if ($override) $enabled_flags[] = $flag['flag_key'];
            continue;
        }

        // User type targeting
        $user_types = json_decode($flag['user_types'] ?? '[]', true);
        if (!empty($user_types) && !in_array($user_role, $user_types, true)) {
            continue;
        }

        // Location targeting
        $target_locations = json_decode($flag['target_locations'] ?? '[]', true);
        if (!empty($target_locations)) {
            $match = false;
            foreach ($target_locations as $target) {
                if (
                    $target === $location['country_code']  ||
                    ($location['state_code']    && $target === $location['state_code'])   ||
                    ($location['district_code'] && $target === $location['district_code'])
                ) {
                    $match = true;
                    break;
                }
            }
            if (!$match) continue;
        }

        // Rollout percentage — consistent hash
        if ((int)$flag['rollout_percentage'] < 100) {
            $hash = abs(crc32($user_id . $flag['flag_key'])) % 100;
            if ($hash >= (int)$flag['rollout_percentage']) continue;
        }

        $enabled_flags[] = $flag['flag_key'];
    }

    return $enabled_flags;
}

/**
 * Check if user has a specific override for a flag.
 * Returns true/false if override exists, null if no override.
 */
function checkOverride(PDO $pdo, int $flag_id, int $user_id): ?bool
{
    $stmt = $pdo->prepare("
        SELECT force_enabled FROM feature_flag_overrides
        WHERE flag_id = ? AND user_id = ?
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$flag_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (bool)$result['force_enabled'] : null;
}

/**
 * Check if a specific flag is enabled for a user.
 */
function isFeatureEnabled(PDO $pdo, string $flag_key, int $user_id, string $platform = 'all', string $user_role = 'user'): bool
{
    return in_array($flag_key, getEnabledFlags($pdo, $user_id, $platform, $user_role), true);
}

/**
 * Log feature flag event for analytics.
 * FIXED: wrapped in try/catch — non-fatal if table missing.
 */
function logFlagEvent(PDO $pdo, string $flag_key, int $user_id, string $event_type, string $platform, string $user_type): void
{
    try {
        $pdo->prepare("
            INSERT INTO feature_flag_events (flag_id, user_id, event_type, platform, user_type)
            SELECT id, ?, ?, ?, ?
            FROM feature_flags WHERE flag_key = ?
            LIMIT 1
        ")->execute([$user_id, $event_type, $platform, $user_type, $flag_key]);
    } catch (PDOException $e) {
        error_log('logFlagEvent failed: ' . $e->getMessage());
    }
}

/**
 * Add/update a flag override for a user (admin/testing).
 * FIXED: expires_hours clamped to 1–8760.
 */
function addFlagOverride(PDO $pdo, string $flag_key, int $user_id, bool $force_enabled, string $reason = '', int $expires_hours = 24): void
{
    // FIXED: clamp expires_hours
    $expires_hours = max(1, min(8760, $expires_hours));
    $expires_at    = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));

    $pdo->prepare("
        INSERT INTO feature_flag_overrides (flag_id, user_id, force_enabled, reason, expires_at)
        SELECT id, ?, ?, ?, ?
        FROM feature_flags WHERE flag_key = ?
        LIMIT 1
        ON DUPLICATE KEY UPDATE
            force_enabled = VALUES(force_enabled),
            reason        = VALUES(reason),
            expires_at    = VALUES(expires_at)
    ")->execute([$user_id, (int)$force_enabled, $reason, $expires_at, $flag_key]);
}

/**
 * Get analytics for a flag.
 * FIXED: $days clamped to 1–90.
 */
function getFlagAnalytics(PDO $pdo, string $flag_key, int $days = 7): array
{
    // FIXED: validate days
    $days = max(1, min(90, $days));

    $stmt = $pdo->prepare("
        SELECT
            DATE(e.created_at)          AS date,
            e.event_type,
            COUNT(*)                    AS count,
            COUNT(DISTINCT e.user_id)   AS unique_users
        FROM feature_flag_events e
        JOIN feature_flags f ON e.flag_id = f.id
        WHERE f.flag_key = ?
          AND e.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(e.created_at), e.event_type
        ORDER BY date DESC
    ");
    $stmt->execute([$flag_key, $days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
