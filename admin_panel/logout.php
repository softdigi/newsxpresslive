<?php
// ============================================================
// admin_panel/logout.php — UPDATED
// Uses destroyAdminSession() from auth/session.php
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../../auth/session.php';

destroyAdminSession();

header('Location: ' . ADMIN_URL . '/login.php');
exit;
