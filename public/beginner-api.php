<?php
if(!isset($pdo,$uid,$in,$action)){http_response_code(404);exit;}
if(str_starts_with($action,'beginner_')){
    if(!empty($_SESSION['staff_id']))out(['error'=>'Для этого действия нужен владелец бизнеса.'],403);
    if($action==='beginner_provider')out(['provider'=>beginnerProvider($pdo,$uid,$runtimeConfig)]);
    if($action==='beginner_load'){$plans=[];foreach(opsAll($pdo,'SELECT type FROM beginner_plans WHERE user_id=?',[$uid]) as $p)$plans[$p['type']]=beginnerPlan($pdo,$uid,$p['type']);out(['sectors'=>beginnerSectors(),'plans'=>$plans,'provider'=>beginnerProvider($pdo,$uid,$runtimeConfig)]);}
    if($action==='beginner_save')out(['plan'=>beginnerSave($pdo,$uid,$in)]);
    if($action==='beginner_analyze'){session_write_close();out(['plan'=>beginnerAnalyze($pdo,$uid,$in,$runtimeConfig)]);}
    if($action==='beginner_select'){$profile=beginnerSelect($pdo,$uid,$in);$_SESSION['biz_category']=$profile['type'];out(['profile'=>$profile]);}
    out(['error'=>'Неизвестное действие'],400);
}
