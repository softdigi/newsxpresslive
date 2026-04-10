<?php
/**
 * web/api/listings/inquire.php
 *
 * POST — Send an inquiry to a listing owner.
 *
 * Body (JSON): {
 *   firebase_uid,
 *   listing_id,
 *   message,
 *   contact_phone   (optional)
 * }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$uid          = trim($body['firebase_uid'] ?? '');
$listingId    = isset($body['listing_id']) ? (int)$body['listing_id'] : 0;
$message      = trim($body['message'] ?? '');
$contactPhone = trim($body['contact_phone'] ?? '') ?: null;

if ($uid === '' || $listingId <= 0 || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'firebase_uid, listing_id, message required']);
    exit;
}
if (mb_strlen($message) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'message max 1000 chars']);
    exit;
}

/* ── verify listing exists and is active ──────────────────── */

$check = $pdo->prepare('SELECT id FROM listings WHERE id = ? AND status = \'active\'');
$check->execute([$listingId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'Listing not found']);
    exit;
}

/* ── rate-limit: max 3 inquiries per user per listing ─────── */

$rl = $pdo->prepare(
    'SELECT COUNT(*) FROM listing_inquiries
     WHERE listing_id = ? AND inquirer_uid = ?
       AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
$rl->execute([$listingId, $uid]);
if ((int)$rl->fetchColumn() >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many inquiries. Try again later.']);
    exit;
}

/* ── insert inquiry ───────────────────────────────────────── */

try {
    $stmt = $pdo->prepare(
        'INSERT INTO listing_inquiries (listing_id, inquirer_uid, message, contact_phone)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$listingId, $uid, $message, $contactPhone]);
    echo json_encode(['success' => true, 'inquiry_id' => (int)$pdo->lastInsertId()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
