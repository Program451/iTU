<?php
// Business operations. Every mutable entity is scoped to its workspace owner.
function opsSchema($pdo){
    static $ready=false;if($ready)return;
    $defs=[
        "ops_settings(user_id INT PRIMARY KEY,payload MEDIUMTEXT NOT NULL)",
        "ops_orders(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,request_key VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL,source VARCHAR(24) NOT NULL,chat_id BIGINT NULL,total DECIMAL(16,2) NOT NULL,is_demo TINYINT NOT NULL DEFAULT 0,payload MEDIUMTEXT NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,UNIQUE KEY request_once(user_id,request_key),INDEX owner_status(user_id,status))",
        "ops_reservations(user_id INT NOT NULL,order_id BIGINT NOT NULL,batch_id BIGINT NOT NULL,quantity DECIMAL(16,3) NOT NULL,active TINYINT NOT NULL DEFAULT 1,PRIMARY KEY(user_id,order_id,batch_id),INDEX batch_active(user_id,batch_id,active))",
        "ops_resources(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,name VARCHAR(180) NOT NULL,active TINYINT DEFAULT 1,payload MEDIUMTEXT NOT NULL,INDEX owner(user_id))",
        "ops_appointments(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,resource_id BIGINT NOT NULL,item_id INT NOT NULL,starts_at DATETIME NOT NULL,ends_at DATETIME NOT NULL,status VARCHAR(24) NOT NULL,chat_id BIGINT NULL,payload MEDIUMTEXT NOT NULL,created_at DATETIME NOT NULL,INDEX overlap_check(user_id,resource_id,starts_at,ends_at))",
        "ops_chats(user_id INT NOT NULL,chat_id BIGINT NOT NULL,payload MEDIUMTEXT NOT NULL,PRIMARY KEY(user_id,chat_id))",
        "ops_audit(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,actor VARCHAR(100) NOT NULL,kind VARCHAR(40) NOT NULL,target_id BIGINT NULL,payload MEDIUMTEXT NOT NULL,undone TINYINT DEFAULT 0,created_at DATETIME NOT NULL,INDEX owner(user_id,id))",
        "ops_staff(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,name VARCHAR(100) NOT NULL,role VARCHAR(20) NOT NULL,login VARCHAR(100) NULL UNIQUE,password_hash VARCHAR(255) NULL,invite_hash VARCHAR(64) NULL UNIQUE,invite_expires DATETIME NULL,active TINYINT DEFAULT 1,INDEX owner(user_id))",
        "ops_links(token VARCHAR(64) PRIMARY KEY,user_id INT NOT NULL,kind VARCHAR(30) NOT NULL,target_id BIGINT NOT NULL DEFAULT 0,active TINYINT DEFAULT 1,created_at DATETIME NOT NULL,INDEX owner_target(user_id,kind,target_id))",
        "ops_demands(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,source_id BIGINT NOT NULL,chat_id BIGINT NULL,reason VARCHAR(30) NOT NULL,topic VARCHAR(180) NOT NULL,excerpt TEXT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY evidence(user_id,source_id,reason))",
        "ops_outbox(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,chat_id BIGINT NOT NULL,ref_key VARCHAR(120) NOT NULL,text TEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',due_at DATETIME NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY message_once(user_id,ref_key),INDEX due(status,due_at))",
        "ops_limits(bucket VARCHAR(64) PRIMARY KEY,window_start BIGINT NOT NULL,hits INT NOT NULL)"
    ];
    foreach($defs as $def)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$def.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');$ready=true;
}
function opsNow(){return (new DateTimeImmutable('now',new DateTimeZone('Asia/Qyzylorda')))->format('Y-m-d H:i:s');}
function opsJson($v){return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);}
function opsText($v,$max=180){return mb_substr(trim((string)$v),0,$max);}
function opsNumber($v,$min=0,$max=1000000000){$n=filter_var(str_replace(',','.',(string)$v),FILTER_VALIDATE_FLOAT);if($n===false||!is_finite($n)||$n<$min||$n>$max)throw new RuntimeException('Проверьте числовое значение');return $n;}
function opsOne($pdo,$sql,$args=[]){$q=$pdo->prepare($sql);$q->execute($args);return $q->fetch()?:null;}
function opsAll($pdo,$sql,$args=[]){$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();}
function opsExec($pdo,$sql,$args=[]){$q=$pdo->prepare($sql);$q->execute($args);return $q->rowCount();}
function opsRecord($pdo,$uid,$kind,$data){opsExec($pdo,'INSERT INTO aulieata_records(user_id,kind,payload) VALUES(?,?,?)',[$uid,$kind,opsJson($data)]);return (int)$pdo->lastInsertId();}
function opsGetRecord($pdo,$uid,$id,$kind,$lock=false){$r=opsOne($pdo,'SELECT * FROM aulieata_records WHERE user_id=? AND id=? AND kind=? AND archived=0'.($lock?' FOR UPDATE':''),[$uid,$id,$kind]);if(!$r)throw new RuntimeException('Запись не найдена');$r['data']=json_decode($r['payload'],true)?:[];return $r;}
function opsPutRecord($pdo,$uid,$id,$data){opsExec($pdo,'UPDATE aulieata_records SET payload=? WHERE user_id=? AND id=?',[opsJson($data),$uid,$id]);}
function opsRecords($pdo,$uid,$kind){$rows=opsAll($pdo,'SELECT id,payload,created_at FROM aulieata_records WHERE user_id=? AND kind=? AND archived=0 ORDER BY id DESC',[$uid,$kind]);foreach($rows as &$r){$r['data']=json_decode($r['payload'],true)?:[];unset($r['payload']);}return $rows;}
function opsTx($pdo,$uid,$fn){$outer=$pdo->inTransaction();if(!$outer)$pdo->beginTransaction();try{if(!opsOne($pdo,'SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]))throw new RuntimeException('Рабочее пространство не найдено');$v=$fn();if(!$outer)$pdo->commit();return $v;}catch(Throwable $e){if(!$outer&&$pdo->inTransaction())$pdo->rollBack();throw $e;}}
function opsActor(){return !empty($_SESSION['staff_id'])?'staff:'.(int)$_SESSION['staff_id']:'owner';}
function opsAudit($pdo,$uid,$kind,$target,$data,$actor=null){opsExec($pdo,'INSERT INTO ops_audit(user_id,actor,kind,target_id,payload,created_at) VALUES(?,?,?,?,?,?)',[$uid,$actor??opsActor(),$kind,$target,opsJson($data),opsNow()]);return (int)$pdo->lastInsertId();}
function opsSettings($pdo,$uid){$r=opsOne($pdo,'SELECT payload FROM ops_settings WHERE user_id=?',[$uid]);return array_merge(['bot_mode'=>'ai','orders_enabled'=>false,'bookings_enabled'=>false,'auto_confirm_booking'=>false,'pickup_note'=>'','feedback_title'=>'Расскажите, как прошло посещение','reminder_hours'=>24],json_decode($r['payload']??'{}',true)?:[]);}
function opsSaveSettings($pdo,$uid,$d){$s=opsSettings($pdo,$uid);if(isset($d['bot_mode'])){if(!in_array($d['bot_mode'],['free','cloud','ai'],true))throw new RuntimeException('Выберите режим бота');$s['bot_mode']=$d['bot_mode'];}foreach(['orders_enabled','bookings_enabled','auto_confirm_booking','bot_collect_contact','bot_voice_enabled'] as $k)if(array_key_exists($k,$d))$s[$k]=filter_var($d[$k],FILTER_VALIDATE_BOOLEAN);foreach(['pickup_note','feedback_title'] as $k)if(isset($d[$k]))$s[$k]=opsText($d[$k],500);if(isset($d['reminder_hours']))$s['reminder_hours']=(int)opsNumber($d['reminder_hours'],1,72);opsExec($pdo,'INSERT INTO ops_settings(user_id,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)',[$uid,opsJson($s)]);return $s;}
function opsRateLimit($pdo,$bucket,$max,$seconds){$key=hash('sha256',$bucket);$window=(int)(floor(time()/$seconds)*$seconds);opsExec($pdo,'INSERT INTO ops_limits(bucket,window_start,hits) VALUES(?,?,1) ON DUPLICATE KEY UPDATE hits=IF(window_start=VALUES(window_start),hits+1,1),window_start=VALUES(window_start)',[$key,$window]);$r=opsOne($pdo,'SELECT hits FROM ops_limits WHERE bucket=?',[$key]);if((int)$r['hits']>$max)throw new RuntimeException('Слишком много попыток. Повторите позже.');}
function opsLink($pdo,$uid,$kind,$id=0,$rotate=false){if(!in_array($kind,['feedback','passport']))throw new RuntimeException('Неизвестная ссылка');if($rotate)opsExec($pdo,'UPDATE ops_links SET active=0 WHERE user_id=? AND kind=? AND target_id=?',[$uid,$kind,$id]);$r=opsOne($pdo,'SELECT token FROM ops_links WHERE user_id=? AND kind=? AND target_id=? AND active=1 LIMIT 1',[$uid,$kind,$id]);if($r)return $r['token'];$token=bin2hex(random_bytes(32));opsExec($pdo,'INSERT INTO ops_links(token,user_id,kind,target_id,created_at) VALUES(?,?,?,?,?)',[$token,$uid,$kind,$id,opsNow()]);return $token;}
function opsChat($pdo,$uid,$chat){$r=opsOne($pdo,'SELECT payload FROM ops_chats WHERE user_id=? AND chat_id=?',[$uid,$chat]);return json_decode($r['payload']??'{}',true)?:[];}
function opsSaveChat($pdo,$uid,$chat,$d){opsExec($pdo,'INSERT INTO ops_chats(user_id,chat_id,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)',[$uid,$chat,opsJson($d)]);}
function opsQueue($pdo,$uid,$chat,$key,$text,$due=null){
    if(!$chat)return;
    // A moved appointment can return to its original slot; delivered messages stay delivered.
    opsExec($pdo,"INSERT INTO ops_outbox(user_id,chat_id,ref_key,text,due_at,created_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=IF(status='cancelled',VALUES(chat_id),chat_id),text=IF(status='cancelled',VALUES(text),text),due_at=IF(status='cancelled',VALUES(due_at),due_at),status=IF(status='cancelled','queued',status)",[$uid,$chat,$key,opsText($text,3500),$due??opsNow(),opsNow()]);
}
function opsStaffGate($pdo,$uid,$action,$in){
    if(empty($_SESSION['staff_id']))return 'owner';$staff=opsOne($pdo,'SELECT id,role,user_id,active FROM ops_staff WHERE id=?',[(int)$_SESSION['staff_id']]);
    if(!$staff||!$staff['active']||(int)$staff['user_id']!==$uid){$_SESSION=[];if(session_status()===PHP_SESSION_ACTIVE)session_destroy();throw new RuntimeException('Доступ сотрудника отключён. Войдите заново.');}
    $_SESSION['staff_role']=$staff['role'];
    $operator=['load','ops_load','ops_quote','ops_order_create','ops_order_status','ops_resource_list','ops_slots','ops_appointment_save','ops_appointment_status','ops_ticket_update','ops_ticket_reply','ops_shift_parse','ops_shift_save','ops_shift_task','ops_passport_save','ops_passport_event','ops_transcribe','ops_budget'];
    $manager=array_merge($operator,['catalog','edit_catalog','archive_catalog','restore_catalog','resource_save','ops_resource_save','ops_campaign_save','ops_scenario','ops_supplier_parse','ops_supplier_save','ops_supplier_compare','ops_supplier_draft','ops_report','ops_import_preview','ops_import_apply','ops_undo','analyze_import','commit_smart_import','promotion_generate','promotion_publish','promotion_task']);
    if(!in_array($action,$staff['role']==='manager'?$manager:$operator,true))throw new RuntimeException('Для этого действия нужен владелец бизнеса.');return $staff['role'];
}
function opsFilterOutput($data){
    if(empty($_SESSION['staff_id'])||!is_array($data))return $data;
    foreach(['connection','jobs','automation_logs','history','agent_plans','market','my_publications','coop_members','archives','documents','expenses','offers','worker_last_seen','tasks'] as $key)if(array_key_exists($key,$data))$data[$key]=[];
    if(isset($data['knowledge']))$data['knowledge']=array_values(array_filter($data['knowledge'],fn($r)=>($r['data']['audience']??'internal')==='public'));
    foreach(['telegram','telegram_messages'] as $key)if(isset($data[$key]))$data[$key]=array_values(array_filter($data[$key],fn($r)=>empty($r['data']['owner_private'])));
    if(($_SESSION['staff_role']??'operator')==='operator'){
        foreach(['stock_batches','business_capacity','promotion_drafts','business_signals','suppliers','audit'] as $key)if(isset($data[$key]))$data[$key]=[];
        $redact=function($value)use(&$redact){if(!is_array($value))return $value;foreach($value as $key=>$child){if(in_array($key,['cost','item_cost','allocations','min_margin'],true))unset($value[$key]);else $value[$key]=$redact($child);}return $value;};
        $data=$redact($data);
    }
    return $data;
}
require_once __DIR__.'/operations-commerce.php';
require_once __DIR__.'/operations-schedule.php';
require_once __DIR__.'/operations-tools.php';
require_once __DIR__.'/operations-assistant.php';
