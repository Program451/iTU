<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();if(!str_starts_with($cfg['db_name']??'','itu_test_')||!in_array($cfg['db_host']??'',['localhost','127.0.0.1'],true))exit(2);
$pdo=appDatabase();appSchema($pdo);$_SESSION=[];$uid=(int)$argv[1];$d=json_decode(base64_decode($argv[3]),true);$d['request_key']='race-'.bin2hex(random_bytes(12));fgets(STDIN);
try{$r=$argv[2]==='booking'?opsAppointmentSave($pdo,$uid,$d):opsCreateOrder($pdo,$uid,$d);echo json_encode(['ok'=>true,'id'=>$r['id']]);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;echo json_encode(['ok'=>false]);}
