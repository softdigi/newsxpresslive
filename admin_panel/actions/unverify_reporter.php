<?php
require "../includes/config.php";
require "../includes/auth.php";

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header("Location: ../reporters/index.php");
    exit;
}

/* Unverify */
$pdo->prepare(
    "UPDATE reporter_profiles SET is_verified=0 WHERE user_id=?"
)->execute([$userId]);

header("Location: ../reporters/index.php");
exit;
