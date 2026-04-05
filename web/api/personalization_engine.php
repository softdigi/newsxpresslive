<?php
/**
 * web/api/personalization_engine.php
 * Feature 3 — Rule-Based Personalization Engine
 *
 * Applies active rules from personalization_rules to update
 * every user's interest weights in user_interests.
 *
 * Designed to be called by a cron job (e.g. once per hour) OR
 * via an authenticated HTTP request for on-demand processing.
 *
 * ── CLI / Cron usage ───────────────────────────────────────────────────
 *   php personalization_engine.php
 *   php personalization_engine.php --user_id=<firebase_uid>
 *
 * ── HTTP usage (admin-only) ────────────────────────────────────────────
 *   POST /web/api/personalization_engine.php
 *   Headers: Authorization: Bearer <firebase_admin_id_token>
 *   Body:    {"user_id": "optional_uid"}   // omit to process all users
 *
 * Supported rule types
 * ────────────────────
 * read_boost
 *   condition: {"min_reads": 3, "window_days": 7}
 *   action:    {"weight_delta": 0.50, "max_weight": 5.00}
 *   → if reads(category, user, last N days) >= min_reads → add delta
 *
 * decay
 *   condition: {"idle_days": 14}
 *   action:    {"weight_factor": 0.90, "min_weight": 0.10}
 *   → if last read for category > idle_days ago → multiply weight
 *
 * trending_boost
 *   condition: {"min_trending_articles": 2}
 *   action:    {"weight_delta": 0.20, "max_weight": 5.00}
 *   → if category has >= N trending articles → boost weight for all users
 *     who already have that category interest
 *
 * recency_boost
 *   condition: {"hours_since_publish": 24}
 *   action:    {"weight_delta": 0.10, "max_weight": 5.00}
 *   → boost interests for categories that published fresh content
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
define('IS_CLI', PHP_SAPI === 'cli');

if (!IS_CLI) {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
}

require_once __DIR__ . '/../includes/config.php';

// HTTP mode requires admin auth
$targetUserId = null;
if (!IS_CLI) {
    require_once __DIR__ . '/../../auth/firebase.php';

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

    $callerUid = $payload['sub'] ?? $payload['uid'] ?? '';
    // Only admins/super_admins may run the engine via HTTP
    $adminCheck = $pdo->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? AND role IN ('admin','super_admin') LIMIT 1"
    );
    $adminCheck->execute([$callerUid]);
    if (!$adminCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $body         = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetUserId = ($body['user_id'] ?? '') ?: null;
} else {
    // CLI: optional --user_id=xxx argument
    foreach ($argv as $arg) {
        if (strpos($arg, '--user_id=') === 0) {
            $targetUserId = substr($arg, strlen('--user_id='));
        }
    }
}

/* ── Load active rules ordered by priority ───────────────────────────── */
try {
    $ruleStmt = $pdo->query(
        "SELECT id, rule_name, rule_type, condition_json, action_json
         FROM   personalization_rules
         WHERE  is_active = 1
         ORDER  BY priority ASC, id ASC"
    );
    $rules = $ruleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg = 'personalization_engine: failed to load rules: ' . $e->getMessage();
    error_log($msg);
    if (IS_CLI) {
        echo $msg . PHP_EOL;
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if (empty($rules)) {
    $out = ['success' => true, 'message' => 'No active rules', 'updates' => 0];
    IS_CLI ? print_r($out) : print(json_encode($out));
    exit;
}

/* ── Helper: fetch distinct user IDs that have interests ─────────────── */
function getUsersToProcess(PDO $pdo, ?string $targetUserId): array
{
    if ($targetUserId !== null) {
        return [$targetUserId];
    }
    $stmt = $pdo->query('SELECT DISTINCT user_id FROM user_interests');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/* ── Rule processors ─────────────────────────────────────────────────── */

/**
 * read_boost: if user read ≥ min_reads articles from a category in
 *             the last window_days → add weight_delta to that category.
 */
function applyReadBoost(
    PDO    $pdo,
    string $userId,
    array  $condition,
    array  $action
): int {
    $minReads   = (int)($condition['min_reads']   ?? 3);
    $windowDays = (int)($condition['window_days'] ?? 7);
    $delta      = (float)($action['weight_delta'] ?? 0.50);
    $maxWeight  = (float)($action['max_weight']   ?? 5.00);

    // Find categories where user hit the read threshold recently
    $stmt = $pdo->prepare(
        'SELECT n.category_id, COUNT(*) AS cnt
         FROM   user_read_history urh
         JOIN   news n ON n.id = urh.news_id
         WHERE  urh.user_id  = :uid
           AND  urh.read_at >= NOW() - INTERVAL :days DAY
           AND  n.category_id IS NOT NULL
         GROUP  BY n.category_id
         HAVING cnt >= :min'
    );
    $stmt->bindValue(':uid',  $userId,    PDO::PARAM_STR);
    $stmt->bindValue(':days', $windowDays, PDO::PARAM_INT);
    $stmt->bindValue(':min',  $minReads,   PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($rows as $row) {
        $cid = (int)$row['category_id'];
        $pdo->prepare(
            'INSERT INTO user_interests (user_id, category_id, tag, source, weight)
             VALUES (:uid, :cid, NULL, \'behavioral\', :w)
             ON DUPLICATE KEY UPDATE
                 weight = LEAST(weight + :delta, :max),
                 source = \'behavioral\''
        )->execute([
            ':uid'   => $userId,
            ':cid'   => $cid,
            ':w'     => round(min($delta, $maxWeight), 2),
            ':delta' => $delta,
            ':max'   => $maxWeight,
        ]);
        $updated++;
    }

    return $updated;
}

/**
 * decay: if user has not read any article from a category in
 *        the last idle_days → multiply weight by weight_factor
 *        (floor at min_weight so interests never disappear entirely).
 */
function applyDecay(
    PDO    $pdo,
    string $userId,
    array  $condition,
    array  $action
): int {
    $idleDays   = (int)($condition['idle_days']    ?? 14);
    $factor     = (float)($action['weight_factor'] ?? 0.90);
    $minWeight  = (float)($action['min_weight']    ?? 0.10);

    // Categories the user has interests in but hasn't read recently
    $stmt = $pdo->prepare(
        'SELECT ui.category_id, ui.weight
         FROM   user_interests ui
         WHERE  ui.user_id     = :uid
           AND  ui.category_id IS NOT NULL
           AND  NOT EXISTS (
               SELECT 1
               FROM   user_read_history urh
               JOIN   news n ON n.id = urh.news_id
               WHERE  urh.user_id     = :uid2
                 AND  n.category_id   = ui.category_id
                 AND  urh.read_at    >= NOW() - INTERVAL :days DAY
           )'
    );
    $stmt->bindValue(':uid',  $userId,   PDO::PARAM_STR);
    $stmt->bindValue(':uid2', $userId,   PDO::PARAM_STR);
    $stmt->bindValue(':days', $idleDays, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($rows as $row) {
        $cid       = (int)$row['category_id'];
        $newWeight = max((float)$row['weight'] * $factor, $minWeight);
        $pdo->prepare(
            'UPDATE user_interests
             SET    weight = :w
             WHERE  user_id = :uid AND category_id = :cid'
        )->execute([
            ':w'   => round($newWeight, 2),
            ':uid' => $userId,
            ':cid' => $cid,
        ]);
        $updated++;
    }

    return $updated;
}

/**
 * trending_boost: for each category that currently has
 *                 ≥ min_trending_articles trending articles,
 *                 boost all users who have that category interest.
 * (User loop calls this once per rule — filter inside by userId.)
 */
function applyTrendingBoost(
    PDO    $pdo,
    string $userId,
    array  $condition,
    array  $action
): int {
    $minTrending = (int)($condition['min_trending_articles'] ?? 2);
    $delta       = (float)($action['weight_delta']           ?? 0.20);
    $maxWeight   = (float)($action['max_weight']             ?? 5.00);

    // Trending categories with enough trending articles
    $catStmt = $pdo->prepare(
        'SELECT category_id, COUNT(*) AS cnt
         FROM   news
         WHERE  is_trending = 1
           AND  status      = \'approved\'
           AND  category_id IS NOT NULL
         GROUP  BY category_id
         HAVING cnt >= :min'
    );
    $catStmt->bindValue(':min', $minTrending, PDO::PARAM_INT);
    $catStmt->execute();
    $trendingCats = $catStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($trendingCats)) {
        return 0;
    }

    // Only boost categories this user already has an interest in
    $updated = 0;
    foreach ($trendingCats as $cid) {
        $pdo->prepare(
            'UPDATE user_interests
             SET    weight = LEAST(weight + :delta, :max)
             WHERE  user_id     = :uid
               AND  category_id = :cid'
        )->execute([
            ':delta' => $delta,
            ':max'   => $maxWeight,
            ':uid'   => $userId,
            ':cid'   => (int)$cid,
        ]);
        $updated += $pdo->rowCount();
    }

    return $updated;
}

/**
 * recency_boost: boost interests for categories that published fresh
 *                articles within hours_since_publish hours.
 */
function applyRecencyBoost(
    PDO    $pdo,
    string $userId,
    array  $condition,
    array  $action
): int {
    $hours     = (int)($condition['hours_since_publish'] ?? 24);
    $delta     = (float)($action['weight_delta']         ?? 0.10);
    $maxWeight = (float)($action['max_weight']           ?? 5.00);

    $catStmt = $pdo->prepare(
        'SELECT DISTINCT category_id
         FROM   news
         WHERE  status      = \'approved\'
           AND  created_at >= NOW() - INTERVAL :hrs HOUR
           AND  category_id IS NOT NULL'
    );
    $catStmt->bindValue(':hrs', $hours, PDO::PARAM_INT);
    $catStmt->execute();
    $freshCats = $catStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($freshCats)) {
        return 0;
    }

    $updated = 0;
    foreach ($freshCats as $cid) {
        $pdo->prepare(
            'UPDATE user_interests
             SET    weight = LEAST(weight + :delta, :max)
             WHERE  user_id     = :uid
               AND  category_id = :cid'
        )->execute([
            ':delta' => $delta,
            ':max'   => $maxWeight,
            ':uid'   => $userId,
            ':cid'   => (int)$cid,
        ]);
        $updated += $pdo->rowCount();
    }

    return $updated;
}

/* ── Main processing loop ────────────────────────────────────────────── */
$users      = getUsersToProcess($pdo, $targetUserId);
$totalUpdates = 0;

foreach ($users as $userId) {
    foreach ($rules as $rule) {
        $condition = json_decode($rule['condition_json'], true) ?: [];
        $action    = json_decode($rule['action_json'],    true) ?: [];

        $updated = match($rule['rule_type']) {
            'read_boost'      => applyReadBoost($pdo, $userId, $condition, $action),
            'decay'           => applyDecay($pdo, $userId, $condition, $action),
            'trending_boost'  => applyTrendingBoost($pdo, $userId, $condition, $action),
            'recency_boost'   => applyRecencyBoost($pdo, $userId, $condition, $action),
            default           => 0,
        };

        $totalUpdates += $updated;
    }
}

$result = [
    'success'      => true,
    'users_processed' => count($users),
    'updates'      => $totalUpdates,
];

if (IS_CLI) {
    echo 'Personalization engine complete.' . PHP_EOL;
    echo 'Users processed : ' . $result['users_processed'] . PHP_EOL;
    echo 'Weight updates  : ' . $result['updates']         . PHP_EOL;
} else {
    echo json_encode($result);
}
