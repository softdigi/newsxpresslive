<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../../helpers/firebase_rtdb.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

verify_csrf();

$ids=$_POST['ids'] ?? [];
$action=$_POST['action'] ?? '';

$clean=array_filter(array_map('intval',$ids));
if(empty($clean)) exit('No selection');

$placeholders=implode(',',array_fill(0,count($clean),'?'));

switch($action){
case 'approve':
    // Load comments before approving so we can push them to RTDB
    $fetch = $pdo->prepare(
        "SELECT id, news_id, parent_id, author_name, content, created_at
         FROM comments WHERE id IN ($placeholders) AND status != 'approved'"
    );
    $fetch->execute($clean);
    $toApprove = $fetch->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("UPDATE comments SET status='approved' WHERE id IN ($placeholders)");
    $stmt->execute($clean);

    // Push each approved comment to RTDB
    foreach ($toApprove as $c) {
        rtdbPut(
            '/live/comments/' . $c['news_id'] . '/' . $c['id'],
            [
                'id'          => (int) $c['id'],
                'news_id'     => (int) $c['news_id'],
                'parent_id'   => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
                'author_name' => $c['author_name'],
                'content'     => $c['content'],
                'created_at'  => $c['created_at'],
            ]
        );
    }
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
