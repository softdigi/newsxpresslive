<?php
/**
 * web/api/subscription/create.php
 * Create Razorpay recurring subscription
 *
 * POST Body: { "plan_id": 1, "billing": "monthly", "gateway": "razorpay" }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';
require_once __DIR__ . '/../../../auth/firebase.php';

corsHeaders(['POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

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

$input = json_decode(file_get_contents('php://input'), true);
$plan_id = (int)($input['plan_id'] ?? 1);
$billing = $input['billing'] ?? 'monthly';

if (!in_array($billing, ['monthly', 'yearly'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid billing period']);
    exit;
}

// Fetch plan
$stmt = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$plan_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plan not found']);
    exit;
}

// Check existing active subscription
$stmt = $pdo->prepare("SELECT * FROM user_subscriptions WHERE user_uid = ? AND status IN ('created','authenticated','active') LIMIT 1");
$stmt->execute([$user_uid]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    echo json_encode([
        'success'         => true,
        'message'         => 'Already subscribed',
        'subscription_id' => $existing['razorpay_subscription_id'],
        'status'          => $existing['status'],
        'short_url'       => $existing['short_url'],
    ]);
    exit;
}

$price = $billing === 'monthly' ? $plan['price_monthly'] : $plan['price_yearly'];
$amount_paise = (int)round((float)$price * 100); // Razorpay uses paise
$rzp_plan_key = $billing === 'monthly' ? 'razorpay_plan_monthly' : 'razorpay_plan_yearly';
$rzp_plan_id = $plan[$rzp_plan_key] ?? null;

$key_id = getenv('RAZORPAY_KEY_ID') ?: '';
$key_secret = getenv('RAZORPAY_KEY_SECRET') ?: '';

if (!$key_id || !$key_secret) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Payment gateway not configured']);
    exit;
}

// Create Razorpay Plan if not preset
if (!$rzp_plan_id) {
    $plan_payload = [
        'period'   => $billing === 'monthly' ? 'monthly' : 'yearly',
        'interval' => 1,
        'item'     => [
            'name'     => $plan['name'] . ' (' . ucfirst($billing) . ')',
            'amount'   => $amount_paise,
            'currency' => 'INR',
        ],
    ];
    $ch = curl_init('https://api.razorpay.com/v1/plans');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($plan_payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $key_id . ':' . $key_secret,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $plan_data = json_decode($response, true);
    if (empty($plan_data['id'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create payment plan', 'details' => $plan_data]);
        exit;
    }
    $rzp_plan_id = $plan_data['id'];
    // Store for future use
    $pdo->prepare("UPDATE subscription_plans SET $rzp_plan_key = ? WHERE id = ?")->execute([$rzp_plan_id, $plan_id]);
}

// Create Razorpay Subscription
$sub_payload = [
    'plan_id'         => $rzp_plan_id,
    'total_count'     => 12,
    'quantity'        => 1,
    'customer_notify' => 1,
    'notes'           => ['user_uid' => $user_uid, 'plan_id' => (string)$plan_id, 'billing' => $billing],
];
$ch = curl_init('https://api.razorpay.com/v1/subscriptions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($sub_payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => $key_id . ':' . $key_secret,
]);
$response = curl_exec($ch);
curl_close($ch);
$sub_data = json_decode($response, true);

if (empty($sub_data['id'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create subscription', 'details' => $sub_data]);
    exit;
}

$rzp_sub_id = $sub_data['id'];
$short_url = $sub_data['short_url'] ?? null;

// Store subscription
$stmt = $pdo->prepare('INSERT INTO user_subscriptions (user_uid, plan_id, billing, razorpay_subscription_id, status, short_url) VALUES (?,?,?,?,?,?)');
$stmt->execute([$user_uid, $plan_id, $billing, $rzp_sub_id, 'created', $short_url]);

echo json_encode([
    'success'        => true,
    'subscription_id' => $rzp_sub_id,
    'short_url'      => $short_url,
    'amount'         => $amount_paise,
    'amount_display' => '₹' . number_format((float)$price, 2),
    'billing'        => $billing,
    'plan_name'      => $plan['name'],
    'razorpay_key'   => $key_id,
]);
