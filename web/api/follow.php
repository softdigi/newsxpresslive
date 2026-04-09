<?php
/**
 * web/api/follow.php
 * Social Layer — Follow / Unfollow System
 *
 * ──────────────────────────────────────────────────────────────
 * POST  Toggle follow / unfollow
 *   Headers:  Authorization: Bearer <firebase_id_token>
 *   Body:     { "target_uid": "<firebase_uid_to_follow>" }
 *   Response: { success, is_following, followers_count }
 *
 * GET   Retrieve follow data (public — no auth required)
 *   ?action=counts   &uid=<firebase_uid>
 *     → { success, uid, followers_count, following_count }
 *
 *   ?action=followers&uid=<firebase_uid>[&page=1&per_page=20]
 *     → { success, uid, page, per_page, has_more, users:[...] }
 *
 *   ?action=following &uid=<firebase_uid>[&page=1&per_page=20]
 *     → { success, uid, page, per_page, has_more, users:[...] }
 *
 *   ?action=check    &uid=<firebase_uid>
 *     (requires Authorization header — checks whether the
 *      authenticated user follows uid)
 *     → { success, is_following }
 * ──────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

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

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Extract + verify Firebase Bearer token.
 * Returns the firebase_uid string or exits with 401.
 */
function requireAuth(): string
{
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
    if (!$payload || empty($payload['sub'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }
    return $payload['sub'];
}

/**
 * Fetch a page of user profiles for an array of firebase_uids.
 * Falls back to a minimal placeholder if the user_profiles row is missing.
 */
function buildUserList(PDO $pdo, array $uids): array
{
    if (empty($uids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($uids), '?'));
    $stmt = $pdo->prepare(
        "SELECT firebase_uid, display_name, avatar_url, is_reporter, is_verified
           FROM user_profiles
          WHERE firebase_uid IN ($placeholders)"
    );
    $stmt->execute($uids);
    $profiles = [];
    foreach ($stmt->fetchAll() as $row) {
        $profiles[$row['firebase_uid']] = $row;
    }

    $result = [];
    foreach ($uids as $uid) {
        $p = $profiles[$uid] ?? [];
        $result[] = [
            'firebase_uid'  => $uid,
            'display_name'  => $p['display_name'] ?? null,
            'avatar_url'    => $p['avatar_url']    ?? null,
            'is_reporter'   => (bool)($p['is_reporter']  ?? false),
            'is_verified'   => (bool)($p['is_verified']  ?? false),
        ];
    }
    return $result;
}

// ── POST — toggle follow / unfollow ───────────────────────────────────────

if ($method === 'POST') {
    $followerUid = requireAuth();

    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetUid = isset($input['target_uid'])
        ? mb_substr(trim((string)$input['target_uid']), 0, 128)
        : '';

    if (empty($targetUid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'target_uid required']);
        exit;
    }

    if ($followerUid === $targetUid) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Cannot follow yourself']);
        exit;
    }

    try {
        // Check if already following
        $checkStmt = $pdo->prepare(
            'SELECT id FROM user_follows
              WHERE follower_id = :fol AND following_id = :tgt
              LIMIT 1'
        );
        $checkStmt->execute([':fol' => $followerUid, ':tgt' => $targetUid]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Unfollow
            $pdo->prepare(
                'DELETE FROM user_follows
                  WHERE follower_id = :fol AND following_id = :tgt'
            )->execute([':fol' => $followerUid, ':tgt' => $targetUid]);
            $isFollowing = false;
        } else {
            // Follow
            $pdo->prepare(
                'INSERT IGNORE INTO user_follows (follower_id, following_id)
                 VALUES (:fol, :tgt)'
            )->execute([':fol' => $followerUid, ':tgt' => $targetUid]);
            $isFollowing = true;
        }

        // Fetch fresh followers_count for the target user
        $cntStmt = $pdo->prepare(
            'SELECT followers_count FROM user_follow_counts
              WHERE user_id = :uid LIMIT 1'
        );
        $cntStmt->execute([':uid' => $targetUid]);
        $followersCount = (int)($cntStmt->fetchColumn() ?: 0);

        echo json_encode([
            'success'         => true,
            'is_following'    => $isFollowing,
            'followers_count' => $followersCount,
        ]);

    } catch (PDOException $e) {
        error_log('follow.php POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// ── GET — retrieve follow data ─────────────────────────────────────────────

if ($method === 'GET') {
    $action  = $_GET['action'] ?? 'counts';
    $uid     = mb_substr(trim((string)($_GET['uid'] ?? '')), 0, 128);

    if (empty($uid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'uid required']);
        exit;
    }

    // ── action=counts ──────────────────────────────────────────────────
    if ($action === 'counts') {
        try {
            $stmt = $pdo->prepare(
                'SELECT followers_count, following_count
                   FROM user_follow_counts WHERE user_id = :uid LIMIT 1'
            );
            $stmt->execute([':uid' => $uid]);
            $row = $stmt->fetch();

            echo json_encode([
                'success'         => true,
                'uid'             => $uid,
                'followers_count' => (int)($row['followers_count'] ?? 0),
                'following_count' => (int)($row['following_count'] ?? 0),
            ]);
        } catch (PDOException $e) {
            error_log('follow.php counts error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    // ── action=check ───────────────────────────────────────────────────
    if ($action === 'check') {
        $currentUid = requireAuth();
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM user_follows
                  WHERE follower_id = :fol AND following_id = :tgt LIMIT 1'
            );
            $stmt->execute([':fol' => $currentUid, ':tgt' => $uid]);
            echo json_encode([
                'success'      => true,
                'is_following' => (bool)$stmt->fetch(),
            ]);
        } catch (PDOException $e) {
            error_log('follow.php check error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    // ── action=followers | action=following ────────────────────────────
    if (in_array($action, ['followers', 'following'], true)) {
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        try {
            if ($action === 'followers') {
                // People who follow $uid
                $stmt = $pdo->prepare(
                    'SELECT follower_id AS uid FROM user_follows
                      WHERE following_id = :uid
                      ORDER BY created_at DESC
                      LIMIT :lim OFFSET :off'
                );
            } else {
                // People $uid is following
                $stmt = $pdo->prepare(
                    'SELECT following_id AS uid FROM user_follows
                      WHERE follower_id = :uid
                      ORDER BY created_at DESC
                      LIMIT :lim OFFSET :off'
                );
            }
            $stmt->bindValue(':uid', $uid,     PDO::PARAM_STR);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
            $stmt->execute();

            $uids  = array_column($stmt->fetchAll(), 'uid');
            $users = buildUserList($pdo, $uids);

            echo json_encode([
                'success'  => true,
                'uid'      => $uid,
                'action'   => $action,
                'page'     => $page,
                'per_page' => $perPage,
                'has_more' => count($uids) === $perPage,
                'users'    => $users,
            ]);
        } catch (PDOException $e) {
            error_log("follow.php {$action} error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── Unsupported method ─────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
