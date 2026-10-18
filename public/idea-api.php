<?php
if(!isset($pdo,$uid,$in,$action,$opsRole)){http_response_code(404);exit;}
if(str_starts_with($action,'idea_')){
    if($opsRole!=='owner')out(['error'=>'Идеями и публикацией управляет владелец.'],403);
    if($action==='idea_list')out(ideaList($pdo,$uid));
    opsRateLimit($pdo,'idea-actions:'.$uid,40,3600);
    if($action==='idea_generate'){session_write_close();out(['batch'=>ideaGenerate($pdo,$uid,$in,$runtimeConfig)]);}
    if($action==='idea_start')out(['run'=>ideaStart($pdo,$uid,$in)]);
    if($action==='idea_cancel'){ideaCancel($pdo,$uid,(int)($in['id']??0));out(['ok'=>true]);}
}
