<?php
if(!isset($pdo,$uid,$in,$action)){http_response_code(404);exit;}
if(str_starts_with($action,'review_')||in_array($action,['assistant_demo_setup','assistant_demo_turn'],true)){
    opsStaffGate($pdo,$uid,$action,$in);
    if($action==='review_list')out(['reviews'=>opsRecords($pdo,$uid,'review_case'),'connected'=>false]);
    if($action==='review_examples')out(reviewExamples($pdo,$uid));
    if($action==='assistant_demo_setup')out(assistantDemoSetup($pdo,$uid));
    if($action==='assistant_demo_turn'){
        $text=opsText($in['text']??'',1500);if(!$text)throw new RuntimeException('Напишите сообщение.');
        $catalog=publicCatalogData($pdo,$uid);if(!$catalog||array_filter($catalog,fn($x)=>$x['data_mode']!=='demo'))throw new RuntimeException('Встроенный тест доступен только для полностью демонстрационного каталога.');
        if(preg_match('/владелец|владельца|иесімін/iu',$text))throw new RuntimeException('В тесте доступен только режим клиента.');
        opsRateLimit($pdo,'demo-chat:'.$uid,50,3600);session_write_close();
        // Telegram never assigns chat 0. opsQueue deliberately ignores it: no external messages.
        $owner=opsOne($pdo,'SELECT id,biz_name,location FROM users WHERE id=?',[$uid]);$reply=opsBotHandle($pdo,$owner,0,$text,$runtimeConfig);
        if($reply===null){$answer=opsAI($runtimeConfig,businessBotSystem($pdo,$owner,$catalog,$text).' Это учебный диалог. Верни JSON {"text":"ответ"}.',$text);$reply=opsText($answer['text']??'',2400);}
        opsRecord($pdo,$uid,'telegram',['chat_id'=>'0','text'=>$text,'reply'=>$reply,'type'=>'demo','is_demo'=>true,'status'=>'done']);out(['text'=>$reply,'is_demo'=>true,'telegram_sent'=>false]);
    }
    if($action==='review_analyze'){opsRateLimit($pdo,'review-ai:'.$uid,30,3600);session_write_close();out(reviewAnalyze($pdo,$uid,(int)($in['id']??0),$runtimeConfig));}
    if($action==='review_reply'||$action==='review_task')out(reviewAction($pdo,$uid,(int)($in['id']??0),$action==='review_reply'?'reply':'task',$in));
    throw new RuntimeException('Неизвестное действие.');
}
