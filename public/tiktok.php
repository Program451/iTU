<?php
/** TikTok Login Kit for Web. All credential operations are owner-only at the API boundary. */
class TikTokError extends RuntimeException {
    public bool $invalid;
    public function __construct(string $message, bool $invalid = false) { parent::__construct($message); $this->invalid=$invalid; }
}

function tiktokSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_tiktok_apps (
        user_id INT PRIMARY KEY, client_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        secret_box TEXT NOT NULL, revision CHAR(32) NOT NULL,
        state_hash CHAR(64) NULL, state_until DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_tiktok_connections (
        user_id INT PRIMARY KEY, client_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        open_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_name VARCHAR(180) NOT NULL, token_box TEXT NOT NULL, refresh_box TEXT NOT NULL,
        scopes VARCHAR(1000) NOT NULL, expires_at DATETIME NOT NULL, refresh_expires_at DATETIME NOT NULL,
        check_status VARCHAR(30) NOT NULL, checked_at DATETIME NOT NULL,
        UNIQUE KEY account(client_key,open_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function tiktokLock(PDO $pdo,int $uid,callable $fn) {
    $key='aulieata.tiktok.'.$uid;
    $q=$pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$key]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('TikTok уже обрабатывает запрос. Повторите через несколько секунд.');
    try { return $fn(); } finally { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$key]); }
}

/** Endpoint allowlist, bounded response, no redirects, no provider messages or credentials in errors. */
function tiktokRequest(string $operation,array $params=[],string $token=''): array {
    $paths=['token'=>'/v2/oauth/token/','revoke'=>'/v2/oauth/revoke/','profile'=>'/v2/user/info/?fields=open_id,display_name'];
    if (!isset($paths[$operation])) throw new RuntimeException('Unsupported TikTok operation');
    $ch=curl_init('https://open.tiktokapis.com'.$paths[$operation]); $raw='';
    $headers=['Accept: application/json','Cache-Control: no-store'];
    if ($operation==='profile') $headers[]='Authorization: Bearer '.$token;
    else $headers[]='Content-Type: application/x-www-form-urlencoded';
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>262144)return 0;$raw.=$chunk;return strlen($chunk);}]);
    if($operation!=='profile')curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params)]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $data=json_decode($raw,true);
    if($operation==='revoke'&&$ok!==false&&$status===200&&trim($raw)==='')return [];
    $error=is_array($data['error']??null)?($data['error']['code']??''):($data['error']??'');
    if($ok===false||$status!==200||!is_array($data)||($error!==''&&$error!=='ok')) {
        $invalid=in_array($error,['invalid_grant','access_token_invalid','invalid_token','access_token_expired','invalid_scope'],true)||$status===401;
        throw new TikTokError($invalid?'Доступ TikTok истёк или отозван. Подключите аккаунт заново.':'TikTok не подтвердил запрос. Проверьте настройки приложения и повторите позже.',$invalid);
    }
    return $data;
}

function tiktokStatus(PDO $pdo,int $uid): array {
    $app=opsOne($pdo,'SELECT client_key FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
    $c=opsOne($pdo,'SELECT display_name,scopes,expires_at,refresh_expires_at,check_status,checked_at FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
    if($c&&$c['refresh_expires_at']<=gmdate('Y-m-d H:i:s'))$c['check_status']='invalid';
    return ['configured'=>(bool)$app,'client_key'=>$app['client_key']??'','redirect_uri'=>appUrl('tiktok-callback.php'),
        'connection'=>$c?:null,'publishing_authorized'=>$c&&in_array('video.publish',explode(',',$c['scopes']),true),'requested_scopes'=>['user.info.basic','video.publish']];
}

function tiktokSaveApp(PDO $pdo,int $uid,array $cfg,string $key,string $secret): array {
    $key=trim($key);$secret=trim($secret);
    if(!preg_match('/^[A-Za-z0-9_-]{5,128}$/D',$key)||!preg_match('/^[A-Za-z0-9_.-]{10,512}$/D',$secret))
        throw new RuntimeException('Вставьте Client key и полный Client secret из TikTok for Developers.');
    return tiktokLock($pdo,$uid,function()use($pdo,$uid,$cfg,$key,$secret){
        if(opsOne($pdo,'SELECT user_id FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]))
            throw new RuntimeException('Сначала отключите TikTok, затем замените приложение.');
        opsTx($pdo,$uid,function()use($pdo,$uid,$cfg,$key,$secret){
            opsExec($pdo,'INSERT INTO aulieata_tiktok_apps(user_id,client_key,secret_box,revision) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE client_key=VALUES(client_key),secret_box=VALUES(secret_box),revision=VALUES(revision),state_hash=NULL,state_until=NULL',
                [$uid,$key,sealToken($secret,$cfg),bin2hex(random_bytes(16))]);
        });
        return tiktokStatus($pdo,$uid);
    });
}

function tiktokBegin(PDO $pdo,int $uid,array &$session,bool $publishing=false): string {
    if((int)($session['user_id']??0)!==$uid||!empty($session['staff_id']))throw new RuntimeException('Подключение доступно владельцу.');
    return tiktokLock($pdo,$uid,function()use($pdo,$uid,&$session,$publishing){
        $app=opsOne($pdo,'SELECT client_key,revision FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
        if(!$app)throw new RuntimeException('Сначала сохраните данные приложения TikTok.');
        $uri=appUrl('tiktok-callback.php');
        if(!str_starts_with($uri,'https://'))throw new RuntimeException('TikTok требует публичный адрес HTTPS.');
        $state=bin2hex(random_bytes(32));$until=time()+600;
        $session['tiktok_oauth']=['state'=>$state,'uid'=>$uid,'revision'=>$app['revision'],'until'=>$until];
        opsExec($pdo,'UPDATE aulieata_tiktok_apps SET state_hash=?,state_until=? WHERE user_id=?',
            [hash('sha256',$state),gmdate('Y-m-d H:i:s',$until),$uid]);
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query(['client_key'=>$app['client_key'],
            'response_type'=>'code','scope'=>$publishing?'user.info.basic,video.publish':'user.info.basic','redirect_uri'=>$uri,'state'=>$state,'disable_auto_auth'=>1]);
    });
}

function tiktokTokens(array $data): array {
    // TikTok tokens are opaque strings. Do not impose an identifier/base64url alphabet.
    // Bound their size and reject whitespace, controls and non-ASCII bytes before header use.
    foreach(['access_token','refresh_token']as$key){
        if(!is_string($data[$key]??null)||$data[$key]==='')
            throw new TikTokError($key==='access_token'?'TikTok не вернул токен доступа. Начните подключение заново.':'TikTok не вернул токен обновления. Начните подключение заново.');
        if(!preg_match('/^[\x21-\x7e]{1,4096}$/D',$data[$key]))
            throw new TikTokError('TikTok вернул неподдерживаемый формат доступа. Сообщите владельцу сайта.');
    }
    if(!is_string($data['open_id']??null)||!preg_match('/^[A-Za-z0-9_.~-]{3,128}$/D',$data['open_id'])||strtolower((string)($data['token_type']??''))!=='bearer')throw new TikTokError('Не удалось подтвердить аккаунт TikTok.');
    $scopes=array_values(array_filter(array_map('trim',explode(',',(string)($data['scope']??'')))));
    if(!in_array('user.info.basic',$scopes,true))throw new TikTokError('Разрешите доступ к основным данным профиля TikTok.',true);
    if(strlen(implode(',',$scopes))>1000)throw new TikTokError('Некорректный ответ TikTok.');
    foreach(['expires_in','refresh_expires_in']as$key)if(!is_int($data[$key]??null)||$data[$key]<1||$data[$key]>366*86400)throw new TikTokError('TikTok не указал срок действия доступа.');
    $data['scope']=implode(',',$scopes);return $data;
}

function tiktokProfile(array $response,string $openId): string {
    $user=$response['data']['user']??[];
    if(($user['open_id']??'')!==$openId||!is_string($user['display_name']??null)||trim($user['display_name'])==='')
        throw new TikTokError('Не удалось подтвердить профиль TikTok.',true);
    return mb_substr(trim($user['display_name']),0,180);
}

/** Consumes session + database state before exchanging a code; GET callbacks alone cannot connect accounts. */
function tiktokFinish(PDO $pdo,int $uid,array $cfg,array &$session,array $query,?callable $request=null): array {
    $pending=$session['tiktok_oauth']??null;unset($session['tiktok_oauth']);
    $state=$query['state']??null;
    if((int)($session['user_id']??0)!==$uid||!empty($session['staff_id'])||!is_array($pending)
        ||($pending['uid']??0)!==$uid||($pending['until']??0)<time()||!is_string($state)
        ||!hash_equals((string)($pending['state']??''),$state))throw new RuntimeException('Ссылка подключения истекла. Начните подключение TikTok на сайте заново.');
    $request??='tiktokRequest';
    return tiktokLock($pdo,$uid,function()use($pdo,$uid,$cfg,$pending,$query,$state,$request){
        $app=opsOne($pdo,'SELECT * FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
        if(!$app||$app['revision']!==$pending['revision']||!hash_equals((string)$app['state_hash'],hash('sha256',$state))||$app['state_until']<gmdate('Y-m-d H:i:s'))
            throw new RuntimeException('Настройки подключения изменились. Начните подключение заново.');
        opsExec($pdo,'UPDATE aulieata_tiktok_apps SET state_hash=NULL,state_until=NULL WHERE user_id=?',[$uid]);
        if(isset($query['error']))throw new RuntimeException('Подключение не разрешено в TikTok. Вы можете повторить его позже.');
        $code=$query['code']??null;
        if(!is_string($code)||strlen($code)<4||strlen($code)>4096||preg_match('/[\x00-\x20\x7f]/',$code))throw new RuntimeException('TikTok не передал код подключения. Начните заново.');
        $data=tiktokTokens($request('token',['client_key'=>$app['client_key'],'client_secret'=>openToken($app['secret_box'],$cfg),
            'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>appUrl('tiktok-callback.php')]));
        $name=tiktokProfile($request('profile',[],$data['access_token']),$data['open_id']);
        $old=opsOne($pdo,'SELECT open_id,client_key FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
        if($old&&($old['open_id']!==$data['open_id']||$old['client_key']!==$app['client_key']))throw new RuntimeException('Сначала отключите прежний аккаунт TikTok, затем подключите другой.');
        try {
            opsTx($pdo,$uid,function()use($pdo,$uid,$app,$data,$name,$cfg,$old){
                $values=[$app['client_key'],$data['open_id'],$name,sealToken($data['access_token'],$cfg),sealToken($data['refresh_token'],$cfg),$data['scope'],
                    gmdate('Y-m-d H:i:s',time()+$data['expires_in']),gmdate('Y-m-d H:i:s',time()+$data['refresh_expires_in']),gmdate('Y-m-d H:i:s'),$uid];
                if($old)opsExec($pdo,"UPDATE aulieata_tiktok_connections SET client_key=?,open_id=?,display_name=?,token_box=?,refresh_box=?,scopes=?,expires_at=?,refresh_expires_at=?,check_status='verified',checked_at=? WHERE user_id=?",$values);
                else opsExec($pdo,"INSERT INTO aulieata_tiktok_connections(client_key,open_id,display_name,token_box,refresh_box,scopes,expires_at,refresh_expires_at,checked_at,user_id,check_status) VALUES(?,?,?,?,?,?,?,?,?,?,'verified')",$values);
            });
        } catch(PDOException $e) { if($e->getCode()==='23000')throw new RuntimeException('Этот TikTok уже подключён к другому рабочему пространству.');throw $e; }
        return tiktokStatus($pdo,$uid);
    });
}

function tiktokCheck(PDO $pdo,int $uid,array $cfg,?callable $request=null): array {
    $request??='tiktokRequest';
    return tiktokLock($pdo,$uid,function()use($pdo,$uid,$cfg,$request){
        $c=opsOne($pdo,'SELECT * FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
        $app=opsOne($pdo,'SELECT * FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
        if(!$c||!$app)throw new RuntimeException('Сначала подключите аккаунт TikTok.');
        try {
            $token=openToken($c['token_box'],$cfg);
            if($c['expires_at']<=gmdate('Y-m-d H:i:s',time()+300)) {
                if($c['refresh_expires_at']<=gmdate('Y-m-d H:i:s'))throw new TikTokError('Доступ TikTok истёк. Подключите аккаунт заново.',true);
                $data=tiktokTokens($request('token',['client_key'=>$app['client_key'],'client_secret'=>openToken($app['secret_box'],$cfg),
                    'grant_type'=>'refresh_token','refresh_token'=>openToken($c['refresh_box'],$cfg)]));
                if($data['open_id']!==$c['open_id'])throw new TikTokError('TikTok вернул другой аккаунт. Подключите его заново.',true);
                $token=$data['access_token'];
                // Persist rotating refresh tokens immediately, even if the following profile request fails.
                opsExec($pdo,'UPDATE aulieata_tiktok_connections SET token_box=?,refresh_box=?,scopes=?,expires_at=?,refresh_expires_at=? WHERE user_id=?',
                    [sealToken($token,$cfg),sealToken($data['refresh_token'],$cfg),$data['scope'],gmdate('Y-m-d H:i:s',time()+$data['expires_in']),gmdate('Y-m-d H:i:s',time()+$data['refresh_expires_in']),$uid]);
            }
            $name=tiktokProfile($request('profile',[],$token),$c['open_id']);
            opsExec($pdo,"UPDATE aulieata_tiktok_connections SET display_name=?,check_status='verified',checked_at=? WHERE user_id=?",[$name,gmdate('Y-m-d H:i:s'),$uid]);
        } catch(TikTokError $e) {
            opsExec($pdo,'UPDATE aulieata_tiktok_connections SET check_status=?,checked_at=? WHERE user_id=?',[$e->invalid?'invalid':'unavailable',gmdate('Y-m-d H:i:s'),$uid]);throw $e;
        }
        return tiktokStatus($pdo,$uid);
    });
}

function tiktokDisconnect(PDO $pdo,int $uid,array $cfg,bool $removeApp=false,?callable $request=null): array {
    $request??='tiktokRequest';
    return tiktokLock($pdo,$uid,function()use($pdo,$uid,$cfg,$removeApp,$request){
        $c=opsOne($pdo,'SELECT token_box FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
        $app=opsOne($pdo,'SELECT * FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);$revoked=true;
        if($c&&$app)try{$request('revoke',['client_key'=>$app['client_key'],'client_secret'=>openToken($app['secret_box'],$cfg),'token'=>openToken($c['token_box'],$cfg)]);}
        catch(TikTokError $e){$revoked=false;}
        opsTx($pdo,$uid,function()use($pdo,$uid,$removeApp){
            opsExec($pdo,'DELETE FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
            if($removeApp)opsExec($pdo,'DELETE FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
            else opsExec($pdo,'UPDATE aulieata_tiktok_apps SET state_hash=NULL,state_until=NULL WHERE user_id=?',[$uid]);
        });
        return ['tiktok'=>tiktokStatus($pdo,$uid),'revoked'=>$revoked];
    });
}
