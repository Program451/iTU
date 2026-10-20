<?php
/** Durable intake. Never replay an uncertain business action or Telegram delivery. */
function tgInboxSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_inbox (
        user_id INT NOT NULL, update_id BIGINT NOT NULL, chat_id BIGINT NOT NULL,
        payload_box MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'queued',
        stage VARCHAR(30) NOT NULL DEFAULT 'accepted', attempts INT NOT NULL DEFAULT 0,
        text_delivered TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL, PRIMARY KEY(user_id,update_id),
        INDEX pending(status,updated_at), INDEX chat_queue(user_id,chat_id,update_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function tgVoiceEnabled(array $settings): bool {
    return array_key_exists('bot_voice_enabled',$settings)
        ? (bool)$settings['bot_voice_enabled'] : ($settings['bot_mode']??'ai')==='ai';
}
function tgRecoveryDecision(array $job): string {
    if(!empty($job['text_delivered']))return 'done';
    if((int)$job['attempts']>=2)return 'failed';
    return in_array($job['stage'],['accepted','initialization','incoming','voice_file','transcription','history','business_context'],true)?'retry':'review';
}
function tgInboxAccept(PDO $pdo,int $uid,array $update,array $cfg,?string $route=null): bool {
    $id=(string)$update['update_id'];$chat=(string)$update['message']['chat']['id'];
    // Keep only fields used by the processor; encrypt voice IDs and message text at rest.
    $msg=$update['message'];$clean=['update_id'=>$id,'_connection_route'=>$route,'message'=>['chat'=>['id'=>$chat,'type'=>'private']]];
    foreach(['text','caption','voice'] as $field)if(isset($msg[$field]))$clean['message'][$field]=$msg[$field];
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('INSERT IGNORE INTO aulieata_tg_events(update_id,user_id,chat_id) VALUES(?,?,?)');$q->execute([$id,$uid,$chat]);
        if(!$q->rowCount()){$pdo->commit();return false;}
        $q=$pdo->prepare('INSERT INTO telegram_inbox(user_id,update_id,chat_id,payload_box,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $q->execute([$uid,$id,$chat,sealToken(json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$cfg)]);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function tgJobStage(string $stage,bool $delivered=false): void {
    global $tgJobDb,$tgJob;
    if(!$tgJobDb||!$tgJob)return;
    $q=$tgJobDb->prepare('UPDATE telegram_inbox SET stage=?,text_delivered=GREATEST(text_delivered,?),updated_at=UTC_TIMESTAMP() WHERE user_id=? AND update_id=?');
    $q->execute([$stage,$delivered?1:0,$tgJob['user_id'],$tgJob['update_id']]);
}
function tgInboxFinish(PDO $pdo,array $job,string $status): void {
    $q=$pdo->prepare("UPDATE telegram_inbox SET status=?,payload_box='',updated_at=UTC_TIMESTAMP() WHERE user_id=? AND update_id=?");
    $q->execute([$status,$job['user_id'],$job['update_id']]);
    $q=$pdo->prepare('UPDATE aulieata_tg_events SET status=? WHERE user_id=? AND update_id=?');
    $q->execute([$status==='done'?'done':'failed',$job['user_id'],$job['update_id']]);
}
function tgInboxRun(PDO $db,array $config,int $limit=2,?int $onlyUid=null,?callable $processor=null): int {
    global $tgJobDb,$tgJob;
    $q=$db->prepare("SELECT * FROM telegram_inbox WHERE (status='queued' OR (status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)))".($onlyUid===null?'':' AND user_id=?').' ORDER BY update_id LIMIT 20');
    $q->execute($onlyUid===null?[]:[$onlyUid]);$candidates=$q->fetchAll();$count=0;
    foreach($candidates as $candidate){
        if($count>=$limit)break;
        $key='tg-inbox-'.substr(hash('sha256',$candidate['user_id'].':'.$candidate['chat_id']),0,40);
        $lock=$db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$key]);if((int)$lock->fetchColumn()!==1)continue;
        try{
            $q=$db->prepare("SELECT * FROM telegram_inbox WHERE user_id=? AND chat_id=? AND status IN ('queued','processing') ORDER BY update_id LIMIT 1");$q->execute([$candidate['user_id'],$candidate['chat_id']]);$job=$q->fetch();
            if(!$job||$job['update_id']!==$candidate['update_id'])continue;
            if($job['status']==='processing'){
                if(strtotime($job['updated_at'].' UTC')>time()-360)continue;
                $decision=tgRecoveryDecision($job);
                if($decision!=='retry'){tgInboxFinish($db,$job,$decision);$count++;continue;}
            }
            if(strtotime($job['created_at'].' UTC')<time()-86400){tgInboxFinish($db,$job,'expired');$count++;continue;}
            $cfg=$config;
            $update=json_decode(openToken($job['payload_box'],$config),true,512,JSON_THROW_ON_ERROR);
            if(!$processor){
                $q=$db->prepare('SELECT token_box,secret_box,active,route_key FROM aulieata_connections WHERE user_id=?');$q->execute([$job['user_id']]);$connection=$q->fetch();
                if($connection&&!(int)$connection['active']){tgInboxFinish($db,$job,'cancelled');continue;}
                if(isset($update['_connection_route'])){
                    if(!$connection||!hash_equals($connection['route_key'],$update['_connection_route'])){tgInboxFinish($db,$job,'cancelled');continue;}
                    $cfg['telegram_token']=openToken($connection['token_box'],$cfg);$cfg['webhook_secret']=openToken($connection['secret_box'],$cfg);
                }else{
                    // Preserve the original single-owner webhook, without crossing into another bot.
                    $phone=(string)($cfg['owner_phone']??'');$q=$db->prepare('SELECT id FROM users WHERE phone IN (?,?,?) LIMIT 1');$q->execute([$phone,'+7'.substr($phone,1),'7'.substr($phone,1)]);
                    if(!$phone||!(string)($cfg['telegram_token']??'')||(int)$q->fetchColumn()!==(int)$job['user_id']){tgInboxFinish($db,$job,'cancelled');continue;}
                }
            }
            $q=$db->prepare("UPDATE telegram_inbox SET status='processing',attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE user_id=? AND update_id=?");$q->execute([$job['user_id'],$job['update_id']]);
            $tgJobDb=$db;$tgJob=$job;
            $ok=($processor??'telegramProcessUpdate')($update,(int)$job['user_id'],$cfg);
            tgInboxFinish($db,$job,$ok?'done':'failed');$count++;
        }catch(Throwable $e){
            // Leave the durable checkpoint for recovery; never print credentials or raw updates.
            error_log('AulieAta Telegram inbox: '.get_class($e));
        }finally{
            $tgJobDb=null;$tgJob=null;
            $release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$key]);
        }
    }
    return $count;
}
