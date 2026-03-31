<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/subscription.php';

logoutUser();
header('Location: ' . SITE_URL . '/');
exit;
