<?php
declare(strict_types=1);
require_once __DIR__.'/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try{$cfg=appConfig();$baseCfg=$cfg;}catch(Throwable $e){http_response_code(503);exit('{"ok":false}');}
require_once __DIR__.'/platform.php';$connectionUid=null;
if(isset($_GET['connection'])){try{$routeDb=appDatabase();$r=$routeDb->prepare('SELECT * FROM aulieata_connections WHERE route_key=? AND active=1');$r->execute([substr((string)$_GET['connection'],0,64)]);$c=$r->fetch();if(!$c){http_response_code(403);exit('{"ok":false}');}$connectionUid=(int)$c['user_id'];$cfg['telegram_token']=openToken($c['token_box'],$cfg);$cfg['webhook_secret']=openToken($c['secret_box'],$cfg);$routeDb=null;}catch(Throwable $e){http_response_code(503);exit('{"ok":false}');}}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{"ok":false}');}
if(!hash_equals($cfg['webhook_secret'],$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'')){http_response_code(403);exit('{"ok":false}');}
$raw=file_get_contents('php://input',false,null,0,1048577);
if(strlen($raw)>1048576){http_response_code(413);exit('{"ok":false}');}
$update=json_decode($raw,true);
if(!is_array($update)){http_response_code(400);exit('{"ok":false}');}
$msg=$update['message']??null;
if(!$msg||($msg['chat']['type']??'')!=='private'||!isset($update['update_id']))exit('{"ok":true,"ignored":true}');
require_once __DIR__.'/schema.php';require_once __DIR__.'/telegram-runtime.php';
try {
    $db=appDatabase();appSchema($db);tgInboxSchema($db);
    if(!$connectionUid){$q=$db->prepare('SELECT id FROM users WHERE phone IN (?,?,?) LIMIT 1');$q->execute([$cfg['owner_phone'],'+7'.substr($cfg['owner_phone'],1),'7'.substr($cfg['owner_phone'],1)]);$connectionUid=(int)$q->fetchColumn();}
    if(!$connectionUid)throw new RuntimeException('Business owner missing');
    tgInboxAccept($db,$connectionUid,$update,$baseCfg,$c['route_key']??null);
}catch(Throwable $e){error_log('AulieAta Telegram intake: '.get_class($e));http_response_code(503);exit('{"ok":false}');}
// Telegram may retry until this acknowledgement; the unique update ID prevents duplicates.
echo '{"ok":true}';ignore_user_abort(true);@set_time_limit(240);
// CGI hosting cannot detach its HTTP response. Exit promptly and let the minute
// worker handle the saved update; otherwise long ASR/TTS makes Telegram retry.
if(!function_exists('fastcgi_finish_request')||!fastcgi_finish_request())exit;
try{tgInboxRun($db,$baseCfg,2,$connectionUid);}catch(Throwable $e){error_log('AulieAta Telegram worker: '.get_class($e));}
