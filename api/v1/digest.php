<?php
// ============================================================
// api/v1/digest.php
//
// Flutter app endpoint: fetch today's AI digest for a user.
//
// GET  /api/v1/digest.php?user_id=123&type=local_district
// GET  /api/v1/digest.php?user_id=123&type=national
//
// type options:
//   local_district  → user's district digest (from news_digests)
//   local_state     → user's state digest
//   national        → latest national digest (last 24h)
//   international   → latest international digest (last 24h)
//   all             → one entry per type (default)
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/database.php';

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$type   = $_GET['type'] ?? 'all';

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id required']);
    exit;
}

// Validate type
$allowedTypes = ['local_district', 'local_state', 'national', 'international', 'all'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid type']);
    exit;
}

try {
    // Fetch user location
    $uStmt = $pdo->prepare("SELECT district_id, state_id FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user not found']);
        exit;
    }

    $districtId = (int)($user['district_id'] ?? 0);
    $stateId    = (int)($user['state_id'] ?? 0);
    $digests    = [];

    // Helper: fetch latest digest of a type
    $fetchDigest = function (string $dtype, ?int $locationId) use ($pdo): ?array {
        $locCondition = $locationId ? 'AND location_id = :lid' : 'AND location_id IS NULL';
        $params       = [':dtype' => $dtype];
        if ($locationId) {
            $params[':lid'] = $locationId;
        }
        $stmt = $pdo->prepare("
            SELECT digest_title, digest_text, sent_at, news_ids
            FROM news_digests
            WHERE digest_type = :dtype
              {$locCondition}
              AND sent_at >= NOW() - INTERVAL 24 HOUR
            ORDER BY sent_at DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    if ($type === 'all' || $type === 'local_district') {
        if ($districtId > 0) {
            $d = $fetchDigest('local_district', $districtId);
            if ($d) {
                $d['type'] = 'local_district';
                $digests[] = $d;
            }
        }
    }

    if ($type === 'all' || $type === 'local_state') {
        if ($stateId > 0) {
            $d = $fetchDigest('local_state', $stateId);
            if ($d) {
                $d['type'] = 'local_state';
                $digests[] = $d;
            }
        }
    }

    if ($type === 'all' || $type === 'national') {
        $d = $fetchDigest('national', null);
        if ($d) {
            $d['type'] = 'national';
            $digests[] = $d;
        }
    }

    if ($type === 'all' || $type === 'international') {
        $d = $fetchDigest('international', null);
        if ($d) {
            $d['type'] = 'international';
            $digests[] = $d;
        }
    }

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'count'   => count($digests),
        'digests' => $digests,
    ]);

} catch (Throwable $e) {
    error_log('digest API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
