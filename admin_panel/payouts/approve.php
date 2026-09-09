<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin'])) {
    exit('Access denied');
}

verify_csrf($_POST['csrf_token'] ?? '');

$ids = $_POST['ids'] ?? [];
$clean = array_filter(array_map('intval',$ids));

if (!empty($clean)) {
    $placeholders = implode(',', array_fill(0,count($clean),'?'));
    $stmt = $pdo->prepare("UPDATE viral_boosts SET status='paid' WHERE id IN ($placeholders)");
    $stmt->execute($clean);
}

header("Location: index.php");
exit;
