<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();if(!str_starts_with($cfg['db_name']??'','itu_test_')||($cfg['db_host']??'')!=='127.0.0.1')exit(2);
$pdo=appDatabase();appSchema($pdo);$uid=0;$old=str_repeat('A',64);$new=str_repeat('B',64);$replacement=str_repeat('C',64);$calls=0;
function refreshCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
try{
    opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',['ig-refresh-'.bin2hex(random_bytes(5)),password_hash('test-only',PASSWORD_DEFAULT),'Refresh test','it','Test']);$uid=(int)$pdo->lastInsertId();
    $profile=fn()=>['user_id'=>'178414'.random_int(10000000,99999999),'username'=>'refresh_test','account_type'=>'BUSINESS'];$identity=$profile();
    $inspect=fn($token,$path,$params)=>$path==='/me'?$identity:['data'=>[]];
    $refresh=function($token)use(&$calls,$new){$calls++;return ['access_token'=>$new,'expires_in'=>5184000];};
    $force=fn()=>opsExec($pdo,'UPDATE aulieata_instagram_token_refresh SET next_attempt_at=? WHERE user_id=?',[gmdate('Y-m-d H:i:s',time()-1),$uid]);
    $stored=fn()=>opsOne($pdo,'SELECT token_box FROM aulieata_instagram_connections WHERE user_id=?',[$uid]);
    instagramConnect($pdo,$uid,$cfg,$old,$inspect);instagramRefreshDue($pdo,$cfg,$refresh);refreshCheck($calls===0,'Fresh token refreshed too early');
    $force();$done=instagramRefreshDue($pdo,$cfg,$refresh);refreshCheck($done===1&&openToken($stored()['token_box'],$cfg)===$new,'Token not rotated');
    $state=opsOne($pdo,'SELECT * FROM aulieata_instagram_token_refresh WHERE user_id=?',[$uid]);refreshCheck(strtotime($state['expires_at'].' UTC')>time()+5000000,'Expiry not retained');
    instagramRefreshDue($pdo,$cfg,$refresh);refreshCheck($calls===1,'Successful refresh repeated');
    echo "PASS refresh age gate, encrypted rotation, observed expiry and cadence\n";
    $force();$before=$stored()['token_box'];instagramRefreshDue($pdo,$cfg,fn()=>['access_token'=>'bad','expires_in'=>1]);refreshCheck($stored()['token_box']===$before,'Invalid response replaced valid token');
    $state=opsOne($pdo,'SELECT * FROM aulieata_instagram_token_refresh WHERE user_id=?',[$uid]);refreshCheck($state['last_error']!==''&&strtotime($state['next_attempt_at'].' UTC')>time()+21000,'Retry backoff missing');
    $force();instagramRefreshDue($pdo,$cfg,function(){throw new InstagramConnectionError('expired',true);});refreshCheck(instagramStatus($pdo,$uid)['check_status']==='invalid','Revoked grant was not marked');
    echo "PASS malformed response preservation, bounded retries and revoked grant status\n";
    instagramConnect($pdo,$uid,$cfg,$old,$inspect);$force();
    instagramRefreshDue($pdo,$cfg,function()use($pdo,$uid,$cfg,$replacement,$inspect,$new){instagramConnect($pdo,$uid,$cfg,$replacement,$inspect);return ['access_token'=>$new,'expires_in'=>5184000];});
    refreshCheck(openToken($stored()['token_box'],$cfg)===$replacement,'Refresh overwrote manual replacement');
    $force();instagramRefreshDue($pdo,$cfg,function()use($pdo,$uid,$new){instagramDisconnect($pdo,$uid);return ['access_token'=>$new,'expires_in'=>5184000];});
    refreshCheck($stored()===false||!$stored(),'Refresh restored disconnected account');refreshCheck(!opsOne($pdo,'SELECT user_id FROM aulieata_instagram_token_refresh WHERE user_id=?',[$uid]),'Disconnect left refresh metadata');
    echo "PASS concurrent replacement and disconnect cannot be reversed by refresh\n";
}finally{if($uid){instagramDisconnect($pdo,$uid);opsExec($pdo,'DELETE FROM users WHERE id=?',[$uid]);}}
