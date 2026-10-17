<?php
require_once __DIR__.'/database.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$cfg=require dirname(__DIR__).'/aulieata-secrets.php';require __DIR__.'/platform.php';
$pdo=appDatabase();platformSchema($pdo);opsSchema($pdo);$lock=$pdo->query("SELECT GET_LOCK('aulieata_daily_worker',0)")->fetchColumn();if(!$lock)exit;
try{$sent=opsRunOutbox($pdo);$n=runDueJobs($pdo);$pdo->exec("INSERT INTO aulieata_records(user_id,kind,payload) VALUES(0,'worker_health',JSON_OBJECT('name','Scheduler heartbeat','time',UTC_TIMESTAMP()))");echo 'Completed jobs: '.$n.PHP_EOL;}finally{$pdo->query("SELECT RELEASE_LOCK('aulieata_daily_worker')");}
