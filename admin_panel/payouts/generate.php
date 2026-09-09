<?php
// ============================================================
// FIXED: payouts/generate.php
// CRITICAL BUG: This was a GET request — anyone could trigger
// a payout for any reporter by visiting the URL.
// CHANGES:
//   1. POST only
//   2. CSRF verification
//   3. reporter_id validated (must exist and have role=reporter)
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin', 'admin'])) {
    http_response_code(403);
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/payouts/index.php');
    exit;
}

verify_csrf();

$reporter_id = (int)($_POST['reporter_id'] ?? 0);

if ($reporter_id <= 0) {
    header('Location: ' . ADMIN_URL . '/payouts/index.php?error=invalid_id');
    exit;
}

// Verify reporter exists
$check = $pdo->prepare("SELECT id FROM admin_users WHERE id = ? AND role = 'reporter'");
$check->execute([$reporter_id]);
if (!$check->fetch()) {
    header('Location: ' . ADMIN_URL . '/payouts/index.php?error=not_found');
    exit;
}

$stmt = $pdo->prepare("
    UPDATE viral_boosts
    SET status = 'paid'
    WHERE reporter_id = ? AND status = 'completed'
");
$stmt->execute([$reporter_id]);

header('Location: ' . ADMIN_URL . '/payouts/index.php?paid=1');
exit;
