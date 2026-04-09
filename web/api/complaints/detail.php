<?php
/**
 * web/api/complaints/detail.php
 * Enhanced Public Voice — Full complaint detail
 *
 * GET ?complaint_id=123
 * Headers: Authorization: Bearer <token>  (optional — for upvote status)
 *
 * Response: { success, complaint, comments, timeline, similar }
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// Optional auth
$currentUid = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $payload = verifyFirebaseToken(trim($m[1]));
    if ($payload && !empty($payload['sub'])) {
        $currentUid = $payload['sub'];
    }
}

$complaintId = isset($_GET['complaint_id']) && ctype_digit((string)$_GET['complaint_id'])
    ? (int)$_GET['complaint_id'] : 0;

if ($complaintId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'complaint_id required']);
    exit;
}

try {
    // Increment views
    $pdo->prepare(
        'UPDATE complaints SET views_count = views_count + 1 WHERE id = :id'
    )->execute([':id' => $complaintId]);

    // Main complaint row
    $stmt = $pdo->prepare(
        "SELECT c.*,
                cc.name        AS category_name,
                cc.name_hi     AS category_name_hi,
                cc.icon        AS category_icon,
                cc.slug        AS category_slug,
                cc.department  AS category_department,
                up.display_name AS user_name,
                up.avatar_url   AS user_avatar,
                up.is_verified  AS user_verified
           FROM complaints c
           LEFT JOIN complaint_categories cc ON cc.id = c.category_id
           LEFT JOIN user_profiles up        ON up.firebase_uid = c.user_id
          WHERE c.id = :id
          LIMIT 1"
    );
    $stmt->execute([':id' => $complaintId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Complaint not found']);
        exit;
    }

    // Check upvote status
    $userUpvoted = false;
    if ($currentUid) {
        $uvStmt = $pdo->prepare(
            'SELECT id FROM complaint_upvotes
              WHERE user_id = :uid AND complaint_id = :cid LIMIT 1'
        );
        $uvStmt->execute([':uid' => $currentUid, ':cid' => $complaintId]);
        $userUpvoted = (bool)$uvStmt->fetch();
    }

    $images = json_decode($row['images'] ?? 'null', true);

    $complaint = [
        'id'                  => (int)$row['id'],
        'title'               => $row['title'],
        'description'         => $row['description'],
        'images'              => is_array($images) ? $images : [],
        'video_url'           => $row['video_url'],
        'status'              => $row['status'],
        'status_label'        => ucfirst(str_replace('_', ' ', $row['status'])),
        'upvotes_count'       => (int)$row['upvotes_count'],
        'comments_count'      => (int)$row['comments_count'],
        'views_count'         => (int)$row['views_count'],
        'shares_count'        => (int)$row['shares_count'],
        'is_viral'            => (bool)$row['is_viral'],
        'is_anonymous'        => (bool)$row['is_anonymous'],
        'user_id'             => $row['is_anonymous'] ? null : $row['user_id'],
        'user_name'           => $row['is_anonymous'] ? null : $row['user_name'],
        'user_avatar'         => $row['is_anonymous'] ? null : $row['user_avatar'],
        'user_verified'       => (bool)($row['user_verified'] ?? false),
        'latitude'            => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude'           => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'address'             => $row['address'],
        'city'                => $row['city'],
        'pincode'             => $row['pincode'],
        'admin_response'      => $row['admin_response'],
        'resolved_at'         => $row['resolved_at'],
        'created_at'          => $row['created_at'],
        'updated_at'          => $row['updated_at'],
        'category_name'       => $row['category_name'],
        'category_name_hi'    => $row['category_name_hi'],
        'category_icon'       => $row['category_icon'],
        'category_department' => $row['category_department'],
        'user_upvoted'        => $userUpvoted,
    ];

    // Comments (latest 20)
    $cmtStmt = $pdo->prepare(
        "SELECT cc.id, cc.comment, cc.is_official, cc.created_at,
                up.display_name AS user_name,
                up.avatar_url   AS user_avatar
           FROM complaint_comments cc
           LEFT JOIN user_profiles up ON up.firebase_uid = cc.user_id
          WHERE cc.complaint_id = :cid
          ORDER BY cc.created_at ASC
          LIMIT 20"
    );
    $cmtStmt->execute([':cid' => $complaintId]);
    $comments = array_map(function (array $c): array {
        return [
            'id'          => (int)$c['id'],
            'comment'     => $c['comment'],
            'is_official' => (bool)$c['is_official'],
            'created_at'  => $c['created_at'],
            'user_name'   => $c['user_name'],
            'user_avatar' => $c['user_avatar'],
        ];
    }, $cmtStmt->fetchAll());

    // Status timeline
    $tlStmt = $pdo->prepare(
        "SELECT old_status, new_status, update_note, created_at
           FROM complaint_updates
          WHERE complaint_id = :cid
          ORDER BY created_at ASC"
    );
    $tlStmt->execute([':cid' => $complaintId]);
    $timeline = $tlStmt->fetchAll();

    // Similar complaints in same district/category (max 5)
    $simWhere = ['c2.id != :cid', "c2.status NOT IN ('rejected')"];
    $simParams = [':cid' => $complaintId];
    if ($row['district_id']) {
        $simWhere[]         = 'c2.district_id = :did';
        $simParams[':did']  = $row['district_id'];
    } elseif ($row['state_id']) {
        $simWhere[]         = 'c2.state_id = :sid';
        $simParams[':sid']  = $row['state_id'];
    }
    $simWhere[] = 'c2.category_id = :cat';
    $simParams[':cat'] = $row['category_id'];

    $simSql = "SELECT c2.id, c2.title, c2.upvotes_count, c2.status, c2.created_at,
                      cc2.name AS category_name, cc2.icon AS category_icon
                 FROM complaints c2
                 LEFT JOIN complaint_categories cc2 ON cc2.id = c2.category_id
                WHERE " . implode(' AND ', $simWhere) . "
                ORDER BY c2.upvotes_count DESC
                LIMIT 5";
    $simStmt = $pdo->prepare($simSql);
    $simStmt->execute($simParams);
    $similar = $simStmt->fetchAll();

    echo json_encode([
        'success'   => true,
        'complaint' => $complaint,
        'comments'  => $comments,
        'timeline'  => $timeline,
        'similar'   => $similar,
    ]);

} catch (PDOException $e) {
    error_log('complaints/detail.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
