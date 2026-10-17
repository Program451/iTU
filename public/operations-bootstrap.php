<?php
require_once __DIR__.'/database.php';
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
ini_set('display_errors','0');
$runtimeConfig=require dirname(__DIR__).'/aulieata-secrets.php';
$pdo=appDatabase();
require_once __DIR__.'/platform.php';
require_once __DIR__.'/operations.php';
platformSchema($pdo);catalogDataSchema($pdo);opsSchema($pdo);
