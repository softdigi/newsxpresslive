<?php
require_once __DIR__ . '/includes/auth.php';

$role = $_SESSION['admin']['role'] ?? null;

switch ($role) {
    case 'super_admin':
    case 'admin':
        header("Location: dashboard.php");
        break;

    case 'editor':
        header("Location: news/pending.php");
        break;

    case 'reporter':
        header("Location: news/index.php");
        break;

    case 'agency':
        header("Location: reporters/index.php");
        break;

    default:
        header("Location: logout.php");
}

exit;
