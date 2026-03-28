<?php
require "../includes/config.php";
require "../includes/auth.php";

$newsId = (int)($_GET['id'] ?? 0);
if (!$newsId) {
    header("Location: ../news/breaking.php");
    exit;
}

$pdo->prepare(
    "UPDATE news 
     SET is_breaking=0 
     WHERE id=?"
)->execute([$newsId]);

header("Location: ../news/breaking.php");
exit;
