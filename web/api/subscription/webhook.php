<?php
/**
 * web/api/subscription/webhook.php
 * Razorpay subscription webhook handler
 *
 * Events: subscription.activated, subscription.charged,
 *         subscription.cancelled, subscription.halted
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/webhook.php';
require_once __DIR__ . '/../../../web/includes/config.php';

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!verifyRazorpayWebhook($payload, $sig)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
$event_type   = $event['event'] ?? '';
$subscription = $event['payload']['subscription']['entity'] ?? [];
$rzp_sub_id   = $subscription['id'] ?? '';

if (!$rzp_sub_id) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

// Fetch local subscription
$stmt = $pdo->prepare('SELECT * FROM user_subscriptions WHERE razorpay_subscription_id = ? LIMIT 1');
$stmt->execute([$rzp_sub_id]);
$local_sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$local_sub) {
    http_response_code(200);
    echo json_encode(['status' => 'not_found']);
    exit;
}

$user_uid = $local_sub['user_uid'];

switch ($event_type) {
    case 'subscription.activated':
    case 'subscription.authenticated':
        $current_start = $subscription['current_start'] ? date('Y-m-d H:i:s', $subscription['current_start']) : null;
        $current_end   = $subscription['current_end']   ? date('Y-m-d H:i:s', $subscription['current_end'])   : null;
        $stmt = $pdo->prepare('UPDATE user_subscriptions SET status=?, current_start=?, current_end=? WHERE razorpay_subscription_id=?');
        $stmt->execute(['active', $current_start, $current_end, $rzp_sub_id]);
        activatePremium($pdo, $user_uid, $current_end);
        break;

    case 'subscription.charged':
        $current_end = $subscription['current_end'] ? date('Y-m-d H:i:s', $subscription['current_end']) : null;
        $paid_count  = $subscription['paid_count'] ?? 0;
        $stmt = $pdo->prepare('UPDATE user_subscriptions SET status="active", current_end=?, paid_count=? WHERE razorpay_subscription_id=?');
        $stmt->execute([$current_end, $paid_count, $rzp_sub_id]);
        activatePremium($pdo, $user_uid, $current_end);
        break;

    case 'subscription.cancelled':
    case 'subscription.completed':
        $stmt = $pdo->prepare("UPDATE user_subscriptions SET status='cancelled' WHERE razorpay_subscription_id=?");
        $stmt->execute([$rzp_sub_id]);
        deactivatePremium($pdo, $user_uid);
        break;

    case 'subscription.halted':
        $stmt = $pdo->prepare("UPDATE user_subscriptions SET status='halted' WHERE razorpay_subscription_id=?");
        $stmt->execute([$rzp_sub_id]);
        break;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);

function activatePremium(PDO $pdo, string $user_uid, ?string $expires): void {
    $stmt = $pdo->prepare("UPDATE users SET subscription_plan='premium', subscription_status='active', subscription_expires=? WHERE uid=?");
    $stmt->execute([$expires, $user_uid]);
}

function deactivatePremium(PDO $pdo, string $user_uid): void {
    $stmt = $pdo->prepare("UPDATE users SET subscription_plan=NULL, subscription_status='cancelled', subscription_expires=NULL WHERE uid=?");
    $stmt->execute([$user_uid]);
}
