<?php
require_once __DIR__.'/database.php';
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
try{
    $pdo=appDatabase();require_once __DIR__.'/schema.php';appSchema($pdo);
    $id=(int)($_GET['id']??0);$r=opsOne($pdo,'SELECT * FROM social_posts WHERE id=?',[$id]);
    if(!$r)throw new RuntimeException('Not found');
    $key=(string)($_GET['k']??'');
    if($key!==''){
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!$r['public_key']||!hash_equals($r['public_key'],$key)||$r['public_until']<socialNow()||!$r['approved_at']||!in_array($r['status'],['processing','publishing','published','uncertain'],true))throw new RuntimeException('Not found');
    }else{
        appSessionStart();if(!isset($_SESSION['user_id'])||(int)$_SESSION['user_id']!==(int)$r['user_id']||!empty($_SESSION['staff_id']))throw new RuntimeException('Not found');session_write_close();
    }
    $path=socialImagePath($r['image_key']);if(!is_file($path))throw new RuntimeException('Not found');
    header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="instagram-post.jpg"');readfile($path);
}catch(Throwable $e){http_response_code(404);echo 'Not found';}
