<?php
/**
 * admin/refunds/index.php
 *
 * Admin API: GET /admin/refunds — list pending/completed refunds
 *
 * GET ?status=pending|processed|failed|all  (default: all)
 *     ?page=1&per_page=20
 *
 * Response:
 * {
 *   "success": true,
 *   "refunds": [...],
 *   "total": 42,
 *   "page": 1,
 *   "per_page": 20
 * }
 *
 * POST ?action=initiate_refund
 * Body: { "razorpay_payment_id": "pay_xxx", "user_id": 5, "amount_paise": 9900, "reason": "manual_request" }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/cors.php';
require_once __DIR__ . '/../../helpers/security_headers.php';
require_once __DIR__ . '/../../web/includes/config.php';
require_once __DIR__ . '/../../helpers/refund_service.php';
require_once __DIR__ . '/../../helpers/email_service.php';

corsHeaders();
setSecurityHeaders('admin');

// Basic admin token check (replace with your admin auth helper)
$adminToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
$expectedToken = getenv('ADMIN_API_TOKEN') ?: '';
if (empty($expectedToken) || !hash_equals($expectedToken, $adminToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorised']);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'];
$service = RefundService::getInstance($pdo);

if ($method === 'GET') {
    $status  = in_array($_GET['status'] ?? '', ['pending','initiated','processed','failed','all'], true)
               ? ($_GET['status'] ?? 'all') : 'all';
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));

    $result = $service->listRefunds($status, $page, $perPage);
    echo json_encode(array_merge(['success' => true], $result));
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $_GET['action'] ?? '';

    if ($action === 'initiate_refund') {
        $paymentId = trim($body['razorpay_payment_id'] ?? '');
        $userId    = (int)($body['user_id'] ?? 0);
        $amount    = (int)($body['amount_paise'] ?? 0);
        $reason    = $body['reason'] ?? 'manual_request';

        if (!$paymentId || $userId <= 0 || $amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit;
        }

        $result = $service->initiateRefund($paymentId, $userId, $amount, $reason, null, 'admin');
        echo json_encode($result);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid request']);
