<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

verify_csrf($_POST['csrf_token'] ?? '');

$ids=$_POST['ids'] ?? [];
$action=$_POST['action'] ?? '';

$clean=array_filter(array_map('intval',$ids));
if(empty($clean)) exit('No selection');

$placeholders=implode(',',array_fill(0,count($clean),'?'));

switch($action){
case 'approve':
$stmt=$pdo->prepare("UPDATE comments SET status='approved' WHERE id IN ($placeholders)");
$stmt->execute($clean);
break;
case 'spam':
$stmt=$pdo->prepare("UPDATE comments SET status='spam' WHERE id IN ($placeholders)");
$stmt->execute($clean);
break;
case 'delete':
$stmt=$pdo->prepare("DELETE FROM comments WHERE id IN ($placeholders)");
$stmt->execute($clean);
break;
}

header("Location: index.php");
exit;
