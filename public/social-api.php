<?php
if(!isset($pdo,$uid,$in,$action,$opsRole)){http_response_code(404);exit;}
require_once __DIR__.'/social-plan.php';
if(str_starts_with($action,'social_')){
    if($opsRole!=='owner')out(['error'=>'Публикациями управляет владелец бизнеса.'],403);
    if($action==='social_plan_get')out(['settings'=>socialPlanSettings($pdo,$uid),'posts'=>socialList($pdo,$uid)]);
    if(in_array($action,['social_plan_save','social_plan_preview','social_plan_apply'],true))opsRateLimit($pdo,'social-plan:'.$uid,90,3600);
    if($action==='social_plan_save')out(['settings'=>socialPlanStore($pdo,$uid,$in['settings']??[])]);
    if($action==='social_plan_preview')out(socialPlanPreview($pdo,$uid,$in));
    if($action==='social_plan_apply')out(socialPlanApply($pdo,$uid,$in));
    if($action==='social_list'){
        $health=$pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.time')) FROM aulieata_records WHERE user_id=0 AND kind='worker_health' ORDER BY id DESC LIMIT 1")->fetchColumn();
        out(['posts'=>socialList($pdo,$uid),'worker_last_seen'=>$health]);
    }
    opsRateLimit($pdo,'social:'.$uid,90,3600);
    if($action==='social_save')out(['post'=>socialSave($pdo,$uid,$in)]);
    if($action==='social_publish_channels')out(['post'=>publicationQueue($pdo,$uid,$in,$runtimeConfig)]);
    if($action==='social_queue')out(['post'=>socialQueue($pdo,$uid,$in)]);
    if($action==='social_cancel')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'cancel')]);
    if($action==='social_archive')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'archive')]);
    if($action==='social_restore')out(['post'=>socialChange($pdo,$uid,(int)($in['id']??0),'restore')]);
}
