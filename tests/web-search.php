<?php
require dirname(__DIR__).'/public/database.php';require dirname(__DIR__).'/public/schema.php';require dirname(__DIR__).'/public/web-search.php';
$cfg=appConfig();if(!str_starts_with($cfg['db_name']??'','itu_test_')||($cfg['db_host']??'')!=='127.0.0.1')exit(2);
$pdo=appDatabase();appSchema($pdo);$uid=random_int(100000000,999999999);$cfg['tavily_key']='test-search-'.bin2hex(random_bytes(10));$cfg['tavily_users']=[$uid];$calls=0;
function searchCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function searchReject(callable $fn,string $message){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException($message);}
$rows=[['url'=>'https://www.gov.kz/memleket/entities/test','title'=>'Government','content'=>'Official excerpt.'],['url'=>'https://damu.kz/program','title'=>'Damu','content'=>'Programme terms.']];
$request=function($key,$body)use(&$calls,$rows){$calls++;searchCheck($body['search_depth']==='basic'&&$body['auto_parameters']===false,'Unbounded search cost');searchCheck(!isset($body['catalog'])&&!isset($body['knowledge']),'Private context sent to search');return $rows;};
$generate=fn($cfg,$system,$data)=>['answer'=>'See the programme [2], and government [1]. More details [2, 1].'];
$daily=hash('sha256','tavily-user:'.$uid.':'.gmdate('Y-m-d'));$monthly=hash('sha256','tavily-month:'.$cfg['tavily_key']);
try{
    searchReject(fn()=>webSearchAnswer($pdo,$uid+1,$cfg,'Test',$request,$generate),'Other workspace accessed shared credential');searchCheck($calls===0,'Unauthorized request sent');
    $unsafe=[['url'=>'https://gov.kz.evil.test/','content'=>'bad'],['url'=>'https://gov.kz@evil.test/','content'=>'bad'],['url'=>'http://gov.kz/','content'=>'bad'],['url'=>'https://gov.kz:8443/','content'=>'bad'],['url'=>'https://evil.test/gov.kz','content'=>'bad'],null];
    searchCheck(count(webSearchSources(array_merge($rows,$rows,$unsafe)))===2,'Source allowlist or deduplication failed');
    echo "PASS search workspace isolation and official-source filtering\n";
    $out=webSearchAnswer($pdo,$uid,$cfg,'Test',$request,$generate);
    searchCheck(str_contains($out['text'],'programme [1], and government [2]')&&str_contains($out['text'],'details [1, 2]')&&$out['sources'][0]['title']==='Damu','Citations do not match links');
    searchCheck(!str_contains(json_encode($out),$cfg['tavily_key']),'Credential leaked');
    searchReject(fn()=>webSearchAnswer($pdo,$uid,$cfg,'Test',$request,fn()=>['answer'=>'Unknown [9]']),'Unknown citation accepted');
    searchReject(fn()=>webSearchAnswer($pdo,$uid,$cfg,'Test',$request,fn()=>['answer'=>'Unknown [1, 9]']),'Unknown grouped citation accepted');
    searchReject(fn()=>webSearchAnswer($pdo,$uid,$cfg,'Test',$request,fn()=>['answer'=>'Invented https://evil.test [1]']),'Unverified URL accepted');
    echo "PASS search citation mapping and unverified-answer rejection\n";
    opsExec($pdo,'UPDATE ops_limits SET hits=900 WHERE bucket=?',[$monthly]);$before=$calls;
    searchReject(fn()=>webSearchAnswer($pdo,$uid,$cfg,'Test',$request,$generate),'Monthly quota bypassed');searchCheck($calls===$before,'Over-quota external call');
    opsExec($pdo,'UPDATE ops_limits SET hits=0 WHERE bucket=?',[$monthly]);opsExec($pdo,'UPDATE ops_limits SET hits=10 WHERE bucket=?',[$daily]);
    searchReject(fn()=>webSearchAnswer($pdo,$uid,$cfg,'Test',$request,$generate),'Daily quota bypassed');searchCheck($calls===$before,'Over-quota external call');
    echo "PASS daily and calendar-month search quotas before external calls\n";
}finally{opsExec($pdo,'DELETE FROM ops_limits WHERE bucket IN (?,?)',[$daily,$monthly]);}
