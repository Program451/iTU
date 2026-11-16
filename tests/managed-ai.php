<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';
$original=appConfig();if(!str_starts_with($original['db_name']??'','itu_test_')||($original['db_host']??'')!=='127.0.0.1')exit(2);
$pdo=appDatabase();appSchema($pdo);$users=[];$old=getenv('AULIEATA_CONFIG');$temp=tempnam(sys_get_temp_dir(),'aulieata-managed-');
function managedCheck($condition,$message){if(!$condition)throw new RuntimeException($message);}
try{
    foreach([1,2] as $i){opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',['+7'.random_int(1000000000,9999999999),password_hash('Local-Test-Only-2026',PASSWORD_DEFAULT),'Managed AI test','it','Test']);$users[]=(int)$pdo->lastInsertId();}
    [$owner,$other]=$users;$cfg=$original;$cfg['gemini_key']='test-key-not-a-live-credential';$cfg['gemini_media_users']=[$owner];
    file_put_contents($temp,'<?php return '.var_export($cfg,true).';');putenv('AULIEATA_CONFIG='.$temp);
    managedCheck(imageAiConnection($pdo,$owner,$cfg)['provider']==='gemini','Configured workspace missing access');
    managedCheck(imageAiConnection($pdo,$other,$cfg)===null,'Unlisted workspace received shared key access');
    $safe=imageAiStatus($pdo,$owner);managedCheck($safe['managed']&&$safe['account_id']===''&&!str_contains(json_encode($safe),$cfg['gemini_key']),'Credential data exposed');
    echo "PASS managed Gemini workspace isolation and safe status\n";
    $job=imageAiQueue($pdo,$owner,['request_key'=>bin2hex(random_bytes(16)),'prompt'=>'A simple test image of a coffee cup']);
    $changed=$cfg;$changed['gemini_key']='a-different-test-key';imageAiRun($pdo,$changed);
    managedCheck(opsOne($pdo,'SELECT status FROM image_ai_jobs WHERE id=?',[$job['id']])['status']==='cancelled','Rotated key reused old authorization');
    echo "PASS provider rotation cancels queued work before inference\n";
    imageAiDisconnect($pdo,$owner);managedCheck(imageAiConnection($pdo,$owner,$cfg)===null,'Disconnected shared service remained active');
    echo "PASS explicit disconnect disables managed access\n";
    imageAiConnect($pdo,$owner,['account_id'=>str_repeat('a',32),'token'=>str_repeat('t',40),'free_plan'=>true],$cfg,fn()=>[['name'=>IMAGE_AI_MODEL]]);
    managedCheck(imageAiConnection($pdo,$owner,$cfg)['provider']==='cloudflare','Explicit provider selection not respected');
    echo "PASS explicit Cloudflare connection retains provider choice\n";
}finally{
    putenv($old===false?'AULIEATA_CONFIG':'AULIEATA_CONFIG='.$old);unlink($temp);
    foreach($users as $uid){foreach(['image_ai_jobs','image_ai_connections','aulieata_records'] as $table)opsExec($pdo,'DELETE FROM '.$table.' WHERE user_id=?',[$uid]);opsExec($pdo,'DELETE FROM users WHERE id=?',[$uid]);}
}
