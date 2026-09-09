<?php
/**
 * web/api/listings/save.php
 *
 * POST — Toggle save / unsave a listing.
 *
 * Body (JSON): { firebase_uid, listing_id }
 *
 * Response: { saved: true|false, saves_count: N }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$uid       = trim($body['firebase_uid'] ?? '');
$listingId = isset($body['listing_id']) ? (int)$body['listing_id'] : 0;

if ($uid === '' || $listingId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'firebase_uid and listing_id required']);
    exit;
}

/* ── check if already saved ───────────────────────────────── */

$check = $pdo->prepare(
    'SELECT 1 FROM listing_saves WHERE user_id = ? AND listing_id = ?'
);
$check->execute([$uid, $listingId]);
$alreadySaved = (bool)$check->fetchColumn();

if ($alreadySaved) {
    $pdo->prepare('DELETE FROM listing_saves WHERE user_id = ? AND listing_id = ?')
        ->execute([$uid, $listingId]);
    $pdo->prepare('UPDATE listings SET saves_count = GREATEST(0, saves_count - 1) WHERE id = ?')
        ->execute([$listingId]);
    $saved = false;
} else {
    try {
        $pdo->prepare('INSERT INTO listing_saves (user_id, listing_id) VALUES (?, ?)')
            ->execute([$uid, $listingId]);
        $pdo->prepare('UPDATE listings SET saves_count = saves_count + 1 WHERE id = ?')
            ->execute([$listingId]);
        $saved = true;
    } catch (PDOException $e) {
        // Duplicate key — already saved race condition
        $saved = true;
    }
}

$cnt = $pdo->prepare('SELECT saves_count FROM listings WHERE id = ?');
$cnt->execute([$listingId]);
$savesCount = (int)($cnt->fetchColumn() ?: 0);

echo json_encode(['saved' => $saved, 'saves_count' => $savesCount]);
