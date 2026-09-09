<?php
/**
 * web/api/reporter/kyc.php
 * KYC submission and status for reporters.
 * POST — submit KYC documents
 * GET  — check KYC status
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders(['GET', 'POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

$id_token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $id_token = substr($authHeader, 7);
}
if (!$id_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}
$user = requireAppUser($pdo, $id_token);
if (!in_array($user['role'] ?? '', ['reporter', 'agency'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Reporter account required']);
    exit;
}
$reporter_uid = $user['uid'];
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: KYC status ─────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM reporter_kyc WHERE reporter_uid = ? LIMIT 1');
    $stmt->execute([$reporter_uid]);
    $kyc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kyc) {
        echo json_encode(['success' => true, 'kyc_status' => null, 'withdrawal_limit' => 50000, 'submitted' => false]);
        exit;
    }

    $pan = $kyc['pan_number'];
    $pan_masked = substr($pan, 0, 5) . '****' . substr($pan, -1);

    echo json_encode([
        'success'            => true,
        'kyc_status'         => $kyc['kyc_status'],
        'withdrawal_limit'   => (float)$kyc['withdrawal_limit'],
        'kyc_verified_limit' => (float)$kyc['kyc_verified_limit'],
        'submitted_at'       => $kyc['submitted_at'],
        'approved_at'        => $kyc['approved_at'],
        'rejection_reason'   => $kyc['rejection_reason'],
        'pan_masked'         => $pan_masked,
        'bank_name'          => $kyc['bank_name'],
        'account_masked'     => $kyc['account_number_masked'],
        'ifsc_code'          => $kyc['ifsc_code'],
        'account_holder'     => $kyc['account_holder'],
    ]);
    exit;
}

// ── POST: Submit KYC ─────────────────────────────────────────────────
if ($method === 'POST') {
    // Check if already submitted
    $stmt = $pdo->prepare('SELECT kyc_status FROM reporter_kyc WHERE reporter_uid = ? LIMIT 1');
    $stmt->execute([$reporter_uid]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['kyc_status'] === 'approved') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'KYC already approved']);
        exit;
    }
    if ($existing && $existing['kyc_status'] === 'pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'KYC already submitted and pending review']);
        exit;
    }

    // Parse form data (multipart)
    $pan_number     = strtoupper(trim($_POST['pan_number'] ?? ''));
    $pan_name       = trim($_POST['pan_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $ifsc_code      = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $bank_name      = trim($_POST['bank_name'] ?? '');
    $account_holder = trim($_POST['account_holder'] ?? '');

    // Validate PAN
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan_number)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid PAN number format']);
        exit;
    }
    // Validate IFSC
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid IFSC code format']);
        exit;
    }
    // Required fields
    foreach (['pan_name' => $pan_name, 'account_number' => $account_number, 'bank_name' => $bank_name, 'account_holder' => $account_holder] as $field => $val) {
        if (empty($val)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => "Field $field is required"]);
            exit;
        }
    }
    if (strlen($account_number) < 8 || strlen($account_number) > 18) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid bank account number']);
        exit;
    }

    // PAN document upload
    if (empty($_FILES['pan_doc']['tmp_name'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'PAN document required']);
        exit;
    }
    $file = $_FILES['pan_doc'];
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_types, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'PAN document must be JPG, PNG or PDF']);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'PAN document must be under 5MB']);
        exit;
    }

    // Save to private uploads/kyc/
    $kyc_dir = __DIR__ . '/../../../uploads/kyc/';
    if (!is_dir($kyc_dir)) {
        mkdir($kyc_dir, 0750, true);
        file_put_contents($kyc_dir . '.htaccess', "Order deny,allow\nDeny from all\n");
    }
    $ext = ($mime === 'application/pdf') ? 'pdf' : (($mime === 'image/png') ? 'png' : 'jpg');
    $filename = 'pan_' . $reporter_uid . '_' . time() . '.' . $ext;
    $filepath = $kyc_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save document']);
        exit;
    }
    $pan_doc_url = 'uploads/kyc/' . $filename; // relative path, not web-accessible

    // Encrypt account number
    $enc_key = getenv('KYC_ENCRYPTION_KEY') ?: 'default_kyc_key_change_in_production!1';
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($account_number, 'AES-256-CBC', $enc_key, 0, $iv);
    $account_number_encrypted = base64_encode($iv) . ':' . $encrypted;

    // Mask account number
    $len = strlen($account_number);
    $account_number_masked = str_repeat('X', max(0, $len - 4)) . substr($account_number, -4);

    // Insert or replace KYC record
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE reporter_kyc SET pan_number=?, pan_name=?, pan_doc_url=?, account_number_masked=?, account_number_encrypted=?, ifsc_code=?, bank_name=?, account_holder=?, kyc_status="pending", rejection_reason=NULL, submitted_at=CURRENT_TIMESTAMP WHERE reporter_uid=?');
        $stmt->execute([$pan_number, $pan_name, $pan_doc_url, $account_number_masked, $account_number_encrypted, $ifsc_code, $bank_name, $account_holder, $reporter_uid]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO reporter_kyc (reporter_uid, pan_number, pan_name, pan_doc_url, account_number_masked, account_number_encrypted, ifsc_code, bank_name, account_holder) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$reporter_uid, $pan_number, $pan_name, $pan_doc_url, $account_number_masked, $account_number_encrypted, $ifsc_code, $bank_name, $account_holder]);
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'KYC submitted successfully. Review takes 1-2 business days.',
        'kyc_status' => 'pending',
        'pan_masked' => substr($pan_number, 0, 5) . '****' . substr($pan_number, -1),
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
