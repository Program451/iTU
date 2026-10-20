<?php
// Local-only fixtures and an injected processor: never contact Telegram.
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
require dirname(__DIR__).'/public/telegram-inbox.php';
$cfg=appConfig();
if(!str_starts_with($cfg['db_name']??'','itu_test_')||($cfg['db_host']??'')!=='127.0.0.1')exit(2);
$pdo=appDatabase();appSchema($pdo);tgInboxSchema($pdo);$uid=0;
function tiAssert($condition,$message){if(!$condition)throw new RuntimeException($message);}
try{
    opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',['ti-'.bin2hex(random_bytes(10)),password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),'Queue test','cafe','Test']);
    $uid=(int)$pdo->lastInsertId();$id=random_int(100000000,2000000000);
    $update=['update_id'=>$id,'message'=>['chat'=>['id'=>9001,'type'=>'private'],'text'=>'Private test message']];
    tiAssert(tgInboxAccept($pdo,$uid,$update,$cfg),'Accept once');
    tiAssert(!tgInboxAccept($pdo,$uid,$update,$cfg),'Duplicate ignored');
    $job=opsOne($pdo,'SELECT * FROM telegram_inbox WHERE user_id=?',[$uid]);
    tiAssert(!str_contains($job['payload_box'],'Private test'),'Encrypted queue');
    $calls=0;$processor=function($data,$owner,$config)use(&$calls,$id,$uid){$calls++;tiAssert($data['update_id']==$id&&$owner===$uid,'Correct tenant and update');tgJobStage('text_delivered',true);return true;};
    tiAssert(tgInboxRun($pdo,$cfg,2,$uid,$processor)===1,'Process once');
    tiAssert(tgInboxRun($pdo,$cfg,2,$uid,$processor)===0&&$calls===1,'No duplicate processing');
    $job=opsOne($pdo,'SELECT * FROM telegram_inbox WHERE user_id=?',[$uid]);
    tiAssert($job['status']==='done'&&$job['payload_box']===''&&(int)$job['text_delivered']===1,'Finished and cleared');
    foreach(['transcription'=>'retry','operations'=>'review','send_text'=>'review','audio'=>'review'] as $stage=>$decision){
        tiAssert(tgRecoveryDecision(['stage'=>$stage,'attempts'=>1,'text_delivered'=>0])===$decision,'Safe recovery: '.$stage);
    }
    tiAssert(tgRecoveryDecision(['stage'=>'audio','attempts'=>1,'text_delivered'=>1])==='done','Delivered text never repeated');
    tiAssert(tgRecoveryDecision(['stage'=>'transcription','attempts'=>2,'text_delivered'=>0])==='failed','Bounded retry');
    $update['update_id']++;tgInboxAccept($pdo,$uid,$update,$cfg);
    opsExec($pdo,"UPDATE telegram_inbox SET status='processing',stage='operations',attempts=1,updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 MINUTE) WHERE user_id=? AND update_id=?",[$uid,$update['update_id']]);
    tgInboxRun($pdo,$cfg,2,$uid,$processor);
    tiAssert($calls===1&&opsOne($pdo,'SELECT status FROM telegram_inbox WHERE user_id=? AND update_id=?',[$uid,$update['update_id']])['status']==='review','Uncertain action is not replayed');
    opsSaveSettings($pdo,$uid,['bot_mode'=>'cloud','bot_voice_enabled'=>true]);
    tiAssert(tgVoiceEnabled(opsSettings($pdo,$uid)),'Voice independent of text mode');
    echo "PASS Telegram queue encryption, tenant routing, duplicate intake, one-time processing, uncertain-action recovery and independent voice setting\n";
}finally{
    foreach(['telegram_inbox','aulieata_tg_events','ops_settings'] as $table)opsExec($pdo,'DELETE FROM '.$table.' WHERE user_id=?',[$uid]);
    if($uid)opsExec($pdo,'DELETE FROM users WHERE id=?',[$uid]);
}
