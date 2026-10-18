<?php
/** Pure regression checks: no configuration, database, credentials or provider calls. */
require dirname(__DIR__).'/public/tiktok.php';
function tokenAssert(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label); }
function tokenReject(array $payload,string $label): void {
    try { tiktokTokens($payload); }
    catch(TikTokError $e) {
        tokenAssert(!str_contains($e->getMessage(),'PRIVATE'),'Provider value leaked into error');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
$payload=['access_token'=>'act.example-access-token','refresh_token'=>'rft.example-refresh-token',
    'expires_in'=>86400,'refresh_expires_in'=>31536000,'token_type'=>'Bearer',
    'open_id'=>'test-open-id','scope'=>'user.info.basic'];
foreach(['access_token','refresh_token']as$field){
    // Tokens are opaque provider strings, not a base64url or application-defined identifier.
    foreach(['act.1!opaque/value+with=padding','rft.2!symbols:$@[](),;','x',str_repeat('X',4096)]as$value){
        $candidate=$payload;$candidate[$field]=$value;
        tokenAssert(tiktokTokens($candidate)[$field]===$value,'Opaque token changed: '.$field);
    }
    foreach([null,[],false,123,'',str_repeat('X',4097),'PRIVATE token',"PRIVATE\r\nInjected: value","PRIVATE\0token","PRIVATE\ttoken","PRIVATE\x7ftoken",'PRIVATE-токен']as$value){
        $candidate=$payload;$candidate[$field]=$value;tokenReject($candidate,$field);
    }
    $candidate=$payload;unset($candidate[$field]);tokenReject($candidate,'Missing '.$field);
}
foreach(['open_id'=>'another user','token_type'=>'Basic','scope'=>'video.list','expires_in'=>0,'refresh_expires_in'=>367*86400]as$field=>$value){
    $candidate=$payload;$candidate[$field]=$value;tokenReject($candidate,$field);
}
tokenAssert(tiktokTokens($payload)===$payload,'Valid response changed');
echo "PASS opaque access/refresh tokens preserved; missing, invalid, oversized and control-byte values rejected; identity, scope and expiry checks retained\n";
