<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();if(!str_starts_with($cfg['db_name']??'','itu_test_')||!in_array($cfg['db_host']??'',['127.0.0.1','localhost'],true)||!str_starts_with(appUrl(),'http://127.0.0.1:'))exit(2);
$pdo=appDatabase();appSchema($pdo);require_once dirname(__DIR__).'/public/business.php';$_SESSION=[];$uids=[];$jars=[];
function bpAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function bpHttp($path,$body=null,$csrf=null,$jar='owner'){
    global $jars;$jars[$jar]??=tempnam(sys_get_temp_dir(),'itu-business-');$ch=curl_init(appUrl($path));$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jars[$jar],CURLOPT_COOKIEJAR=>$jars[$jar],CURLOPT_TIMEOUT=>15];
    if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$csrf===null?http_build_query($body):json_encode($body);if($csrf!==null)$opts[CURLOPT_HTTPHEADER]=['Content-Type: application/json','X-CSRF-Token: '.$csrf];}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$status,$raw,json_decode((string)$raw,true)];
}
try{
    bpAssert(array_keys(businessTypes())===array_keys(businessPresets()),'All business types must have a preset in order');
    foreach(businessPresets() as $type=>$preset){bpAssert(count($preset['topics'])===3&&count(array_unique($preset['topics']))===3&&strlen($preset['check'])>20,'Editorial coverage '.$type);$p=businessProfileInput(['type'=>$type,'promotion'=>businessPromotionDefaults($type)],[]);bpAssert($p['promotion_by_type'][$type]['theme']===$preset['theme'],'Preset theme');}
    echo "PASS 28 complete industry presets and validated defaults\n";
    $pass=bin2hex(random_bytes(20));$phone='+7'.random_int(2000000000,2999999999);
    foreach([$phone,'business-other-'.bin2hex(random_bytes(8))] as $p){$q=$pdo->prepare('INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)');$q->execute([$p,password_hash($pass,PASSWORD_DEFAULT),'Business profile test','cafe','Тараз']);$uids[]=(int)$pdo->lastInsertId();}
    [$uid,$other]=$uids;$new=['type'=>'cafe','description'=>'Кофейня','rules'=>'Уточнить условия','promotion'=>array_merge(businessPromotionDefaults('cafe'),['audience'=>'INTERNAL-AUDIENCE','cta'=>'Напишите о меню','language'=>'kk'])];
    businessSaveProfile($pdo,$uid,$new);businessSaveProfile($pdo,$uid,['type'=>'edu','description'=>'Школа','promotion'=>array_merge(businessPromotionDefaults('edu'),['tone'=>'expert'])]);
    businessSaveProfile($pdo,$uid,['type'=>'cafe','description'=>'Кофейня','rules'=>'PUBLIC-RULE']);$profile=businessProfile($pdo,$uid);
    bpAssert($profile['promotion']['language']==='kk'&&$profile['promotion']['cta']==='Напишите о меню','Switching types lost preferences');
    bpAssert($profile['promotion_by_type']['edu']['tone']==='expert','Other type lost settings');
    bpAssert(businessProfile($pdo,$other)['promotion']['audience']==='','Cross-workspace preferences');
    $public=businessPublicProfile($profile);bpAssert(!str_contains(json_encode($public),'INTERNAL-AUDIENCE')&&$public['rules']==='PUBLIC-RULE','Public bot profile leaked marketing preferences');
    echo "PASS round trip, old-client compatibility, type switching and workspace/public-bot isolation\n";
    [$status,$html]=bpHttp('login.php');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);
    [$status]=bpHttp('login.php',['action'=>'login','phone'=>$phone,'password'=>$pass,'csrf'=>$m[1]]);bpAssert($status===302,'Login');
    [$status,$html]=bpHttp('index.php');preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$m);$csrf=$m[1];
    [$status]=bpHttp('index.php?api=1',['action'=>'profile','data'=>$new],'wrong');bpAssert($status===403,'CSRF accepted');
    [$status]=bpHttp('index.php?api=1',['action'=>'profile','data'=>$new],$csrf);bpAssert($status===200,'HTTP profile save');
    foreach([['type'=>'missing'],['type'=>[]],['promotion'=>['language'=>'fr']],['promotion'=>['audience'=>[]]],['promotion'=>['cta'=>str_repeat('x',71)]],['description'=>str_repeat('x',3001)]] as $bad){
        [$status]=bpHttp('index.php?api=1',['action'=>'profile','data'=>array_replace($new,$bad)],$csrf);bpAssert($status===422,'Bad profile not rejected');
    }
    [$status,,$load]=bpHttp('index.php?api=1',['action'=>'load'],$csrf);bpAssert($status===200&&$load['profile']['promotion']['cta']==='Напишите о меню','Read after save or failed validation lost data');
    $q=$pdo->prepare("SELECT COUNT(*) FROM aulieata_records WHERE user_id=? AND kind='profile' AND archived=0");$q->execute([$uid]);bpAssert((int)$q->fetchColumn()===1,'Multiple active profiles');
    $q=$pdo->prepare("INSERT INTO ops_staff(user_id,name,role,active) VALUES(?,'Test','manager',1)");$q->execute([$uid]);$_SESSION=['staff_id'=>(int)$pdo->lastInsertId()];$blocked=false;try{opsStaffGate($pdo,$uid,'profile',['data'=>$new]);}catch(RuntimeException $e){$blocked=true;}bpAssert($blocked,'Staff modified profile');$_SESSION=[];
    echo "PASS HTTP save/load, CSRF, owner role and atomic validation failures\n";
    echo "RESULT business profiles passed; no external services called\n";
}finally{
    $_SESSION=[];foreach($uids as $uid){foreach(['aulieata_records','ops_staff'] as $table){$q=$pdo->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$uid]);}$q=$pdo->prepare('DELETE FROM users WHERE id=?');$q->execute([$uid]);}
    foreach($jars as $jar)if(is_file($jar))unlink($jar);
}
