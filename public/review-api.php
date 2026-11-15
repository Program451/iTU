<?php
if(!isset($pdo,$uid,$in,$action)){http_response_code(404);exit;}
if(str_starts_with($action,'review_')||$action==='assistant_demo_setup'){
    opsStaffGate($pdo,$uid,$action,$in);
    if($action==='review_list')out(['reviews'=>opsRecords($pdo,$uid,'review_case'),'connected'=>false]);
    if($action==='review_examples')out(reviewExamples($pdo,$uid));
    if($action==='assistant_demo_setup')out(assistantDemoSetup($pdo,$uid));
    if($action==='review_analyze'){opsRateLimit($pdo,'review-ai:'.$uid,30,3600);session_write_close();out(reviewAnalyze($pdo,$uid,(int)($in['id']??0),$runtimeConfig));}
    if($action==='review_reply'||$action==='review_task')out(reviewAction($pdo,$uid,(int)($in['id']??0),$action==='review_reply'?'reply':'task',$in));
    throw new RuntimeException('Неизвестное действие.');
}
