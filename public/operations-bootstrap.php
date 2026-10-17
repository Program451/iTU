<?php
require_once __DIR__.'/database.php';
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
ini_set('display_errors','0');
$runtimeConfig=appConfig();
$pdo=appDatabase();
require_once __DIR__.'/platform.php';
require_once __DIR__.'/operations.php';
require_once __DIR__.'/schema.php';appSchema($pdo);
