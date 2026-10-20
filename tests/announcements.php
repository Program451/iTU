<?php
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();
if (!str_starts_with($cfg['db_name']??'','itu_test_') || !in_array($cfg['db_host']??'',['127.0.0.1','localhost'],true) || !str_starts_with(appUrl(),'http://127.0.0.1:')) exit(2);
$video=getenv('AULIEATA_TEST_VIDEO');
if (!$video || !is_file($video)) { fwrite(STDERR,"Set AULIEATA_TEST_VIDEO to a small valid MP4 fixture.\n"); exit(2); }
$pdo=appDatabase();appSchema($pdo);$originalWelcome=$pdo->query('SELECT welcome_id FROM app_announcement_settings WHERE id=1')->fetchColumn();$suffix=(string)random_int(100000,999999);$phone='+70001'.$suffix;$adminPhone='+70002'.$suffix;$pass='Local-fixture-2026';$jars=[];$ids=[];$mediaIds=[];$userIds=[];
function checkAnnouncement($ok,$message){if(!$ok)throw new RuntimeException($message);}
function annHttp($path,$data=null,$jar='user',$csrf=null,$headers=[],$method=null){global $jars;$jars[$jar]??=tempnam(sys_get_temp_dir(),'itu-ann-');$ch=curl_init(appUrl($path));$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jars[$jar],CURLOPT_COOKIEJAR=>$jars[$jar],CURLOPT_TIMEOUT=>25];if($data!==null){$options[CURLOPT_POST]=true;if(is_array($data)&&isset($data['video'])&&$data['video'] instanceof CURLFile)$options[CURLOPT_POSTFIELDS]=$data;elseif($csrf!==null){$headers[]='Content-Type: application/json';$options[CURLOPT_POSTFIELDS]=json_encode($data);}else $options[CURLOPT_POSTFIELDS]=http_build_query($data);}if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;if($method)$options[CURLOPT_CUSTOMREQUEST]=$method;$options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,$body,json_decode((string)$body,true)];}
function annCsrf($html){preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);return $m[1]??'';}
function annLogin($phone,$password,$jar='user',$admin=false){$path='login.php';[, $html]=annHttp($path,null,$jar);[$code]=annHttp($path,['action'=>'login','phone'=>$phone,'password'=>$password,'csrf'=>annCsrf($html)],$jar);checkAnnouncement($code===302,'Sign in '.$jar);[, $html]=annHttp($admin?'admin.php':'index.php',null,$jar);preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$m);checkAnnouncement(!empty($m[1]),'CSRF bootstrap '.$jar);return $m[1];}
function annApi($action,$data=[],$jar='user',$token=null){global $tokens;return annHttp('announcement-api.php',['action'=>$action]+$data,$jar,$token??$tokens[$jar]);}
function annDraft($kind,$media){global $ids;[$code,,$r]=annApi('admin_save',['kind'=>$kind,'title_ru'=>'Проверка <script>alert(1)</script>','title_kk'=>'Тексеру','body_ru'=>'Текст администратора','body_kk'=>'Әкімші мәтіні','version'=>'test','media_id'=>$media],'admin');checkAnnouncement($code===200,'Save draft');$ids[]=$r['id'];return $r['id'];}
try {
 checkAnnouncement(announcementUploadLimit('50M','50M')===49*1048576,'Hosting multipart limit');
 checkAnnouncement(announcementUploadLimit('100M','105M')===100*1048576,'Application upload ceiling');
 checkAnnouncement(announcementUploadLimit('1G','0')===100*1048576,'Unlimited PHP post ceiling');
 $q=$pdo->prepare('INSERT INTO users(phone,password,biz_name,biz_category,location,created_at) VALUES(?,?,?,?,?,DATE_SUB(NOW(),INTERVAL 1 DAY))');$q->execute([$phone,password_hash($pass,PASSWORD_DEFAULT),'Existing account','services','Тараз']);$old=(int)$pdo->lastInsertId();$userIds[]=$old;
 $q=$pdo->prepare('INSERT INTO app_admins(phone,password_hash,name) VALUES(?,?,?)');$q->execute([$adminPhone,password_hash($pass,PASSWORD_DEFAULT),'Fixture admin']);$adminId=(int)$pdo->lastInsertId();
 $tokens['user']=annLogin($phone,$pass);$tokens['admin']=annLogin($adminPhone,$pass,'admin',true);
 checkAnnouncement(annHttp('admin-login.php',null,'legacy')[0]===303,'Legacy admin link redirects to shared sign in');
 [, $html]=annHttp('login.php',null,'invalidAdmin');checkAnnouncement(!str_contains($html,'href="admin-login.php"'),'No separate administrator sign-in link');
 [$code,$html]=annHttp('login.php',['action'=>'login','phone'=>$adminPhone,'password'=>'Wrong-password','csrf'=>annCsrf($html)],'invalidAdmin');checkAnnouncement($code===200&&str_contains($html,'Неверный номер телефона или пароль.'),'Wrong admin password rejected in shared form');
 checkAnnouncement(annHttp('admin.php',null,'invalidAdmin')[0]===302,'Phone alone cannot grant admin access');
 [, $html]=annHttp('login.php',null,'invalidCsrf');[$code,$html]=annHttp('login.php',['action'=>'login','phone'=>$adminPhone,'password'=>$pass,'csrf'=>'wrong'],'invalidCsrf');checkAnnouncement($code===200&&str_contains($html,'Обновите страницу и повторите вход.'),'Shared admin sign in requires CSRF');
 [, $html]=annHttp('login.php',null,'duplicateAdmin');[$code,$html]=annHttp('login.php',['action'=>'register','phone'=>$adminPhone,'password'=>$pass,'csrf'=>annCsrf($html),'biz_name'=>'Duplicate'],'duplicateAdmin');checkAnnouncement($code===200&&str_contains($html,'Этот номер уже зарегистрирован.'),'Registration cannot shadow an admin number');
 $pdo->prepare('UPDATE app_admins SET active=0 WHERE id=?')->execute([$adminId]);
 [, $html]=annHttp('login.php',null,'inactiveAdmin');[$code,$html]=annHttp('login.php',['action'=>'login','phone'=>$adminPhone,'password'=>$pass,'csrf'=>annCsrf($html)],'inactiveAdmin');checkAnnouncement($code===200&&str_contains($html,'Неверный номер телефона или пароль.'),'Inactive administrator cannot sign in');
 $pdo->prepare('UPDATE app_admins SET active=1 WHERE id=?')->execute([$adminId]);
 $tokens['formatted']=annLogin('8'.substr($adminPhone,2),$pass,'formatted',true);checkAnnouncement(annApi('admin_list',[],'formatted')[0]===200,'Shared admin sign in normalizes phone');
 annHttp('login.php?logout=1',null,'formatted');checkAnnouncement(annHttp('admin.php',null,'formatted')[0]===302,'Shared logout clears admin session');
 // An existing business account on the same phone remains a separate role.
 $q=$pdo->prepare('INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)');$q->execute([$adminPhone,password_hash('Owner-fixture-2026',PASSWORD_DEFAULT),'Same phone business','services','Тараз']);$userIds[]=(int)$pdo->lastInsertId();
 $tokens['samePhone']=annLogin($adminPhone,'Owner-fixture-2026','samePhone');checkAnnouncement(annApi('admin_list',[],'samePhone')[0]===403,'Same phone with business credentials has no administrator privileges');

 checkAnnouncement(annHttp('admin.php',null,'user')[0]===302,'Owner cannot open admin console');
 checkAnnouncement(annApi('admin_list')[0]===403,'Owner cannot read admin API');
 checkAnnouncement(annApi('admin_save',[],'admin','wrong')[0]===403,'CSRF enforced');
 checkAnnouncement(annHttp('announcement-api.php',['action'=>'pending'],'anonymous','wrong')[0]===401,'Anonymous rejected');
 [$code,,$uploaded]=annHttp('announcement-api.php',['action'=>'admin_upload','video'=>new CURLFile($video,'video/mp4','intro.mp4')],'admin',$tokens['admin']);checkAnnouncement($code===200,'Video upload');$media=$uploaded['id'];$mediaIds[]=$media;
 [$code]=annHttp('announcement-api.php',['action'=>'admin_upload','video'=>new CURLFile(__FILE__,'video/mp4','fake.mp4')],'admin',$tokens['admin']);checkAnnouncement($code===422,'Reject script disguised as video');
 $welcome=annDraft('welcome',$media);
 checkAnnouncement(annHttp('announcement-media.php?id='.$media,null,'user')[0]===404,'Draft video private');
 checkAnnouncement(annApi('admin_publish',['id'=>$welcome],'admin')[0]===200,'Publish welcome');
 [$code,,$pending]=annApi('pending');checkAnnouncement(!in_array($welcome,array_column($pending['items'],'id')),'Existing user skips welcome');
 $newPhone='+70003'.$suffix;[, $html]=annHttp('login.php',null,'new');[$code,$html]=annHttp('login.php',['action'=>'register','csrf'=>annCsrf($html),'phone'=>$newPhone,'password'=>$pass,'biz_name'=>'New user','biz_category'=>'cafe','location'=>'Тараз'],'new');checkAnnouncement($code===200&&str_contains($html,'Аккаунт создан'),'Registration success');$q=$pdo->prepare('SELECT id FROM users WHERE phone=?');$q->execute([$newPhone]);$new=(int)$q->fetchColumn();$userIds[]=$new;
 $tokens['new']=annLogin($newPhone,$pass,'new');[$code,,$pending]=annApi('pending',[],'new');checkAnnouncement((int)$pending['items'][0]['id']===$welcome,'New user gets welcome');
 checkAnnouncement(annApi('admin_list',[],'new')[0]===403,'Registration cannot grant admin');
 checkAnnouncement(annApi('acknowledge',['id'=>$welcome], 'new')[0]===200,'Acknowledge welcome');
 checkAnnouncement(annApi('acknowledge',['id'=>$welcome], 'new')[0]===200,'Acknowledgement idempotent');
 annHttp('login.php?logout=1',null,'new');$tokens['new']=annLogin($newPhone,$pass,'new');checkAnnouncement(count(annApi('pending',[],'new')[2]['items'])===0,'Welcome stays dismissed after fresh login');
 $update=annDraft('update',$media);checkAnnouncement(annApi('admin_publish',['id'=>$update],'admin')[0]===200,'Publish update');
 checkAnnouncement(in_array($update,array_column(annApi('pending')[2]['items'],'id')),'Old user gets update');checkAnnouncement(in_array($update,array_column(annApi('pending',[],'new')[2]['items'],'id')),'New user gets later update');
 checkAnnouncement(annApi('acknowledge',['id'=>$update])[0]===200,'Acknowledge update');checkAnnouncement(!in_array($update,array_column(annApi('pending')[2]['items'],'id')),'Update only once');checkAnnouncement(in_array($update,array_column(annApi('pending',[],'new')[2]['items'],'id')),'Read state separated per user');
 checkAnnouncement(annApi('admin_publish',['id'=>$update],'admin')[0]===200,'Publish retry idempotent');checkAnnouncement(!in_array($update,array_column(annApi('pending')[2]['items'],'id')),'Publish retry does not repeat popup');
 checkAnnouncement(annApi('admin_save',['id'=>$update,'kind'=>'update','title_ru'=>'Changed','body_ru'=>'Changed'],'admin')[0]===422,'Published content immutable');
 checkAnnouncement(annApi('acknowledge',['id'=>999999999])[0]===422,'Invalid acknowledgement rejected');
 [$code,$bytes]=annHttp('announcement-media.php?id='.$media,null,'user',null,['Range: bytes=0-15']);checkAnnouncement($code===206&&strlen($bytes)===16,'Video seeking range');
 [$code,$bytes]=annHttp('announcement-media.php?id='.$media,null,'user',null,['Range: bytes=-10']);checkAnnouncement($code===206&&strlen($bytes)===10,'Video suffix range');
 checkAnnouncement(annHttp('announcement-media.php?id='.$media,null,'user',null,['Range: bytes=999999999-'])[0]===416,'Invalid range rejected');
 checkAnnouncement(annHttp('announcement-media.php?id='.$media,null,'anonymous')[0]===404,'Video requires sign in');
 checkAnnouncement(annHttp('announcement-media.php?id=../../private',null,'admin')[0]===404,'Path traversal rejected');
 $replacement=annDraft('welcome',$media);annApi('admin_publish',['id'=>$replacement],'admin');checkAnnouncement(!in_array($replacement,array_column(annApi('pending',[],'new')[2]['items'],'id')),'Replacing welcome does not replay onboarding');
 annApi('admin_withdraw',['id'=>$update],'admin');checkAnnouncement(!in_array($update,array_column(annApi('pending',[],'new')[2]['items'],'id')),'Withdraw removes pending release');
 $staffLogin='ann-staff-'.$suffix;$q=$pdo->prepare('INSERT INTO ops_staff(user_id,name,role,login,password_hash) VALUES(?,?,?,?,?)');$q->execute([$old,'Fixture staff','operator',$staffLogin,password_hash($pass,PASSWORD_DEFAULT)]);$staffId=(int)$pdo->lastInsertId();
 [, $html]=annHttp('staff.php',null,'staff');[$code]=annHttp('staff.php',['csrf'=>annCsrf($html),'login'=>$staffLogin,'password'=>$pass],'staff');checkAnnouncement($code===302,'Staff sign in');[, $html]=annHttp('index.php',null,'staff');preg_match('/"csrf":"([a-f0-9]{64})"/',$html,$m);$tokens['staff']=$m[1];checkAnnouncement(annApi('admin_list',[],'staff')[0]===403,'Staff cannot administer announcements');checkAnnouncement(annApi('pending',[],'staff')[0]===200,'Staff can read updates');$pdo->prepare('UPDATE ops_staff SET active=0 WHERE id=?')->execute([$staffId]);checkAnnouncement(annApi('pending',[],'staff')[0]>=400,'Revoked staff cannot read updates');checkAnnouncement(annHttp('announcement-media.php?id='.$media,null,'staff')[0]===404,'Revoked staff cannot read videos');
 for($i=0;$i<105;$i++){$q=$pdo->prepare("INSERT INTO app_announcements(admin_id,kind,status,title_ru,title_kk,body_ru,body_kk,media_id,published_at) VALUES(?,'update','published','Pagination','Беттер','Text','Мәтін',?,NOW())");$q->execute([$adminId,$media]);$ids[]=(int)$pdo->lastInsertId();}
 $last=end($ids);checkAnnouncement(annApi('acknowledge',['id'=>$last])[0]===200,'Read receipt works beyond the first 100 releases');$history=annApi('history')[2]['items'];checkAnnouncement(count($history)===100&&(int)$history[0]['id']===$last,'History contains newest releases');
 echo "PASS: registration, shared login with verified admin routing, invalid credentials/CSRF, disabled admin and duplicate phone protection, owner/staff roles, revoked sessions, CSRF, upload validation, private drafts, durable one-time welcome, per-user updates, idempotent publication, immutable releases, video ranges, withdrawal and history pagination.\n";
} finally {
 foreach($ids as $id){$pdo->prepare('DELETE FROM app_announcement_reads WHERE announcement_id=?')->execute([$id]);$pdo->prepare('UPDATE app_announcement_settings SET welcome_id=NULL WHERE welcome_id=?')->execute([$id]);$pdo->prepare('DELETE FROM app_announcements WHERE id=?')->execute([$id]);}
 foreach($mediaIds as $id){$q=$pdo->prepare('SELECT file_key FROM app_announcement_media WHERE id=?');$q->execute([$id]);$key=$q->fetchColumn();if($key&&is_file(announcementMediaDirectory().'/'.$key))unlink(announcementMediaDirectory().'/'.$key);$pdo->prepare('DELETE FROM app_announcement_media WHERE id=?')->execute([$id]);}
 foreach($userIds as $id){$pdo->prepare('DELETE FROM app_announcement_state WHERE subject=?')->execute(['user:'.$id]);$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
 $pdo->prepare('UPDATE app_announcement_settings SET welcome_id=? WHERE id=1')->execute([$originalWelcome?:null]);
 if(isset($staffId))$pdo->prepare('DELETE FROM ops_staff WHERE id=?')->execute([$staffId]);
 if(isset($adminId))$pdo->prepare('DELETE FROM app_admins WHERE id=?')->execute([$adminId]);
 foreach($jars as $jar)if(is_file($jar))unlink($jar);
}
