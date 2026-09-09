<?php
/**
 * web/api/blue_tick/plans.php
 *
 * GET  → Return all active blue-tick plans with early-bird pricing info.
 *
 * Optional query params:
 *   ?type=reporter|agency   → filter by account type
 *
 * Response:
 *   {
 *     success,
 *     plans: [
 *       {
 *         id, plan_type, name, price, duration_type,
 *         max_reporters, can_assign_ticks, assign_limit, assign_price,
 *         early_bird_available,  // bool — free slots remain
 *         slots_remaining        // int  — null if not early-bird plan
 *       }, ...
 *     ],
 *     early_bird: {
 *       reporter: { count, free_limit, paid_limit, free_available },
 *       agency:   { count, free_limit, paid_limit, free_available }
 *     }
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../helpers/cors.php';
corsHeaders(['GET', 'OPTIONS']);
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

$typeFilter = trim($_GET['type'] ?? '');

// ── Load early-bird counters ───────────────────────────────────────────────
$ebStmt = $pdo->query("SELECT type, count, free_limit, paid_limit FROM early_bird_counters");
$ebRaw  = $ebStmt->fetchAll(PDO::FETCH_ASSOC);
$eb = [];
foreach ($ebRaw as $row) {
    $eb[$row['type']] = $row;
}

// Helpers for early-bird availability
$reporterFreeAvail = ($eb['reporter']['count'] ?? 0) < ($eb['reporter']['free_limit'] ?? 100);
$agencyFreeAvail   = ($eb['agency']['count']   ?? 0) < ($eb['agency']['free_limit']   ?? 10);

// ── Load plans ─────────────────────────────────────────────────────────────
$where = ['is_active = 1'];
$params = [];
if (in_array($typeFilter, ['reporter', 'agency'], true)) {
    $where[] = 'plan_type LIKE ?';
    $params[] = $typeFilter . '%';
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$planStmt = $pdo->prepare(
    "SELECT id, plan_type, name, price, duration_type,
            max_reporters, can_assign_ticks, assign_limit, assign_price
     FROM blue_tick_plans {$whereSQL}
     ORDER BY id ASC"
);
$planStmt->execute($params);
$plans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plans as &$p) {
    $p['price']            = (float)$p['price'];
    $p['assign_price']     = (float)$p['assign_price'];
    $p['max_reporters']    = (int)$p['max_reporters'];
    $p['can_assign_ticks'] = (bool)$p['can_assign_ticks'];
    $p['assign_limit']     = (int)$p['assign_limit'];

    // Determine if this is an early-bird free plan and if slots remain
    if ($p['plan_type'] === 'reporter_free') {
        $p['early_bird_available'] = $reporterFreeAvail;
        $p['slots_remaining'] = max(0, ($eb['reporter']['free_limit'] ?? 100) - ($eb['reporter']['count'] ?? 0));
    } elseif ($p['plan_type'] === 'agency_free') {
        $p['early_bird_available'] = $agencyFreeAvail;
        $p['slots_remaining'] = max(0, ($eb['agency']['free_limit'] ?? 10) - ($eb['agency']['count'] ?? 0));
    } else {
        $p['early_bird_available'] = false;
        $p['slots_remaining']      = null;
    }
}
unset($p);

echo json_encode([
    'success' => true,
    'plans'   => $plans,
    'early_bird' => [
        'reporter' => [
            'count'          => (int)($eb['reporter']['count']      ?? 0),
            'free_limit'     => (int)($eb['reporter']['free_limit'] ?? 100),
            'paid_limit'     => (int)($eb['reporter']['paid_limit'] ?? 1500),
            'free_available' => $reporterFreeAvail,
        ],
        'agency' => [
            'count'          => (int)($eb['agency']['count']      ?? 0),
            'free_limit'     => (int)($eb['agency']['free_limit'] ?? 10),
            'paid_limit'     => (int)($eb['agency']['paid_limit'] ?? 50),
            'free_available' => $agencyFreeAvail,
        ],
    ],
]);
