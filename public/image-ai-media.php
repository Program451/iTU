<?php
require_once __DIR__.'/database.php';
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
try{
    appSessionStart();$uid=(int)($_SESSION['user_id']??0);
    if(!$uid||!empty($_SESSION['staff_id']))throw new RuntimeException('Not found');
    session_write_close();$pdo=appDatabase();require_once __DIR__.'/schema.php';appSchema($pdo);
    $r=opsOne($pdo,"SELECT image_key FROM image_ai_jobs WHERE id=? AND user_id=? AND status='done'",[(int)($_GET['id']??0),$uid]);
    if(!$r)throw new RuntimeException('Not found');$path=imageAiPath($r['image_key']);if(!is_file($path))throw new RuntimeException('Not found');
    header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="aulieata-ai-image.jpg"');readfile($path);
}catch(Throwable $e){http_response_code(404);echo 'Not found';}
