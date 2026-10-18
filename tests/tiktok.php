<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();$base=appUrl();$oldConfig=getenv('AULIEATA_CONFIG');
if(!str_starts_with($cfg['db_name']??'','itu_test_')||!in_array($cfg['db_host']??'',['127.0.0.1','localhost'],true)||!str_starts_with($base,'http://127.0.0.1:'))exit(2);
$pdo=appDatabase();appSchema($pdo);$ids=[];$jars=[];$testConfig=tempnam(sys_get_temp_dir(),'itu-tiktok-');
$httpsCfg=$cfg;$httpsCfg['base_url']='https://local-test.example';file_put_contents($testConfig,'<?php return '.var_export($httpsCfg,true).';');
putenv('AULIEATA_CONFIG='.$testConfig);
function tkAssert($ok,$label){if(!$ok)throw new RuntimeException($label);}
function tkReject(callable $fn,$label){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection: '.$label);}
function tkPending($pdo,$uid){$s=['user_id'=>$uid];$url=tiktokBegin($pdo,$uid,$s);parse_str(parse_url($url,PHP_URL_QUERY),$q);return [$s,['state'=>$q['state'],'code'=>'local-code-123456'],$q];}
function tkHttp($path,$body=null,$csrf=null,$jar='owner'){
    global $base,$jars;$jars[$jar]??=tempnam(sys_get_temp_dir(),'itu-tk-cookie-');$ch=curl_init($base.'/'.$path);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jars[$jar],CURLOPT_COOKIEJAR=>$jars[$jar],CURLOPT_TIMEOUT=>15];
    if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$csrf===null?http_build_query($body):json_encode($body);if($csrf!==null)$opts[CURLOPT_HTTPHEADER]=['Content-Type: application/json','X-CSRF-Token: '.$csrf];}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$status,$raw,json_decode((string)$raw,true)];
}
$secret=str_repeat('S',40);$payload=['access_token'=>'act.1!opaque/access+'.str_repeat('T',40).'=','refresh_token'=>'rft.1!opaque/refresh+'.str_repeat('R',40).'=','expires_in'=>86400,'refresh_expires_in'=>31536000,'token_type'=>'Bearer','open_id'=>'test-open-123456','scope'=>'user.info.basic'];$calls=[];
$mock=function($op,$params=[],$token='')use(&$calls,&$payload){$calls[]=$op;if($op==='profile')tkAssert($token===$payload['access_token'],'Profile token was changed');return $op==='token'?$payload:($op==='profile'?['data'=>['user'=>['open_id'=>$payload['open_id'],'display_name'=>'Local Test Creator']]]:[]);};
try{
    $pass='Local-Test-Only-2026';$phones=[];
    for($i=0;$i<2;$i++){$phones[]='+7'.random_int(1000000000,9999999999);opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',[end($phones),password_hash($pass,PASSWORD_DEFAULT),'TikTok test','cafe','Test']);$ids[]=(int)$pdo->lastInsertId();}
    [$uid,$other]=$ids;$_SESSION=[];
    foreach($ids as$id)tiktokSaveApp($pdo,$id,$cfg,'test_client_key',$secret);
    $app=opsOne($pdo,'SELECT * FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
    tkAssert($app['secret_box']!==$secret&&openToken($app['secret_box'],$cfg)===$secret,'App secret encryption');
    tkAssert(!str_contains(json_encode(tiktokStatus($pdo,$uid)),$secret),'App secret leak');
    [$s,$query,$params]=tkPending($pdo,$uid);
    tkAssert($params['redirect_uri']==='https://local-test.example/tiktok-callback.php'&&$params['scope']==='user.info.basic'&&$params['disable_auto_auth']==='1','OAuth request');
    tkAssert(opsOne($pdo,'SELECT state_hash FROM aulieata_tiktok_apps WHERE user_id=?',[$uid])['state_hash']!==$query['state'],'Raw state stored');
    $r=tiktokFinish($pdo,$uid,$cfg,$s,$query,$mock);tkAssert($r['connection']['display_name']==='Local Test Creator','Profile verification');
    $row=opsOne($pdo,'SELECT * FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
    tkAssert(openToken($row['token_box'],$cfg)===$payload['access_token']&&openToken($row['refresh_box'],$cfg)===$payload['refresh_token'],'Token encryption');
    tkAssert(!str_contains(json_encode($r),'act.')&&!isset($r['connection']['open_id']),'Public credential leak');
    $n=count($calls);tkReject(function()use($pdo,$uid,$cfg,&$s,$query,$mock){tiktokFinish($pdo,$uid,$cfg,$s,$query,$mock);},'replayed callback');tkAssert(count($calls)===$n,'Replay contacted provider');
    echo "PASS encrypted application and tokens, OAuth identity and one-time callback\n";

    [$s,$q]=tkPending($pdo,$other);tkReject(function()use($pdo,$other,$cfg,&$s,$q,$mock){tiktokFinish($pdo,$other,$cfg,$s,$q,$mock);},'tenant duplicate');tkAssert(tiktokStatus($pdo,$other)['connection']===null,'Account transferred');
    tkReject(fn()=>tiktokSaveApp($pdo,$uid,$cfg,'replacement',$secret),'replace connected app');
    $saved=$row['token_box'];$payload['open_id']='different-user';[$s,$q]=tkPending($pdo,$uid);tkReject(function()use($pdo,$uid,$cfg,&$s,$q,$mock){tiktokFinish($pdo,$uid,$cfg,$s,$q,$mock);},'silent replacement');
    tkAssert(opsOne($pdo,'SELECT token_box FROM aulieata_tiktok_connections WHERE user_id=?',[$uid])['token_box']===$saved,'Old credential destroyed');$payload['open_id']='test-open-123456';
    echo "PASS tenant isolation and replacement protection\n";

    foreach(['state','expired','other_owner','staff','config','disconnect','denied','malformed']as$case){
        [$s,$q]=tkPending($pdo,$other);$n=count($calls);
        if($case==='state')$q['state']=str_repeat('0',64);
        if($case==='expired')$s['tiktok_oauth']['until']=time()-1;
        if($case==='other_owner')$s['user_id']=$uid;
        if($case==='staff')$s['staff_id']=99;
        if($case==='config')tiktokSaveApp($pdo,$other,$cfg,'test_client_key',$secret);
        if($case==='disconnect')tiktokDisconnect($pdo,$other,$cfg,false,$mock);
        if($case==='denied')$q['error']='<script>untrusted provider message</script>';
        if($case==='malformed')$q['code']=['array'];
        tkReject(function()use($pdo,$other,$cfg,&$s,$q,$mock){tiktokFinish($pdo,$other,$cfg,$s,$q,$mock);},$case);
        tkAssert(count($calls)===$n,'Invalid callback contacted provider: '.$case);
    }
    echo "PASS wrong state, expiry, session owner, staff, config change, disconnect and denial\n";

    $payload['access_token']='act.2!rotated/access+'.str_repeat('N',40).'=';$payload['refresh_token']='rft.2!rotated/refresh+'.str_repeat('N',40).'=';
    opsExec($pdo,'UPDATE aulieata_tiktok_connections SET expires_at=? WHERE user_id=?',[gmdate('Y-m-d H:i:s',time()-10),$uid]);
    $failProfile=function($op,$params=[],$token='')use($mock){if($op==='profile')throw new TikTokError('Temporary outage');return $mock($op,$params,$token);};
    tkReject(fn()=>tiktokCheck($pdo,$uid,$cfg,$failProfile),'temporary profile failure');
    $row=opsOne($pdo,'SELECT * FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
    tkAssert(openToken($row['refresh_box'],$cfg)===$payload['refresh_token']&&$row['check_status']==='unavailable','Rotated token lost');
    $n=count(array_filter($calls,fn($x)=>$x==='token'));tiktokCheck($pdo,$uid,$cfg,$mock);
    tkAssert(count(array_filter($calls,fn($x)=>$x==='token'))===$n,'Fresh token refreshed twice');
    $failInvalid=function(){throw new TikTokError('Revoked',true);};tkReject(fn()=>tiktokCheck($pdo,$uid,$cfg,$failInvalid),'revoked access');tkAssert(tiktokStatus($pdo,$uid)['connection']['check_status']==='invalid','Revoked status');
    echo "PASS expiry refresh, refresh-token rotation and safe transient failure\n";

    $second=appDatabase();$lock=$second->prepare('SELECT GET_LOCK(?,0)');$lock->execute(['aulieata.tiktok.'.$uid]);
    try{tkReject(fn()=>tiktokDisconnect($pdo,$uid,$cfg,false,$mock),'concurrent disconnect');}finally{$second->query("SELECT RELEASE_LOCK('aulieata.tiktok.$uid')");}
    $revokeFail=function(){throw new TikTokError('Provider unavailable');};$r=tiktokDisconnect($pdo,$uid,$cfg,true,$revokeFail);
    tkAssert($r['revoked']===false&&!$r['tiktok']['configured']&&$r['tiktok']['connection']===null,'Revoke fallback');
    foreach(['operator','manager']as$role){opsExec($pdo,'INSERT INTO ops_staff(user_id,name,role,active) VALUES(?,?,?,1)',[$uid,'Test',$role]);$_SESSION=['staff_id'=>(int)$pdo->lastInsertId()];foreach(['tiktok_save_app','tiktok_begin','tiktok_check','tiktok_disconnect']as$action)tkReject(fn()=>opsStaffGate($pdo,$uid,$action,[]),'staff '.$action);}
    $_SESSION=[];echo "PASS serialized credential writes, disconnect fallback and staff gates\n";

    [$code,$html]=tkHttp('login.php');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);tkAssert(!empty($m[1]),'Login csrf');
    [$code]=tkHttp('login.php',['action'=>'login','phone'=>$phones[0],'password'=>$pass,'csrf'=>$m[1]]);tkAssert($code===302,'HTTP owner login');
    [$code,$html]=tkHttp('index.php');preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$m);tkAssert(!empty($m[1]),'App csrf');$csrf=$m[1];
    [$code]=tkHttp('index.php?api=1',['action'=>'tiktok_save_app','client_key'=>'test_http_key','client_secret'=>$secret],'wrong');tkAssert($code===403,'HTTP CSRF bypass');
    [$code,,$r]=tkHttp('index.php?api=1',['action'=>'tiktok_save_app','client_key'=>'test_http_key','client_secret'=>$secret],$csrf);tkAssert($code===200&&!str_contains(json_encode($r),$secret),'HTTP save secret');
    [$code,,$r]=tkHttp('index.php?api=1',['action'=>'load'],$csrf);tkAssert($code===200&&$r['tiktok']['configured']&&$r['tiktok']['connection']===null&&!str_contains(json_encode($r),$secret),'HTTP status');
    [$code,$html]=tkHttp('tiktok-callback.php?state=invalid&code=PRIVATE-CODE&error_description=PRIVATE-TEXT');tkAssert($code===400&&!str_contains($html,'PRIVATE-'),'Unsafe callback response');
    [$code,$html]=tkHttp('tiktok-callback.php?code=PRIVATE-CODE',null,null,'anonymous');tkAssert($code===400&&!str_contains($html,'PRIVATE-CODE'),'Anonymous callback');
    [$code]=tkHttp('index.php?api=1',null,null,'anonymous');tkAssert($code===401,'Anonymous API');
    echo "PASS real HTTP authentication, CSRF, safe status and callback rejection\n";
    echo "RESULT TikTok checks passed; provider mocked, no external requests or publications\n";
}finally{
    $_SESSION=[];foreach($ids as$id){opsExec($pdo,'DELETE FROM aulieata_tiktok_connections WHERE user_id=?',[$id]);opsExec($pdo,'DELETE FROM aulieata_tiktok_apps WHERE user_id=?',[$id]);opsExec($pdo,'DELETE FROM ops_staff WHERE user_id=?',[$id]);opsExec($pdo,'DELETE FROM users WHERE id=?',[$id]);}
    foreach($jars as$jar)@unlink($jar);@unlink($testConfig);putenv('AULIEATA_CONFIG='.$oldConfig);
}
