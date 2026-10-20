<?php
// Isolated local fixtures; injected AI only, no external inference calls.
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
require_once dirname(__DIR__).'/public/business.php';
$cfg=appConfig();if(!str_starts_with($cfg['db_name']??'','itu_test_')||($cfg['db_host']??'')!=='127.0.0.1'||!str_starts_with(appUrl(),'http://127.0.0.1:'))exit(2);
$pdo=appDatabase();appSchema($pdo);$uids=[];$jars=[];$_SESSION=[];
function bgAssert($ok,$label){if(!$ok)throw new RuntimeException($label);}
function bgReject($fn,$label){try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;return;}throw new RuntimeException('Not rejected: '.$label);}
function bgHttp($path,$body=null,$csrf=null,$jar='owner'){
 global $jars;$jars[$jar]??=tempnam(sys_get_temp_dir(),'itu-beginner-');
 $ch=curl_init(appUrl($path));$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jars[$jar],CURLOPT_COOKIEJAR=>$jars[$jar],CURLOPT_TIMEOUT=>20];
 if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$csrf===null?http_build_query($body):json_encode($body);if($csrf!==null)$opts[CURLOPT_HTTPHEADER]=['Content-Type: application/json','X-CSRF-Token: '.$csrf];}
 curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$status,$raw,json_decode((string)$raw,true)];
}
try{
 bgAssert(array_keys(businessTypes())===array_keys(beginnerSectors()),'28 exact sectors');
 bgAssert(count(businessActivityTypes())===29&&isset(businessActivityTypes()['beginner']),'Novice separate from 28 sectors');
 foreach(beginnerSectors() as $type=>$sector){beginnerInput($sector['sample']);foreach(['ru','kk'] as $l)foreach(['pitch','plus','risk','test','competition'] as $key)bgAssert(mb_strlen($sector[$l][$key])>20,"Missing editorial $type/$l/$key");}
 $input=beginnerSectors()['cafe']['sample'];
 $calc=beginnerCalculate($input);
 bgAssert($calc['fixed']===640000&&$calc['scenarios']['base']['net']===335000.0&&$calc['breakeven_orders']===394,'Base formula');
 $loss=beginnerCalculate(array_replace($input,['orders'=>0,'capital'=>0,'growth'=>0]));
 bgAssert($loss['months'][5]['cash']===-5340000.0&&$loss['funding_gap']===5340000.0&&$loss['payback_month']===null,'Loss/cash gap');
 bgAssert(beginnerCalculate(array_replace($input,['variable'=>90,'tax'=>10]))['breakeven_orders']===null,'No unit margin');
 foreach([['ticket'=>0],['orders'=>-1],['growth'=>51],['variable'=>101],['tax'=>51],['capital'=>INF],['city'=>[]],['experience'=>str_repeat('я',701)]] as $bad)bgReject(fn()=>beginnerInput(array_replace($input,$bad)),'Invalid input');
 echo "PASS 28 bilingual sectors, novice category, deterministic cash/scenarios and bounds\n";
 $pass=bin2hex(random_bytes(16));$phone='+7'.random_int(2000000000,2999999999);
 [$status,$html]=bgHttp('login.php');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);
 bgAssert(str_contains($html,'value="beginner"'),'Registration option');
 [$status,$html]=bgHttp('login.php',['action'=>'register','csrf'=>$m[1],'phone'=>$phone,'password'=>$pass,'biz_name'=>'Beginner test','biz_category'=>'beginner','location'=>'Тараз']);
 $q=$pdo->prepare('SELECT id FROM users WHERE phone=?');$q->execute([$phone]);$uid=(int)$q->fetchColumn();bgAssert($uid>0&&$status===200,'Novice registration');$uids[]=$uid;
 $q=$pdo->prepare('INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)');$q->execute(['bg-other-'.bin2hex(random_bytes(8)),password_hash($pass,PASSWORD_DEFAULT),'Other','cafe','Тараз']);$other=(int)$pdo->lastInsertId();$uids[]=$other;
 bgAssert(businessProfile($pdo,$uid)['modules']===['beginner','beginner_economy','beginner_mentor','settings'],'Dedicated novice menu');
 $save=['type'=>'cafe','input'=>$input,'checklist'=>[],'revision'=>0];
 $p=beginnerSave($pdo,$uid,$save);bgAssert($p['revision']===1,'First save');
 bgReject(fn()=>beginnerSave($pdo,$uid,$save),'Stale revision');
 bgReject(fn()=>beginnerSave($pdo,$uid,array_replace($save,['type'=>'beginner'])),'Invalid choice');
 bgReject(fn()=>beginnerSave($pdo,$uid,array_replace($save,['checklist'=>[['nested']]])),'Malformed checks');
 bgAssert(beginnerPlan($pdo,$other,'cafe')===null,'Owner isolation');
 beginnerSave($pdo,$uid,['type'=>'edu','input'=>beginnerSectors()['edu']['sample'],'checklist'=>[],'revision'=>0]);
 bgAssert(beginnerPlan($pdo,$uid,'cafe')['input']['ticket']===$p['input']['ticket'],'Type isolation');
 $report=['summary'=>'Короткий учебный разбор','competition'=>'Проверьте предложения клиентов','answer'=>'Проведите небольшой тест','strengths'=>['Понятный формат'],'risks'=>['Нет подтверждённого спроса'],'next_steps'=>['Поговорите с клиентами'],'questions'=>['Кто ваш клиент?']];
 $request=['type'=>'cafe','language'=>'ru','question'=>'Как начать?','revision'=>1,'request_key'=>bin2hex(random_bytes(16))];$calls=0;
 $mock=function($system,$context)use(&$calls,$report){$calls++;bgAssert($context['calculated']['breakeven_orders']===394,'AI must receive exact calculation');bgAssert(!str_contains(json_encode($context),'token_box'),'No credentials in context');return $report;};
 $p=beginnerAnalyze($pdo,$uid,$request,[],$mock);bgAssert($p['revision']===2&&$p['reports']['ru']['data']['answer']===$report['answer'],'Structured advice');
 $repeat=array_replace($request,['revision'=>2]);bgReject(fn()=>beginnerAnalyze($pdo,$uid,$repeat,[],$mock),'Duplicate request');bgAssert($calls===1,'Duplicate billed');
 $p=beginnerSave($pdo,$uid,array_replace($save,['revision'=>2]));bgAssert(isset($p['reports']['ru']),'Unchanged input preserves report');
 $p=beginnerSave($pdo,$uid,array_replace($save,['revision'=>$p['revision'],'checklist'=>['demand']]));bgAssert(!$p['reports']&&!$p['messages'],'Changed evidence invalidates advice');
 $badRequest=array_replace($request,['revision'=>$p['revision'],'request_key'=>bin2hex(random_bytes(16))]);
 bgReject(fn()=>beginnerAnalyze($pdo,$uid,$badRequest,[],fn()=>['summary'=>'incomplete']),'Malformed AI report');
 bgAssert(beginnerPlan($pdo,$uid,'cafe')['revision']===$p['revision'],'Failure retained plan');
 $race=array_replace($badRequest,['request_key'=>bin2hex(random_bytes(16))]);
 bgReject(fn()=>beginnerAnalyze($pdo,$uid,$race,[],function()use($pdo,$uid,$save,$p,$report){beginnerSave($pdo,$uid,array_replace($save,['revision'=>$p['revision'],'input'=>array_replace($save['input'],['orders'=>100])]));return $report;}),'Stale inference result');
 bgAssert((float)beginnerPlan($pdo,$uid,'cafe')['input']['orders']===100.0,'Concurrent edit preserved');
 bgReject(fn()=>beginnerAnalyze($pdo,$uid,$request,[]),'No provider is explicit');
 echo "PASS revisions, ownership, per-sector plans, AI validation/deduplication/failure/concurrent-edit protection\n";
 opsExec($pdo,"INSERT INTO aulieata_records(user_id,kind,payload) VALUES(?,'clients',?)",[$uid,opsJson(['name'=>'Keep client'])]);
 businessSaveProfile($pdo,$uid,['type'=>'cafe','description'=>'Cafe description','rules'=>'Cafe rules']);
 beginnerSelect($pdo,$uid,['type'=>'edu','expected_type'=>'cafe','confirmed'=>true]);
 businessSaveProfile($pdo,$uid,['type'=>'edu','description'=>'School description','rules'=>'School rules']);
 $p=beginnerSelect($pdo,$uid,['type'=>'cafe','expected_type'=>'edu','confirmed'=>true]);
 bgAssert($p['description']==='Cafe description'&&$p['rules']==='Cafe rules','Details restore');
 bgAssert(opsOne($pdo,'SELECT biz_category FROM users WHERE id=?',[$uid])['biz_category']==='cafe','Login category sync');
 bgAssert(opsOne($pdo,"SELECT COUNT(*) n FROM aulieata_records WHERE user_id=? AND kind='clients' AND archived=0",[$uid])['n']===1,'Client preservation');
 bgReject(fn()=>beginnerSelect($pdo,$uid,['type'=>'edu','expected_type'=>'cafe']),'Explicit selection required');
 bgReject(fn()=>beginnerSelect($pdo,$uid,['type'=>'edu','expected_type'=>'beginner','confirmed'=>true]),'Stale workspace selection');
 businessSaveProfile($pdo,$uid,['type'=>'beginner','description'=>'','rules'=>'']);
 [$status,$html]=bgHttp('login.php');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);
 [$status]=bgHttp('login.php',['action'=>'login','phone'=>$phone,'password'=>$pass,'csrf'=>$m[1]]);bgAssert($status===302,'Novice login');
 [$status,$html]=bgHttp('index.php');preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$m);$csrf=$m[1];
 [$status,,$load]=bgHttp('index.php?api=1',['action'=>'load'],$csrf);bgAssert($status===200&&$load['profile']['type']==='beginner','Beginner load works');
 [$status,,$guide]=bgHttp('index.php?api=1',['action'=>'beginner_load'],$csrf);bgAssert($status===200&&count($guide['sectors'])===28,'Guide HTTP');
 [$status]=bgHttp('index.php?api=1',['action'=>'beginner_select','type'=>'beauty','expected_type'=>'beginner','confirmed'=>true],'wrong');bgAssert($status===403,'CSRF');
 [$status,,$selected]=bgHttp('index.php?api=1',['action'=>'beginner_select','type'=>'beauty','expected_type'=>'beginner','confirmed'=>true],$csrf);
 bgAssert($status===200&&$selected['profile']['type']==='beauty'&&!in_array('beginner',$selected['profile']['modules'],true),'Selection restores normal menu');
 [$status,,$load]=bgHttp('index.php?api=1',['action'=>'load'],$csrf);bgAssert($load['profile']['type']==='beauty','Durable selection');
 [$status]=bgHttp('index.php?api=1',['action'=>'beginner_load'],'wrong','guest');bgAssert($status===401,'Guest denied');
 opsExec($pdo,"INSERT INTO ops_staff(user_id,name,role,active) VALUES(?,'Staff','manager',1)",[$uid]);$_SESSION=['staff_id'=>(int)$pdo->lastInsertId()];
 foreach(['beginner_load','beginner_select','beginner_analyze','beginner_save'] as $action)bgReject(fn()=>opsStaffGate($pdo,$uid,$action,[]),'Staff denied '.$action);$_SESSION=[];
 echo "PASS registration/login, separate menu, confirmed switch, restored details, preserved clients, HTTP CSRF/auth/staff protections\n";
 imageAiConnect($pdo,$uid,['account_id'=>bin2hex(random_bytes(16)),'token'=>'fixture_'.bin2hex(random_bytes(20)),'free_plan'=>true],$cfg,fn()=>[['name'=>IMAGE_AI_MODEL]]);
 bgAssert(beginnerProvider($pdo,$uid,[])==='Cloudflare','Uses owner Cloudflare connection');
 $inferences=0;
 $cloud=function($account,$token,$body)use(&$inferences,$report){$inferences++;bgAssert($body['max_tokens']===2600,'Bounded mentor output');return $report;};
 for($i=0;$i<8;$i++)cloudText($pdo,$uid,$cfg,'beginner','Test',[],$cloud);
 bgReject(fn()=>cloudText($pdo,$uid,$cfg,'beginner','Test',[],$cloud),'Ninth mentor inference');
 bgAssert($inferences===8,'Daily limit precedes external call');
 echo "PASS shared Cloudflare connection, reserved budget and eight-attempt daily mentor limit\n";
 echo "RESULT beginner guide passed; no external services called\n";
}finally{
 $_SESSION=[];foreach($uids as $uid){foreach(['beginner_plans','beginner_requests','aulieata_records','ops_staff','cloud_text_usage','image_ai_connections'] as $table)opsExec($pdo,'DELETE FROM '.$table.' WHERE user_id=?',[$uid]);foreach(['app_announcement_state','app_announcement_reads'] as $table)opsExec($pdo,'DELETE FROM '.$table.' WHERE subject=?',['user:'.$uid]);opsExec($pdo,'DELETE FROM users WHERE id=?',[$uid]);opsExec($pdo,'DELETE FROM ops_limits WHERE bucket=?',[hash('sha256','beginner-ai:'.$uid)]);}
 foreach($jars as $jar)if(is_file($jar))unlink($jar);
}
