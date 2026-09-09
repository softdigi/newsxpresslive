<?php
/**
 * web/api/subscription/plans.php
 * List available subscription plans
 * GET — no auth required
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

$stmt = $pdo->query('SELECT id, name, name_hi, price_monthly, price_yearly, features FROM subscription_plans WHERE is_active = 1');
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plans as &$p) {
    $p['features']      = json_decode($p['features'], true);
    $p['price_monthly'] = (float)$p['price_monthly'];
    $p['price_yearly']  = (float)$p['price_yearly'];
    $p['yearly_savings'] = round(($p['price_monthly'] * 12) - $p['price_yearly'], 2);
}

echo json_encode(['success' => true, 'plans' => $plans]);
