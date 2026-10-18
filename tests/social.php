<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();
if(!str_starts_with($cfg['db_name']??'','itu_test_')||!in_array($cfg['db_host']??'',['127.0.0.1','localhost'],true)||!str_starts_with(appUrl(),'http://127.0.0.1:'))exit(2);
$pdo=appDatabase();appSchema($pdo);$_SESSION=[];$uids=[];$files=[];$jars=[];
function socialAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function socialReject(callable $f,$label){try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection: '.$label);}
function socialHttp($path,$body=null,$csrf=null,$jar='owner'){
    global $jars;$jars[$jar]??=tempnam(sys_get_temp_dir(),'itu-social-');$ch=curl_init(appUrl($path));$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jars[$jar],CURLOPT_COOKIEJAR=>$jars[$jar],CURLOPT_TIMEOUT=>15];
    if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$csrf===null?http_build_query($body):opsJson($body);if($csrf!==null)$opts[CURLOPT_HTTPHEADER]=['Content-Type: application/json','X-CSRF-Token: '.$csrf];}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$status,$raw,json_decode((string)$raw,true)];
}
function socialFixture(PDO $pdo,int $uid,array $data=[]): array {
    global $image,$files;$r=socialSave($pdo,$uid,array_merge(['request_key'=>bin2hex(random_bytes(16)),'title'=>'Проверка карточки','caption'=>'Тестовый пост без реальной отправки','design'=>['headline'=>'Проверка','brand'=>'Тестовая кофейня'],'image'=>$image],$data));$files[]=socialRow($pdo,$uid,$r['id'])['image_key'];return $r;
}
function socialReady(PDO $pdo,int $id):void{opsExec($pdo,'UPDATE social_posts SET next_check=? WHERE id=?',[socialNow(),$id]);}
try{
    $phone='+7'.random_int(1000000000,1999999999);$pass=bin2hex(random_bytes(16));
    foreach([$phone,'social-other-'.bin2hex(random_bytes(8))] as $p){opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',[$p,password_hash($pass,PASSWORD_DEFAULT),'Social test','cafe','Тараз']);$uids[]=(int)$pdo->lastInsertId();}
    [$uid,$other]=$uids;$account='17841400000111';$profile=['user_id'=>$account,'username'=>'social_test','account_type'=>'BUSINESS'];
    instagramConnect($pdo,$uid,$cfg,str_repeat('T',64),fn($t,$path)=>$path==='/me'?$profile:['data'=>[['config'=>['quota_total'=>100],'quota_usage'=>0]]]);
    $img=imagecreatetruecolor(1080,1350);imagefill($img,0,0,imagecolorallocate($img,20,60,40));ob_start();imagejpeg($img,null,85);$image='data:image/jpeg;base64,'.base64_encode(ob_get_clean());imagedestroy($img);
    $request=bin2hex(random_bytes(16));$post=socialFixture($pdo,$uid,['request_key'=>$request]);$again=socialFixture($pdo,$uid,['request_key'=>$request]);socialAssert($post['id']===$again['id'],'Duplicate save created a second post');
    socialAssert(!isset($post['public_key'],$post['image_key'])&&count(socialList($pdo,$other))===0,'Private row data leaked');socialReject(fn()=>socialRow($pdo,$other,$post['id']),'cross-tenant read');
    socialReject(fn()=>socialQueue($pdo,$uid,['id'=>$post['id'],'version'=>1,'account_id'=>$account]),'missing approval');
    socialReject(fn()=>socialQueue($pdo,$uid,['id'=>$post['id'],'version'=>1,'approved'=>true,'account_id'=>'wrong']),'account mismatch');
    socialReject(fn()=>socialSave($pdo,$uid,['id'=>$post['id'],'version'=>0,'request_key'=>$request,'title'=>'x','caption'=>'x','design'=>['headline'=>'x'],'image'=>$image]),'stale edit');
    echo "PASS private drafts, save idempotency, account approval and stale edits\n";

    [$status,$html]=socialHttp('login.php');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$match);socialAssert(!empty($match[1]),'Login form');
    [$status]=socialHttp('login.php',['action'=>'login','phone'=>$phone,'password'=>$pass,'csrf'=>$match[1]]);socialAssert($status===302,'Login');
    [$status,$html]=socialHttp('index.php');preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$match);$csrf=$match[1]??'';socialAssert($csrf!=='','App token');
    [$status,$jpeg]=socialHttp($post['image_url']);socialAssert($status===200&&str_starts_with($jpeg,chr(255).chr(216)),'Owner preview');
    [$status]=socialHttp($post['image_url'],null,null,'anonymous');socialAssert($status===404,'Anonymous draft preview');
    [$status]=socialHttp('index.php?api=1',['action'=>'social_queue','id'=>$post['id'],'approved'=>true],'wrong');socialAssert($status===403,'CSRF bypass');
    [$status,,$list]=socialHttp('index.php?api=1',['action'=>'social_list'],$csrf);socialAssert($status===200&&count($list['posts'])===1,'HTTP list');
    foreach(['operator','manager'] as $role){opsExec($pdo,'INSERT INTO ops_staff(user_id,name,role,active) VALUES(?,?,?,1)',[$uid,'Test',$role]);$_SESSION=['staff_id'=>(int)$pdo->lastInsertId()];foreach(['social_list','social_save','social_queue','social_cancel','social_archive'] as $action)socialReject(fn()=>opsStaffGate($pdo,$uid,$action,[]),'staff '.$action);}$_SESSION=[];
    echo "PASS HTTP preview authentication, CSRF and employee restrictions\n";

    $scheduled=(new DateTimeImmutable('+2 hours',new DateTimeZone('Asia/Qyzylorda')))->format('Y-m-d\TH:i');
    $queue=['id'=>$post['id'],'version'=>1,'account_id'=>$account,'approved'=>true,'scheduled_at'=>$scheduled];$q=socialQueue($pdo,$uid,$queue);socialQueue($pdo,$uid,$queue);
    socialAssert($q['status']==='queued'&&strtotime($q['due_at'].' UTC')>time()+7100,'UTC+5 schedule conversion');
    $never=fn()=>throw new RuntimeException('Future post should not reach provider');socialPublishStep($pdo,$post['id'],$cfg,$never);
    socialReject(fn()=>socialSave($pdo,$uid,['id'=>$post['id'],'version'=>1,'request_key'=>$request,'title'=>'x','caption'=>'x','design'=>['headline'=>'x'],'image'=>$image]),'edit approved snapshot');
    $draft=socialChange($pdo,$uid,$post['id'],'cancel');socialAssert($draft['status']==='draft'&&$draft['version']===2,'Cancel invalidates approval');
    socialReject(fn()=>socialQueue($pdo,$uid,$queue),'old approval after cancel');
    foreach(['2026-02-30T10:00','2000-01-01T10:00',(new DateTimeImmutable('+31 days'))->format('Y-m-d\TH:i')] as $time)socialReject(fn()=>socialQueue($pdo,$uid,array_merge($queue,['version'=>2,'scheduled_at'=>$time])),'invalid schedule');
    echo "PASS scheduled timing, cancellation, immutable approved snapshot and date validation\n";

    $calls=[];$mock=function($token,$method,$path,$params)use(&$calls){$calls[]=[$method,$path,$params];if(str_ends_with($path,'content_publishing_limit'))return ['data'=>[['config'=>['quota_total'=>100],'quota_usage'=>0]]];if(str_ends_with($path,'/media'))return ['id'=>'900001'];if(str_ends_with($path,'/media_publish'))return ['id'=>'800001'];if(($params['fields']??'')==='permalink')return ['permalink'=>'https://www.instagram.com/p/TEST123/'];return ['status_code'=>'FINISHED'];};
    socialQueue($pdo,$uid,array_merge($queue,['version'=>2,'scheduled_at'=>'']));socialPublishStep($pdo,$post['id'],$cfg,$mock);
    $r=socialRow($pdo,$uid,$post['id']);socialAssert($r['status']==='processing'&&$r['container_id']==='900001','Prepare container');
    [$status]=socialHttp('social-media.php?id='.$post['id'].'&k='.$r['public_key'],null,null,'anonymous');socialAssert($status===200,'Approved signed image unavailable');
    [$status]=socialHttp('social-media.php?id='.$post['id'].'&k='.str_repeat('a',64),null,null,'anonymous');socialAssert($status===404,'Wrong image key accepted');
    socialReady($pdo,$post['id']);socialPublishStep($pdo,$post['id'],$cfg,$mock);socialReady($pdo,$post['id']);socialPublishStep($pdo,$post['id'],$cfg,$mock);
    $r=socialRow($pdo,$uid,$post['id']);$sends=array_filter($calls,fn($c)=>str_ends_with($c[1],'/media_publish'));socialAssert($r['status']==='published'&&$r['media_id']==='800001'&&count($sends)===1,'Exactly one publication');
    echo "PASS signed image delivery, container readiness, publication receipt and duplicate prevention\n";

    $amb=socialFixture($pdo,$uid);socialQueue($pdo,$uid,['id'=>$amb['id'],'version'=>1,'account_id'=>$account,'approved'=>true]);socialPublishStep($pdo,$amb['id'],$cfg,$mock);socialReady($pdo,$amb['id']);$attempts=0;
    $timeout=function($t,$m,$p,$d)use($mock,&$attempts){if(str_ends_with($p,'/media_publish')){$attempts++;throw new SocialProviderError('Timeout',true);}return $mock($t,$m,$p,$d);};
    socialPublishStep($pdo,$amb['id'],$cfg,$timeout);socialAssert(socialRow($pdo,$uid,$amb['id'])['status']==='uncertain','Lost acknowledgement should be uncertain');
    socialReady($pdo,$amb['id']);socialPublishStep($pdo,$amb['id'],$cfg,$timeout);socialAssert($attempts===1,'Retried uncertain publication');socialReject(fn()=>socialChange($pdo,$uid,$amb['id'],'cancel'),'unsafe retry of uncertain post');
    socialReady($pdo,$amb['id']);socialPublishStep($pdo,$amb['id'],$cfg,fn()=>['status_code'=>'PUBLISHED']);socialAssert(socialRow($pdo,$uid,$amb['id'])['status']==='published','Reconciliation failed');
    echo "PASS uncertain delivery is never resent and can reconcile read-only\n";

    $dbFail=socialFixture($pdo,$uid);socialQueue($pdo,$uid,['id'=>$dbFail['id'],'version'=>1,'account_id'=>$account,'approved'=>true]);socialPublishStep($pdo,$dbFail['id'],$cfg,$mock);socialReady($pdo,$dbFail['id']);
    $trigger='social_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON social_posts FOR EACH ROW BEGIN IF NEW.id=".(int)$dbFail['id']." AND NEW.status='published' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated receipt write failure'; END IF; END");
    try{socialPublishStep($pdo,$dbFail['id'],$cfg,$mock);socialAssert(socialRow($pdo,$uid,$dbFail['id'])['status']==='uncertain','Receipt write failure permitted resend');}finally{$pdo->exec('DROP TRIGGER '.$trigger);}
    socialReady($pdo,$dbFail['id']);socialPublishStep($pdo,$dbFail['id'],$cfg,fn()=>['status_code'=>'PUBLISHED']);socialAssert(socialRow($pdo,$uid,$dbFail['id'])['status']==='published','Receipt recovery');
    echo "PASS database failure after provider acceptance remains read-only until reconciled\n";

    $reversible=socialFixture($pdo,$uid);socialChange($pdo,$uid,$reversible['id'],'archive');socialAssert(socialRow($pdo,$uid,$reversible['id'])['status']==='archived','Archive');socialChange($pdo,$uid,$reversible['id'],'restore');socialAssert(socialRow($pdo,$uid,$reversible['id'])['status']==='draft','Restore');
    $second=appDatabase();$lock='aulieata_social_'.$reversible['id'];opsOne($pdo,'SELECT GET_LOCK(?,0) acquired',[$lock]);
    try{socialReject(fn()=>socialChange($second,$uid,$reversible['id'],'archive'),'concurrent mutation');}finally{opsOne($pdo,'SELECT RELEASE_LOCK(?) released',[$lock]);}
    $lim=socialFixture($pdo,$uid);socialQueue($pdo,$uid,['id'=>$lim['id'],'version'=>1,'account_id'=>$account,'approved'=>true]);
    socialPublishStep($pdo,$lim['id'],$cfg,fn()=>['data'=>[['config'=>['quota_total'=>100],'quota_usage'=>100]]]);socialAssert(socialRow($pdo,$uid,$lim['id'])['status']==='failed','Quota guard');
    $late=socialFixture($pdo,$uid);socialQueue($pdo,$uid,['id'=>$late['id'],'version'=>1,'account_id'=>$account,'approved'=>true]);opsExec($pdo,'UPDATE social_posts SET due_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',time()-7201),$late['id']]);socialPublishStep($pdo,$late['id'],$cfg,$never);socialAssert(socialRow($pdo,$uid,$late['id'])['status']==='failed','Stale scheduled offer published');
    echo "PASS recoverable archive, independent connection lock, quota and stale-schedule protection\n";

    $fail=socialFixture($pdo,$uid);socialQueue($pdo,$uid,['id'=>$fail['id'],'version'=>1,'account_id'=>$account,'approved'=>true]);socialPublishStep($pdo,$fail['id'],$cfg,fn()=>throw new SocialProviderError('Token revoked'));
    socialAssert(socialRow($pdo,$uid,$fail['id'])['status']==='failed','Invalid token');socialChange($pdo,$uid,$fail['id'],'cancel');
    socialQueue($pdo,$uid,['id'=>$fail['id'],'version'=>2,'account_id'=>$account,'approved'=>true]);instagramDisconnect($pdo,$uid);socialPublishStep($pdo,$fail['id'],$cfg,$never);socialAssert(socialRow($pdo,$uid,$fail['id'])['status']==='failed','Disconnected account was used');
    opsExec($pdo,'UPDATE social_posts SET public_until=? WHERE id=?',[gmdate('Y-m-d H:i:s',time()-1),$post['id']]);[$status]=socialHttp('social-media.php?id='.$post['id'].'&k='.$r['public_key'],null,null,'anonymous');socialAssert($status===404,'Expired public capability');
    socialReject(fn()=>socialImageSave('data:image/jpeg;base64,'.base64_encode('<?php echo 1;')),'non-image upload');
    echo "PASS disconnected/expired credentials, image validation and public-link expiry\n";
    echo "RESULT social tests passed; provider mocked, no external posts or paid calls\n";
}finally{
    $_SESSION=[];foreach($uids as $id){foreach(opsAll($pdo,'SELECT image_key FROM social_posts WHERE user_id=?',[$id]) as $row)$files[]=$row['image_key'];foreach(['social_posts','aulieata_instagram_connections','ops_staff','ops_audit'] as $table)opsExec($pdo,'DELETE FROM '.$table.' WHERE user_id=?',[$id]);opsExec($pdo,'DELETE FROM users WHERE id=?',[$id]);}
    foreach(array_unique($files) as $key)if(is_file(socialImagePath($key)))unlink(socialImagePath($key));foreach($jars as $jar)if(is_file($jar))unlink($jar);
}
