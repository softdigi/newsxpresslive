<?php
/**
 * web/api/subscription/status.php
 * Check current user's subscription status
 * GET — requires auth
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders(['GET', 'OPTIONS']);
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
$user_uid = $user['uid'];

$stmt = $pdo->prepare("SELECT us.*, sp.name, sp.name_hi, sp.price_monthly, sp.price_yearly
    FROM user_subscriptions us
    JOIN subscription_plans sp ON sp.id = us.plan_id
    WHERE us.user_uid = ? AND us.status IN ('active','created','authenticated','halted')
    ORDER BY us.created_at DESC LIMIT 1");
$stmt->execute([$user_uid]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    echo json_encode(['success' => true, 'subscribed' => false, 'subscription' => null]);
    exit;
}

echo json_encode([
    'success'      => true,
    'subscribed'   => $sub['status'] === 'active',
    'subscription' => [
        'status'          => $sub['status'],
        'plan_name'       => $sub['name'],
        'billing'         => $sub['billing'],
        'current_end'     => $sub['current_end'],
        'subscription_id' => $sub['razorpay_subscription_id'],
        'short_url'       => $sub['short_url'],
    ],
]);
