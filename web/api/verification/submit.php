<?php
/**
 * web/api/verification/submit.php
 *
 * POST multipart/form-data  (documents) or application/json (status check)
 *
 * Submit KYC / verification documents for a reporter or agency.
 *
 * Headers:
 *   Authorization: Bearer <firebase_id_token>
 *
 * Fields (multipart):
 *   account_type    reporter | agency        (required)
 *
 *   --- Reporter fields ---
 *   aadhar_number   12-digit string          (required for reporter)
 *   pan_number      10-char alphanumeric      (optional)
 *   channel_id      int  (from media_channels table, 0 = other)
 *   channel_name    string (when channel_id=0)
 *   aadhar_doc      file  jpg/png/pdf ≤ 5 MB  (required for reporter)
 *   pan_doc         file  jpg/png/pdf ≤ 5 MB  (optional)
 *
 *   --- Agency fields ---
 *   business_name   string                   (required for agency)
 *   msme_number     string                   (optional)
 *   website         string                   (optional)
 *   contact_name    string                   (required for agency)
 *   contact_phone   string                   (required for agency)
 *   reporter_count  int                      (optional)
 *   msme_doc        file  ≤ 5 MB             (optional)
 *   legal_doc       file  ≤ 5 MB             (optional)
 *
 * Response:
 *   { success, message, submission_id }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['POST', 'OPTIONS']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

// ── Authentication ────────────────────────────────────────────────────────
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
$uid = $payload['sub'] ?? $payload['uid'] ?? '';
if (empty($uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Rate limit: max 3 submissions per UID per 24 h ────────────────────────
$rlStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM verification_documents
     WHERE user_id = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
$rlStmt->execute([$uid]);
if ((int)$rlStmt->fetchColumn() >= 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many submissions — please wait 24 hours']);
    exit;
}

// ── Already approved? ─────────────────────────────────────────────────────
$existStmt = $pdo->prepare(
    "SELECT id, status FROM verification_documents
     WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1"
);
$existStmt->execute([$uid]);
$existing = $existStmt->fetch(PDO::FETCH_ASSOC);
if ($existing && $existing['status'] === 'approved') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Verification already approved']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────
$accountType = trim($_POST['account_type'] ?? '');
if (!in_array($accountType, ['reporter', 'agency'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'account_type must be reporter or agency']);
    exit;
}

$uploadDir  = __DIR__ . '/../../../uploads/verification/';
$uploadUrl  = SITE_URL . '/uploads/verification/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/**
 * Handle a single file upload.
 * Returns the public URL on success or throws RuntimeException.
 */
function handleDocUpload(string $field, string $uid): ?string
{
    global $uploadDir, $uploadUrl;

    if (empty($_FILES[$field]['name'])) {
        return null;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload error for {$field}: code {$file['error']}");
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException("{$field} must be ≤ 5 MB");
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException("{$field}: unsupported file type");
    }
    $ext      = $allowed[$mime];
    $filename = 'vdoc_' . preg_replace('/[^a-z0-9]/', '', strtolower($uid))
                . '_' . $field . '_' . time() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException("Failed to save {$field}");
    }
    return $uploadUrl . $filename;
}

// ── Build insert array ────────────────────────────────────────────────────
$doc = [
    'user_id'      => $uid,
    'account_type' => $accountType,
    'status'       => 'pending',
];

try {
    if ($accountType === 'reporter') {
        // Validate Aadhar
        $aadhar = preg_replace('/\D/', '', $_POST['aadhar_number'] ?? '');
        if (strlen($aadhar) !== 12) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid Aadhar number (12 digits required)']);
            exit;
        }
        $doc['aadhar_number']  = $aadhar;

        // PAN (optional)
        $pan = strtoupper(trim($_POST['pan_number'] ?? ''));
        if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid PAN format']);
            exit;
        }
        $doc['pan_number'] = $pan ?: null;

        // Channel
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId > 0) {
            $chStmt = $pdo->prepare("SELECT name FROM media_channels WHERE id=? AND is_active=1");
            $chStmt->execute([$channelId]);
            $ch = $chStmt->fetchColumn();
            $doc['channel_id']   = $channelId;
            $doc['channel_name'] = $ch ?: null;
            $doc['channel_type'] = 'existing';
        } else {
            $channelName = trim($_POST['channel_name'] ?? '');
            if ($channelName !== '') {
                $doc['channel_name'] = substr($channelName, 0, 200);
                $doc['channel_type'] = 'other';
            }
        }

        // Documents
        $doc['aadhar_doc_url'] = handleDocUpload('aadhar_doc', $uid);
        if (!$doc['aadhar_doc_url']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Aadhar document is required']);
            exit;
        }
        $doc['pan_doc_url'] = handleDocUpload('pan_doc', $uid);

    } else { // agency
        $bizName = trim($_POST['business_name'] ?? '');
        $contact = trim($_POST['contact_name']  ?? '');
        $phone   = preg_replace('/\D/', '', $_POST['contact_phone'] ?? '');

        if ($bizName === '' || $contact === '' || strlen($phone) < 10) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'business_name, contact_name and contact_phone are required']);
            exit;
        }
        $doc['business_name']   = substr($bizName, 0, 200);
        $doc['contact_name']    = substr($contact, 0, 200);
        $doc['contact_phone']   = substr($phone, 0, 15);
        $doc['msme_number']     = substr(trim($_POST['msme_number'] ?? ''), 0, 50) ?: null;
        $doc['website']         = filter_var(trim($_POST['website'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $doc['reporter_count']  = max(0, (int)($_POST['reporter_count'] ?? 0));

        $doc['msme_doc_url']  = handleDocUpload('msme_doc',  $uid);
        $doc['legal_doc_url'] = handleDocUpload('legal_doc', $uid);
    }
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// ── Insert ────────────────────────────────────────────────────────────────
$cols   = implode(', ', array_keys($doc));
$places = implode(', ', array_fill(0, count($doc), '?'));
$stmt   = $pdo->prepare("INSERT INTO verification_documents ({$cols}) VALUES ({$places})");
$stmt->execute(array_values($doc));
$submissionId = (int)$pdo->lastInsertId();

// Update user verification_status → pending
$pdo->prepare(
    "UPDATE users SET verification_status='pending', account_type=?
     WHERE firebase_uid=?"
)->execute([$accountType, $uid]);

echo json_encode([
    'success'       => true,
    'message'       => 'Verification documents submitted successfully',
    'submission_id' => $submissionId,
]);
