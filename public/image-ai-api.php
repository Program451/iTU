<?php
if(!isset($pdo,$uid,$in,$action,$opsRole)){http_response_code(404);exit;}
if(str_starts_with($action,'image_ai_')){
    if($opsRole!=='owner')out(['error'=>'Генерацией управляет владелец бизнеса.'],403);
    if($action==='image_ai_list')out(['connection'=>imageAiStatus($pdo,$uid),'images'=>imageAiList($pdo,$uid)]);
    opsRateLimit($pdo,'image-ai:'.$uid,45,3600);
    if($action==='image_ai_connect')out(['connection'=>imageAiConnect($pdo,$uid,$in,$runtimeConfig)]);
    if($action==='image_ai_disconnect'){imageAiDisconnect($pdo,$uid);out(['ok'=>true]);}
    if($action==='image_ai_queue')out(['image'=>imageAiQueue($pdo,$uid,$in)]);
    if($action==='image_ai_cancel'){imageAiCancel($pdo,$uid,(int)($in['id']??0));out(['ok'=>true]);}
}
