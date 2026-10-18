<?php
if(!isset($pdo,$uid,$in,$action,$opsRole)){http_response_code(404);exit;}
if(str_starts_with($action,'social_')){
    if($opsRole!=='owner')out(['error'=>'Публикациями управляет владелец бизнеса.'],403);
    if($action==='social_list')out(['posts'=>socialList($pdo,$uid)]);
    opsRateLimit($pdo,'social:'.$uid,90,3600);
    if($action==='social_save')out(['post'=>socialSave($pdo,$uid,$in)]);
    if($action==='social_queue')out(['post'=>socialQueue($pdo,$uid,$in)]);
    if($action==='social_cancel')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'cancel')]);
    if($action==='social_archive')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'archive')]);
    if($action==='social_restore')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'restore')]);
}
