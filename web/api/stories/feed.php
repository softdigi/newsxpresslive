<?php
/**
 * web/api/stories/feed.php
 * Auth-required API — fetch active stories grouped by reporter.
 *
 * GET /web/api/stories/feed.php
 * Authorization: Bearer <firebase_id_token>
 *
 * Returns reporters who have active (non-expired) stories,
 * with unseen stories listed first.
 *
 * Response: { success, stories: [{ reporter_uid, display_name,
 *   photo_url, is_blue_tick, unseen_count, items: [...] }] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$user  = requireAppUser($pdo, $token);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

$viewer_uid = $user['firebase_uid'] ?? '';

// ── Fetch active stories ──────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT ns.id, ns.reporter_uid, ns.story_type, ns.media_url, ns.thumbnail_url,
            ns.text_content, ns.background_color, ns.linked_article_id,
            ns.duration_seconds, ns.views_count, ns.created_at,
            COALESCE(u.display_name, u.name, ns.reporter_uid) AS display_name,
            u.photo_url,
            COALESCE(rp.is_verified, 0) AS is_blue_tick,
            (sv.viewer_uid IS NOT NULL) AS is_viewed
     FROM news_stories ns
     LEFT JOIN users u ON u.firebase_uid = ns.reporter_uid
     LEFT JOIN reporter_profiles rp ON rp.user_id = u.id
     LEFT JOIN story_views sv ON sv.story_id = ns.id AND sv.viewer_uid = ?
     WHERE ns.expires_at > NOW()
     ORDER BY ns.reporter_uid, is_viewed ASC, ns.created_at ASC'
);
$stmt->execute([$viewer_uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group by reporter ─────────────────────────────────────────────────────────
$grouped = [];
foreach ($rows as $row) {
    $uid = $row['reporter_uid'];
    if (!isset($grouped[$uid])) {
        $grouped[$uid] = [
            'reporter_uid' => $uid,
            'display_name' => $row['display_name'],
            'photo_url'    => $row['photo_url'],
            'is_blue_tick' => (bool)$row['is_blue_tick'],
            'unseen_count' => 0,
            'items'        => [],
        ];
    }
    if (!$row['is_viewed']) {
        $grouped[$uid]['unseen_count']++;
    }
    $grouped[$uid]['items'][] = [
        'id'                => (int)$row['id'],
        'story_type'        => $row['story_type'],
        'media_url'         => $row['media_url'],
        'thumbnail_url'     => $row['thumbnail_url'],
        'text_content'      => $row['text_content'],
        'background_color'  => $row['background_color'],
        'linked_article_id' => $row['linked_article_id'] ? (int)$row['linked_article_id'] : null,
        'duration_seconds'  => (int)$row['duration_seconds'],
        'views_count'       => (int)$row['views_count'],
        'created_at'        => $row['created_at'],
        'is_viewed'         => (bool)$row['is_viewed'],
    ];
}

// Sort: reporters with unseen stories first
usort($grouped, fn($a, $b) => $b['unseen_count'] <=> $a['unseen_count']);

echo json_encode(['success' => true, 'stories' => array_values($grouped)]);
