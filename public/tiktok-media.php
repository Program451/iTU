<?php
require_once __DIR__.'/database.php';require_once __DIR__.'/schema.php';
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
try{
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true))throw new RuntimeException('Not found');
    $pdo=appDatabase();appSchema($pdo);$key=(string)($_GET['k']??'');
    $r=opsOne($pdo,'SELECT * FROM tiktok_publications WHERE id=?',[(int)($_GET['id']??0)]);
    if(!$r||!preg_match('/^[a-f0-9]{64}$/D',$key)||!$r['public_key']||!hash_equals($r['public_key'],$key)||$r['public_until']<socialNow()||!in_array($r['status'],['sending','processing','published','uncertain'],true))throw new RuntimeException('Not found');
    $path=tiktokPhotoPath($r['image_key']);if(!is_file($path))throw new RuntimeException('Not found');
    header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($path);
}catch(Throwable $e){http_response_code(404);echo 'Not found';}
