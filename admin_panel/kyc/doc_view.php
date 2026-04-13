<?php
/**
 * admin_panel/kyc/doc_view.php
 * Secure KYC document viewer for admins only
 */
declare(strict_types=1);
require_once __DIR__ . '/../../web/includes/config.php';
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT pan_doc_url FROM reporter_kyc WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$kyc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kyc) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$doc_path = __DIR__ . '/../../' . $kyc['pan_doc_url'];

// Prevent path traversal
$real_base = realpath(__DIR__ . '/../../uploads/kyc/');
$real_doc  = realpath($doc_path);
if (!$real_doc || !$real_base || !str_starts_with($real_doc, $real_base)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

if (!file_exists($real_doc)) {
    http_response_code(404);
    echo 'Document file not found';
    exit;
}

$ext = strtolower(pathinfo($real_doc, PATHINFO_EXTENSION));
$mime = ($ext === 'pdf') ? 'application/pdf' : (($ext === 'png') ? 'image/png' : 'image/jpeg');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="kyc_doc_' . $id . '.' . $ext . '"');
header('Content-Length: ' . filesize($real_doc));
readfile($real_doc);
