<?php
// actions/schedule_notification.php — FIXED
// Was inserting raw $_POST directly — XSS/injection risk
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../../auth/session.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/notifications/schedule.php');
    exit;
}

verify_csrf();

$title        = mb_substr(trim($_POST['title']        ?? ''), 0, 200, 'UTF-8');
$message      = mb_substr(trim($_POST['message']      ?? ''), 0, 1000, 'UTF-8');
$topic        = mb_substr(trim($_POST['topic']        ?? 'global'), 0, 100, 'UTF-8');
$scheduled_at = trim($_POST['scheduled_at'] ?? '');

if (!$title || !$message) {
    header('Location: ' . ADMIN_URL . '/notifications/schedule.php?error=missing');
    exit;
}

// Validate topic — only alphanumeric + underscores + hyphens
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $topic)) {
    header('Location: ' . ADMIN_URL . '/notifications/schedule.php?error=invalid_topic');
    exit;
}

// Validate scheduled_at
$scheduled_ts = strtotime($scheduled_at);
if (!$scheduled_ts || $scheduled_ts < time()) {
    header('Location: ' . ADMIN_URL . '/notifications/schedule.php?error=invalid_date');
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO notifications_queue (title, message, topic, scheduled_at)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$title, $message, $topic, date('Y-m-d H:i:s', $scheduled_ts)]);

header('Location: ' . ADMIN_URL . '/notifications/schedule.php?scheduled=1');
exit;
