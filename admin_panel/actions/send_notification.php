<?php
// actions/send_notification.php — FIXED
// sendFCMNotification() was called with wrong arg order
// Original: sendFCMNotification($topic, $title, $message)
// Correct:  sendFCMNotification($title, $body, $data, $topic)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../helpers/notification.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/notifications/breaking.php');
    exit;
}

verify_csrf();

$title   = mb_substr(trim($_POST['title']   ?? ''), 0, 200, 'UTF-8');
$message = mb_substr(trim($_POST['message'] ?? ''), 0, 1000, 'UTF-8');
$type    = trim($_POST['target_type'] ?? 'global');

if (!$title || !$message) {
    header('Location: ' . ADMIN_URL . '/notifications/breaking.php?error=missing');
    exit;
}

// Build topic — whitelist type
$allowed_types = ['global', 'country', 'state', 'district'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'global';
}

$topic = 'global';
if ($type === 'country' && !empty($_POST['country_id'])) {
    $topic = 'country_' . (int)$_POST['country_id'];
} elseif ($type === 'state' && !empty($_POST['state_id'])) {
    $topic = 'state_' . (int)$_POST['state_id'];
} elseif ($type === 'district' && !empty($_POST['district_id'])) {
    $topic = 'district_' . (int)$_POST['district_id'];
}

// FIXED: correct function signature
sendFCMNotification($title, $message, ['type' => 'push'], $topic);

// Log
$stmt = $pdo->prepare("
    INSERT INTO notifications_queue (title, message, topic, created_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->execute([$title, $message, $topic]);

header('Location: ' . ADMIN_URL . '/notifications/breaking.php?sent=1');
exit;
