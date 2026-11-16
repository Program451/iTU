<?php
/** Refresh existing Instagram Login grants; never requests broader permissions. */
function instagramRefreshSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_instagram_token_refresh (
        user_id INT PRIMARY KEY, token_box_hash CHAR(64) NOT NULL,
        next_attempt_at DATETIME NOT NULL, expires_at DATETIME NULL,
        last_error VARCHAR(240) NOT NULL DEFAULT '',
        FOREIGN KEY (user_id) REFERENCES aulieata_instagram_connections(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function instagramRefreshSchedule(PDO $pdo,int $uid,string $box): void {
    // Meta accepts only unexpired long-lived tokens that are at least 24 hours old.
    opsExec($pdo,"INSERT INTO aulieata_instagram_token_refresh(user_id,token_box_hash,next_attempt_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY)) ON DUPLICATE KEY UPDATE token_box_hash=VALUES(token_box_hash),next_attempt_at=VALUES(next_attempt_at),expires_at=NULL,last_error=''",[$uid,hash('sha256',$box)]);
}
function instagramRefreshRequest(string $token): array {
    $ch=curl_init('https://graph.instagram.com/refresh_access_token?'.http_build_query(['grant_type'=>'ig_refresh_token','access_token'=>$token]));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);
    if($status!==200||!is_array($data)||isset($data['error']))throw new InstagramConnectionError('Instagram не обновил доступ. Проверьте подключение в настройках.',(int)($data['error']['code']??0)===190);
    return $data;
}
function instagramRefreshDue(PDO $pdo,array $cfg,?callable $request=null,?int $now=null): int {
    $now??=time();$nowSql=gmdate('Y-m-d H:i:s',$now);$count=0;
    // Existing installations get a conservative first attempt, without assuming expiry.
    opsExec($pdo,"INSERT IGNORE INTO aulieata_instagram_token_refresh(user_id,token_box_hash,next_attempt_at) SELECT user_id,SHA2(token_box,256),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY) FROM aulieata_instagram_connections");
    $q=$pdo->prepare("SELECT c.user_id,c.token_box,m.token_box_hash FROM aulieata_instagram_connections c JOIN aulieata_instagram_token_refresh m ON m.user_id=c.user_id WHERE c.check_status<>'invalid' AND m.next_attempt_at<=? ORDER BY m.next_attempt_at LIMIT 3");$q->execute([$nowSql]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $uid=(int)$row['user_id'];$old=$row['token_box'];$hash=$row['token_box_hash'];if(!hash_equals(hash('sha256',$old),$hash))continue;
        // Claim the attempt before network I/O; retries are bounded even after a crash.
        $claim=$pdo->prepare('UPDATE aulieata_instagram_token_refresh SET next_attempt_at=? WHERE user_id=? AND token_box_hash=? AND next_attempt_at<=?');
        $claim->execute([gmdate('Y-m-d H:i:s',$now+21600),$uid,$hash,$nowSql]);if(!$claim->rowCount())continue;
        try{
            $data=($request??'instagramRefreshRequest')(openToken($old,$cfg));$token=(string)($data['access_token']??'');$seconds=$data['expires_in']??0;
            if(!preg_match('/^[A-Za-z0-9_.-]{30,4096}$/D',$token)||!is_int($seconds)||$seconds<86400||$seconds>7776000)throw new InstagramConnectionError('Instagram вернул неподходящий срок доступа. Проверьте подключение.');
            $box=sealToken($token,$cfg);$due=$now+min(2592000,max(86400,$seconds-604800));
            // A disconnect or manual replacement during the request must win.
            $update=$pdo->prepare("UPDATE aulieata_instagram_connections c JOIN aulieata_instagram_token_refresh m ON m.user_id=c.user_id SET c.token_box=?,m.token_box_hash=?,m.next_attempt_at=?,m.expires_at=?,m.last_error='' WHERE c.user_id=? AND c.token_box=? AND m.token_box_hash=?");
            $update->execute([$box,hash('sha256',$box),gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$now+$seconds),$uid,$old,$hash]);if($update->rowCount())$count++;
        }catch(Throwable $e){
            $invalid=$e instanceof InstagramConnectionError&&$e->invalidToken;
            $update=$pdo->prepare("UPDATE aulieata_instagram_connections c JOIN aulieata_instagram_token_refresh m ON m.user_id=c.user_id SET m.last_error=?,c.check_status=IF(?, 'invalid',c.check_status) WHERE c.user_id=? AND c.token_box=? AND m.token_box_hash=?");
            $update->execute([$invalid?'Доступ отозван или истёк. Подключите Instagram заново.':'Продление временно недоступно. Повторная попытка через 6 часов.',$invalid?1:0,$uid,$old,$hash]);
        }
    }
    return $count;
}
