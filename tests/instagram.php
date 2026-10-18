<?php
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
$cfg = appConfig();
if (!str_starts_with($cfg['db_name']??'', 'itu_test_') || !in_array($cfg['db_host']??'', ['127.0.0.1','localhost'], true)) exit(2);
$pdo = appDatabase(); appSchema($pdo); $_SESSION = [];
function igAssert($value, $label) { if (!$value) throw new RuntimeException($label); }
function igReject(callable $fn, $label) {
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
$ids = [];
$token = str_repeat('T', 64); $newToken = str_repeat('R', 64);
$profile = ['id'=>'1234567','user_id'=>'17841400000001','username'=>'local_test','account_type'=>'BUSINESS'];
$mock = function($token,$path,$params) use ($profile) {
    return $path==='/me' ? $profile : ['data'=>[['quota_usage'=>0,'config'=>['quota_total'=>100,'quota_duration'=>86400]]]];
};
try {
    for ($i=0;$i<2;$i++) {
        opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',
            ['ig-test-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT),'Instagram test','cafe','Test']);
        $ids[] = (int)$pdo->lastInsertId();
    }
    [$uid,$other] = $ids;
    $status = instagramConnect($pdo,$uid,$cfg,$token,$mock);
    igAssert($status['account_id']===$profile['user_id'] && $status['check_status']==='verified', 'Verified account');
    igAssert(!isset($status['token_box']) && !str_contains(json_encode($status),$token), 'Token exposed in response');
    $stored = opsOne($pdo,'SELECT token_box FROM aulieata_instagram_connections WHERE user_id=?',[$uid])['token_box'];
    igAssert($stored!==$token && openToken($stored,$cfg)===$token, 'Encryption roundtrip');
    igAssert(instagramStatus($pdo,$other)===null,'Tenant read isolation');
    echo "PASS encrypted storage, masked response, account identity and tenant isolation\n";

    igReject(fn()=>instagramConnect($pdo,$other,$cfg,$token,$mock),'duplicate account');
    igAssert(instagramStatus($pdo,$other)===null && instagramStatus($pdo,$uid)['username']==='local_test','Cross-tenant transfer');
    instagramDisconnect($pdo,$other);
    igAssert(instagramStatus($pdo,$uid)!==null,'Cross-tenant disconnect');
    echo "PASS account ownership and disconnect isolation\n";

    $failed = function(){throw new InstagramConnectionError('Invalid token',true);};
    igReject(fn()=>instagramConnect($pdo,$uid,$cfg,$newToken,$failed),'invalid replacement');
    igAssert(opsOne($pdo,'SELECT token_box FROM aulieata_instagram_connections WHERE user_id=?',[$uid])['token_box']===$stored,'Invalid replacement overwrote good token');
    igReject(fn()=>instagramCheck($pdo,$uid,$cfg,$failed),'expired token');
    igAssert(instagramStatus($pdo,$uid)['check_status']==='invalid' && instagramStatus($pdo,$uid)['quota_total']===null,'Expired token status');
    instagramConnect($pdo,$uid,$cfg,$newToken,$mock);
    igAssert(instagramStatus($pdo,$uid)['check_status']==='verified','Reconnect status');
    echo "PASS invalid replacement preserves token, expired token is marked and reconnect recovers\n";

    $noQuota = function($t,$path,$p)use($profile){if($path!=='/me')throw new InstagramConnectionError('Quota unavailable');return $profile;};
    instagramCheck($pdo,$uid,$cfg,$noQuota);
    igAssert(instagramStatus($pdo,$uid)['quota_total']===null,'Invented quota');
    echo "PASS optional quota failure does not imply publication capability\n";

    igReject(fn()=>instagramInspectToken("bad\r\nheader",$mock),'header injection');
    $personal = fn()=>array_replace($profile,['account_type'=>'PERSONAL']);
    igReject(fn()=>instagramInspectToken($token,$personal),'personal account');
    $changed = fn()=>array_replace($profile,['user_id'=>'17841400000002']);
    igReject(fn()=>instagramConnect($pdo,$uid,$cfg,$token,$changed),'implicit account switch');
    echo "PASS malformed token, personal profile and accidental account switch blocked\n";

    foreach(['operator','manager'] as $role){
        opsExec($pdo,'INSERT INTO ops_staff(user_id,name,role,active) VALUES(?,?,?,1)',[$uid,'Test',$role]);
        $_SESSION=['staff_id'=>(int)$pdo->lastInsertId()];
        foreach(['instagram_connect','instagram_check','instagram_disconnect'] as $action) igReject(fn()=>opsStaffGate($pdo,$uid,$action,[]),'staff '.$action);
    }
    $_SESSION=[];
    echo "PASS operator and manager cannot manage Instagram credentials\n";

    $racingDisconnect = function($t,$path,$params)use($pdo,$uid,$mock){
        if($path==='/me')instagramDisconnect($pdo,$uid);return $mock($t,$path,$params);
    };
    igReject(fn()=>instagramCheck($pdo,$uid,$cfg,$racingDisconnect),'disconnect while checking');
    igAssert(instagramStatus($pdo,$uid)===null,'Check restored disconnected credential');
    instagramConnect($pdo,$uid,$cfg,$token,$mock);
    $racingReplace = function($t,$path,$params)use($pdo,$uid,$cfg,$mock,$newToken){
        if($path==='/me')instagramConnect($pdo,$uid,$cfg,$newToken,$mock);return $mock($t,$path,$params);
    };
    igReject(fn()=>instagramCheck($pdo,$uid,$cfg,$racingReplace),'replacement while checking');
    $box=opsOne($pdo,'SELECT token_box FROM aulieata_instagram_connections WHERE user_id=?',[$uid])['token_box'];
    igAssert(openToken($box,$cfg)===$newToken,'Check overwrote replaced credential');
    instagramDisconnect($pdo,$uid);
    igAssert(instagramStatus($pdo,$uid)===null,'Disconnect left token');
    echo "PASS in-flight checks cannot restore disconnected or replaced credentials\n";
    echo "RESULT Instagram connection checks passed; no external publication or AI requests\n";
} finally {
    $_SESSION=[];
    foreach($ids as $id){
        instagramDisconnect($pdo,$id);
        opsExec($pdo,'DELETE FROM ops_staff WHERE user_id=?',[$id]);
        opsExec($pdo,'DELETE FROM users WHERE id=?',[$id]);
    }
}
