<?php
header("Content-Type: application/json");

require __DIR__ . "/../geo/config.php";
require_once __DIR__ . "/../auth/firebase.php";
require __DIR__ . "/../geo/response.php";

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, [], "POST request required");
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['admin_uid'])) {
    jsonResponse(false, [], "admin_uid required");
}

$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');

/* ===== COUNTS ===== */

/* Users */
$totalUsers = $pdo->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$totalReporters = $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role='reporter'"
)->fetchColumn();

/* News status */
$pendingNews = $pdo->query(
    "SELECT COUNT(*) FROM news WHERE status='pending'"
)->fetchColumn();

$approvedNews = $pdo->query(
    "SELECT COUNT(*) FROM news WHERE status='approved'"
)->fetchColumn();

$rejectedNews = $pdo->query(
    "SELECT COUNT(*) FROM news WHERE status='rejected'"
)->fetchColumn();

/* Breaking / Featured */
$breakingNews = $pdo->query(
    "SELECT COUNT(*) FROM news WHERE is_breaking=1"
)->fetchColumn();

$featuredNews = $pdo->query(
    "SELECT COUNT(*) FROM news WHERE is_featured=1"
)->fetchColumn();

/* Today stats */
$todayUsers = $pdo->query(
    "SELECT COUNT(*) FROM users 
     WHERE DATE(created_at)=CURDATE()"
)->fetchColumn();

$todayNews = $pdo->query(
    "SELECT COUNT(*) FROM news 
     WHERE DATE(created_at)=CURDATE()"
)->fetchColumn();

/* ===== RESPONSE ===== */

jsonResponse(true, [
    "users" => [
        "total" => (int)$totalUsers,
        "reporters" => (int)$totalReporters,
        "today_new" => (int)$todayUsers
    ],
    "news" => [
        "pending" => (int)$pendingNews,
        "approved" => (int)$approvedNews,
        "rejected" => (int)$rejectedNews,
        "breaking" => (int)$breakingNews,
        "featured" => (int)$featuredNews,
        "today_submitted" => (int)$todayNews
    ]
], "dashboard stats loaded");
