<?php
if(!isset($pdo,$uid,$in,$action,$opsRole)){http_response_code(404);exit;}
if(str_starts_with($action,'tiktok_')){
    if($opsRole!=='owner')out(['error'=>'Подключением управляет владелец бизнеса.'],403);
    opsRateLimit($pdo,'tiktok:'.$uid,30,3600);
    if($action==='tiktok_save_app')out(['tiktok'=>tiktokSaveApp($pdo,$uid,$runtimeConfig,(string)($in['client_key']??''),(string)($in['client_secret']??''))]);
    if($action==='tiktok_begin')out(['url'=>tiktokBegin($pdo,$uid,$_SESSION)]);
    if($action==='tiktok_check')out(['tiktok'=>tiktokCheck($pdo,$uid,$runtimeConfig)]);
    if($action==='tiktok_disconnect'){
        unset($_SESSION['tiktok_oauth']);out(tiktokDisconnect($pdo,$uid,$runtimeConfig,($in['remove_app']??false)===true));
    }
}
