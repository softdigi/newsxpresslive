<?php
header('Content-Type: application/json');
require_once '../geo/config.php';
require_once '../geo/response.php';
require_once '../helpers/fraud_guard.php';

session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$amount  = intval($_POST['amount'] ?? 0);

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
