<?php
header('Content-Type: application/json');
require_once '../geo/config.php';
require_once '../geo/response.php';
require_once '../helpers/fraud_guard.php';
require_once '../auth/firebase.php';

// FIX 3: Replaced session_start() / $_SESSION['user_id'] with Firebase
// Bearer token verification. The Flutter app never maintains a PHP session
// cookie, so $_SESSION['user_id'] was always 0, silently bypassing the
// fraud check and inserting withdrawal rows with user_id = 0.
// The caller must now send "Authorization: Bearer <firebase_id_token>".
$id_token = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $id_token = substr($auth_header, 7);
}

// requireAppUser() verifies the JWT signature, checks token expiry, and
// ensures the account is active (not blocked/suspended). It exits with
// a 401/403 JSON response if any check fails.
$authUser = requireAppUser($pdo, $id_token);
$user_id  = (int) $authUser['id'];

$amount = intval($_POST['amount'] ?? 0);

if (!$user_id || $amount <= 0) sendResponse(false, null, 'Invalid request', 400);

// Fraud signals
$signals = [
    'withdrawal_anomaly' => true
];

$fraud = evaluateFraud($pdo, $user_id, 'withdrawal', $signals);
if (in_array($fraud['level'], ['high','critical'])) {
    sendResponse(false, null, 'Withdrawal blocked for verification', 403);
}

// Queue withdrawal (delayed)
$stmt = $pdo->prepare("
    INSERT INTO withdrawal_requests (user_id, amount, status, created_at)
    VALUES (?, ?, 'pending', NOW())
");
$stmt->execute([$user_id, $amount]);

sendResponse(true, ['amount'=>$amount], 'Withdrawal requested');
