<?php
/** Official Direct Post for original photo posts. Each delivery keeps its approved snapshot. */
class TikTokPublishError extends RuntimeException {
    public bool $uncertain;
    public function __construct(string $message,bool $uncertain=false){parent::__construct($message);$this->uncertain=$uncertain;}
}
function tiktokPublishSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_publications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,post_id BIGINT UNSIGNED NOT NULL,
        post_version INT NOT NULL,account_key CHAR(64) NOT NULL,account_name VARCHAR(180) NOT NULL,creator_username VARCHAR(128) NOT NULL,
        status VARCHAR(24) NOT NULL,snapshot TEXT NOT NULL,image_key CHAR(48) NOT NULL,image_hash CHAR(64) NOT NULL,
        approved_at DATETIME NOT NULL,due_at DATETIME NOT NULL,next_check DATETIME NULL,
        publish_id VARCHAR(128) NULL,remote_id VARCHAR(40) NULL,public_key CHAR(64) NULL,public_until DATETIME NULL,
        last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
        UNIQUE KEY one_delivery(post_id),INDEX owner(user_id,id),INDEX due(status,next_check,due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function tiktokPublishMessage(string $code): string {
    return [
        'scope_not_authorized'=>'Разрешите публикацию в TikTok: подключите аккаунт заново с доступом video.publish.',
        'access_token_invalid'=>'Доступ TikTok истёк. Подключите аккаунт заново.',
        'url_ownership_unverified'=>'Подтвердите домен сайта в TikTok for Developers → URL properties.',
        'unaudited_client_can_only_post_to_private_accounts'=>'TikTok ещё не одобрил публичные публикации приложения. Для теста нужны закрытый аккаунт и видимость «Только я».',
        'privacy_level_option_mismatch'=>'Настройки видимости TikTok изменились. Выберите доступную видимость заново.',
        'spam_risk_too_many_posts'=>'Дневной лимит публикаций TikTok исчерпан. Выберите другое время.',
        'spam_risk_user_banned_from_posting'=>'TikTok ограничил публикации этого аккаунта.',
        'reached_active_user_cap'=>'Достигнут лимит активных авторов приложения TikTok.',
        'rate_limit_exceeded'=>'TikTok временно ограничил запросы. Попробуйте позже.',
        'photo_pull_failed'=>'TikTok не смог скачать изображение. Проверьте доступность сайта и подтверждение домена.',
        'picture_size_check_failed'=>'TikTok отклонил размер изображения.',
        'file_format_check_failed'=>'TikTok отклонил формат изображения.',
        'spam_risk_text'=>'TikTok отклонил текст публикации. Проверьте содержание.',
        'spam_risk'=>'TikTok отклонил публикацию. Проверьте ограничения аккаунта.',
        'auth_removed'=>'Доступ приложения к TikTok отозван.',
    ][$code]??'TikTok не подтвердил запрос. Проверьте настройки приложения и состояние аккаунта.';
}
function tiktokPublishRequest(string $operation,array $params,string $token): array {
    $paths=['creator'=>'creator_info/query/','init'=>'content/init/','status'=>'status/fetch/'];
    if(!isset($paths[$operation]))throw new RuntimeException('Unsupported TikTok publishing operation');
    $ch=curl_init('https://open.tiktokapis.com/v2/post/publish/'.$paths[$operation]);$raw='';
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params?:new stdClass(),JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json; charset=UTF-8'],
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>262144)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode($raw,true);
    $error=$data['error']['code']??null;
    if($ok===false||$status!==200||$error!=='ok'||!is_array($data['data']??null))
        throw new TikTokPublishError(tiktokPublishMessage(is_string($error)?$error:''),$ok===false||$status>=500||!is_string($error)||$error==='ok');
    return $data['data'];
}
function tiktokAccountKey(array $c): string {return hash('sha256',$c['client_key'].':'.$c['open_id']);}
/** Caller holds the account lock; rotating credentials are saved before the next request. */
function tiktokPostingAccess(PDO $pdo,int $uid,array $cfg,?callable $auth=null): array {
    $auth??='tiktokRequest';$c=opsOne($pdo,'SELECT * FROM aulieata_tiktok_connections WHERE user_id=?',[$uid]);
    $app=opsOne($pdo,'SELECT * FROM aulieata_tiktok_apps WHERE user_id=?',[$uid]);
    if(!$c||!$app||$c['client_key']!==$app['client_key'])throw new TikTokPublishError('Сначала подключите TikTok в настройках.');
    if($c['expires_at']<=socialLater(300)){
        if($c['refresh_expires_at']<=socialNow())throw new TikTokPublishError('Доступ TikTok истёк. Подключите аккаунт заново.');
        $data=tiktokTokens($auth('token',['client_key'=>$app['client_key'],'client_secret'=>openToken($app['secret_box'],$cfg),'grant_type'=>'refresh_token','refresh_token'=>openToken($c['refresh_box'],$cfg)]));
        if($data['open_id']!==$c['open_id'])throw new TikTokPublishError('Подключённый аккаунт TikTok изменился.');
        $c['token_box']=sealToken($data['access_token'],$cfg);$c['refresh_box']=sealToken($data['refresh_token'],$cfg);$c['scopes']=$data['scope'];
        $c['expires_at']=socialLater($data['expires_in']);$c['refresh_expires_at']=socialLater($data['refresh_expires_in']);
        opsExec($pdo,'UPDATE aulieata_tiktok_connections SET token_box=?,refresh_box=?,scopes=?,expires_at=?,refresh_expires_at=? WHERE user_id=?',[$c['token_box'],$c['refresh_box'],$c['scopes'],$c['expires_at'],$c['refresh_expires_at'],$uid]);
    }
    if(!in_array('video.publish',explode(',',$c['scopes']),true))throw new TikTokPublishError(tiktokPublishMessage('scope_not_authorized'));
    return [$c,openToken($c['token_box'],$cfg)];
}
function tiktokCreatorSafe(array $data,array $c): array {
    $allowed=['PUBLIC_TO_EVERYONE','MUTUAL_FOLLOW_FRIENDS','FOLLOWER_OF_CREATOR','SELF_ONLY'];
    $options=$data['privacy_level_options']??null;
    if(!is_array($options)||!$options||array_diff($options,$allowed)||!is_string($data['creator_nickname']??null)||!is_bool($data['comment_disabled']??null))throw new TikTokPublishError('TikTok не вернул настройки публикации. Повторите проверку позже.');
    return ['account_key'=>tiktokAccountKey($c),'nickname'=>mb_substr($data['creator_nickname'],0,180),'username'=>mb_substr((string)($data['creator_username']??''),0,128),'privacy_options'=>array_values($options),'comment_disabled'=>$data['comment_disabled']];
}
function tiktokPostingCreator(PDO $pdo,int $uid,array $cfg,?callable $request=null,?callable $auth=null): array {
    $request??='tiktokPublishRequest';return tiktokLock($pdo,$uid,function()use($pdo,$uid,$cfg,$request,$auth){[$c,$token]=tiktokPostingAccess($pdo,$uid,$cfg,$auth);return tiktokCreatorSafe($request('creator',[],$token),$c);});
}
function tiktokPublicationSafe(?array $r): ?array {
    if(!$r)return null;$result=array_intersect_key($r,array_flip(['id','post_id','post_version','status','account_name','due_at','last_error','remote_id','updated_at']));
    foreach(['id','post_id','post_version'] as $key)$result[$key]=(int)$result[$key];
    $snapshot=json_decode($r['snapshot'],true)?:[];$result['privacy_level']=$snapshot['privacy_level']??'';
    $result['permalink']=preg_match('/^[A-Za-z0-9._]{1,128}$/D',$r['creator_username'])&&preg_match('/^[0-9]{5,40}$/D',(string)$r['remote_id'])?'https://www.tiktok.com/@'.$r['creator_username'].'/photo/'.$r['remote_id']:null;
    return $result;
}
function tiktokPostDelivery(PDO $pdo,int $uid,int $post): ?array {return tiktokPublicationSafe(opsOne($pdo,'SELECT * FROM tiktok_publications WHERE user_id=? AND post_id=?',[$uid,$post]));}
function tiktokPhotoPath(string $key): string {
    if(!preg_match('/^[a-f0-9]{48}$/D',$key))throw new RuntimeException('Invalid media key');return dirname(__DIR__).'/aulieata-media/tiktok/'.$key.'.jpg';
}
function publicationDue(string $local): string {
    if($local==='')return socialNow();$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new DateTimeZone('Asia/Qyzylorda'));
    if(!$d||$d->format('Y-m-d\TH:i')!==$local||$d->getTimestamp()<time()+60||$d->getTimestamp()>time()+30*86400)throw new RuntimeException('Выберите время от одной минуты до 30 дней вперёд, UTC+5.');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function tiktokPostOptions(array $in,array $creator,array $post): array {
    if(($in['consent']??false)!==true)throw new RuntimeException('Подтвердите предпросмотр и условия публикации TikTok.');
    if(($in['account_key']??'')!==$creator['account_key'])throw new RuntimeException('Подключённый аккаунт TikTok изменился.');
    if(!in_array($in['privacy_level']??'', $creator['privacy_options'],true))throw new RuntimeException('Выберите доступную видимость TikTok.');
    $comments=($in['allow_comment']??false)===true;if($creator['comment_disabled']&&$comments)throw new RuntimeException('Комментарии отключены в настройках TikTok.');
    $commercial=($in['commercial']??false)===true;$own=($in['brand_organic']??false)===true;$branded=($in['brand_content']??false)===true;
    if(($commercial&&!$own&&!$branded)||(!$commercial&&($own||$branded)))throw new RuntimeException('Укажите тип рекламного контента.');
    if($branded&&$in['privacy_level']==='SELF_ONLY')throw new RuntimeException('Партнёрская реклама не может иметь видимость «Только я».');
    $title=trim((string)($in['title']??$post['title']));$description=trim((string)($in['description']??$post['caption']));
    if($title===''||strlen(mb_convert_encoding($title,'UTF-16LE','UTF-8'))>180||$description===''||strlen(mb_convert_encoding($description,'UTF-16LE','UTF-8'))>8000)throw new RuntimeException('TikTok: заголовок до 90, описание до 4000 символов.');
    return ['title'=>$title,'description'=>$description,'privacy_level'=>$in['privacy_level'],'disable_comment'=>!$comments,'auto_add_music'=>($in['auto_add_music']??false)===true,'brand_content_toggle'=>$branded,'brand_organic_toggle'=>$own,'is_aigc'=>($in['is_aigc']??false)===true];
}
/** Both queues commit together. A failure in one channel never silently queues the other. */
function publicationQueue(PDO $pdo,int $uid,array $in,array $cfg,?callable $request=null): array {
    $channels=$in['channels']??[];if(!is_array($channels)||!count($channels)||count($channels)>2||count(array_unique($channels))!==count($channels)||array_diff($channels,['instagram','tiktok']))throw new RuntimeException('Выберите Instagram, TikTok или обе сети.');
    if(($in['approved']??false)!==true)throw new RuntimeException('Подтвердите изображение, текст и аккаунты.');
    $id=(int)($in['id']??0);$version=(int)($in['version']??0);$hasTikTok=in_array('tiktok',$channels,true);
    socialRow($pdo,$uid,$id);$creator=$hasTikTok?tiktokPostingCreator($pdo,$uid,$cfg,$request):null;$file=null;
    try{return opsTx($pdo,$uid,function()use($pdo,$uid,$in,$id,$version,$channels,$hasTikTok,$creator,&$file){
        $post=socialRow($pdo,$uid,$id,true);if((int)$post['version']!==$version||$post['status']==='archived')throw new RuntimeException('Пост изменился. Откройте актуальный предпросмотр.');
        $old=$hasTikTok?opsOne($pdo,'SELECT * FROM tiktok_publications WHERE user_id=? AND post_id=? FOR UPDATE',[$uid,$id]):null;
        $already=$old&&in_array($old['status'],['queued','sending','processing','published','uncertain'],true);
        if($already&&(int)$old['post_version']!==$version)throw new RuntimeException('Этот пост уже отправляется или опубликован в TikTok. Создайте новый пост для другой версии.');
        $due=publicationDue(trim((string)($in['scheduled_at']??'')));
        $options=null;if($hasTikTok&&!$already){
            $c=opsOne($pdo,'SELECT * FROM aulieata_tiktok_connections WHERE user_id=? FOR UPDATE',[$uid]);if(!$c||tiktokAccountKey($c)!==$creator['account_key'])throw new RuntimeException('Подключённый аккаунт TikTok изменился.');
            $options=tiktokPostOptions($in['tiktok']??[],$creator,$post);
            $src=socialImagePath($post['image_key']);if(!is_file($src)||!hash_equals($post['image_hash'],hash_file('sha256',$src)))throw new RuntimeException('Изображение изменилось. Сохраните пост заново.');
            $key=bin2hex(random_bytes(24));$file=tiktokPhotoPath($key);$dir=dirname($file);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Хранилище изображений недоступно.');if(!copy($src,$file))throw new RuntimeException('Не удалось подготовить изображение.');chmod($file,0600);
        }
        if(in_array('instagram',$channels,true))socialQueue($pdo,$uid,['id'=>$id,'version'=>$version,'approved'=>true,'account_id'=>$in['account_id']??'','scheduled_at'=>$in['scheduled_at']??'']);
        if($options){
            $values=[$version,$creator['account_key'],$creator['nickname'],$creator['username'],opsJson($options),$key,$post['image_hash'],socialNow(),$due,$due,socialNow()];
            if($old)opsExec($pdo,"UPDATE tiktok_publications SET post_version=?,account_key=?,account_name=?,creator_username=?,snapshot=?,image_key=?,image_hash=?,approved_at=?,due_at=?,next_check=?,updated_at=?,status='queued',publish_id=NULL,remote_id=NULL,last_error=NULL,public_key=NULL,public_until=NULL WHERE id=?",[...$values,$old['id']]);
            else opsExec($pdo,"INSERT INTO tiktok_publications(post_version,account_key,account_name,creator_username,snapshot,image_key,image_hash,approved_at,due_at,next_check,updated_at,user_id,post_id,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'queued',?)",[...$values,$uid,$id,socialNow()]);
            opsAudit($pdo,$uid,'tiktok_approved',$id,['version'=>$version,'account'=>$creator['nickname'],'due_at'=>$due]);
        }
        $result=socialSafe(socialRow($pdo,$uid,$id));$result['tiktok']=tiktokPostDelivery($pdo,$uid,$id);return $result;
    });}catch(Throwable $e){if($file&&is_file($file))unlink($file);throw $e;}
}
function tiktokDeliveryLock(PDO $pdo,int $id,callable $fn){$key='aulieata.tiktok.post.'.$id;if(!(int)opsOne($pdo,'SELECT GET_LOCK(?,0) n',[$key])['n'])throw new RuntimeException('TikTok обрабатывает пост. Повторите позже.');try{return $fn();}finally{opsOne($pdo,'SELECT RELEASE_LOCK(?) n',[$key]);}}
function tiktokDeliverySet(PDO $pdo,int $id,string $status,?string $error=null,int $delay=60): void {opsExec($pdo,'UPDATE tiktok_publications SET status=?,last_error=?,next_check=?,updated_at=? WHERE id=?',[$status,$error,socialLater($delay),socialNow(),$id]);}
function tiktokPublishStep(PDO $pdo,int $id,array $cfg,?callable $request=null,?callable $auth=null): void {
    $request??='tiktokPublishRequest';tiktokDeliveryLock($pdo,$id,function()use($pdo,$id,$cfg,$request,$auth){
        $r=opsOne($pdo,'SELECT * FROM tiktok_publications WHERE id=?',[$id]);if(!$r||!in_array($r['status'],['queued','sending','processing','uncertain'],true)||$r['due_at']>socialNow()||$r['next_check']>socialNow())return;
        if(in_array($r['status'],['sending','uncertain'],true)&&!$r['publish_id']){tiktokDeliverySet($pdo,$id,'uncertain','Ответ TikTok не подтверждён. Проверьте профиль; автоматическая повторная отправка заблокирована.',86400);return;}
        $sending=$r['status']!=='queued';
        try{tiktokLock($pdo,(int)$r['user_id'],function()use($pdo,$id,$r,$cfg,$request,$auth,&$sending){
            [$c,$token]=tiktokPostingAccess($pdo,(int)$r['user_id'],$cfg,$auth);if(tiktokAccountKey($c)!==$r['account_key'])throw new TikTokPublishError('Подключённый аккаунт TikTok изменился.');
            if($r['publish_id']){
                $data=$request('status',['publish_id'=>$r['publish_id']],$token);$status=$data['status']??'';
                if($status==='PUBLISH_COMPLETE'){$remote=(string)(($data['publicaly_available_post_id']??$data['publicly_available_post_id']??[])[0]??'');if(!preg_match('/^[0-9]{5,40}$/D',$remote))$remote=null;opsExec($pdo,"UPDATE tiktok_publications SET status='published',remote_id=?,last_error=NULL,next_check=NULL,updated_at=? WHERE id=?",[$remote,socialNow(),$id]);return;}
                if($status==='FAILED'){tiktokDeliverySet($pdo,$id,'failed',tiktokPublishMessage((string)($data['fail_reason']??'')));return;}
                if(!in_array($status,['PROCESSING_DOWNLOAD','PROCESSING_UPLOAD'],true))throw new TikTokPublishError('TikTok не вернул окончательный статус. Повторная отправка заблокирована.',true);
                tiktokDeliverySet($pdo,$id,'processing',null,strtotime($r['approved_at'].' UTC')<time()-86400?3600:60);return;
            }
            if(strtotime($r['due_at'].' UTC')<time()-7200)throw new TikTokPublishError('Время публикации пропущено более чем на 2 часа. Подтвердите актуальный пост заново.');
            $creator=tiktokCreatorSafe($request('creator',[],$token),$c);$options=json_decode($r['snapshot'],true);
            if(!in_array($options['privacy_level'],$creator['privacy_options'],true)||($creator['comment_disabled']&&!$options['disable_comment']))throw new TikTokPublishError('Настройки TikTok изменились. Проверьте видимость и комментарии заново.');
            $path=tiktokPhotoPath($r['image_key']);if(!is_file($path)||!hash_equals($r['image_hash'],hash_file('sha256',$path)))throw new TikTokPublishError('Изображение изменилось. Сохраните пост заново.');
            $public=bin2hex(random_bytes(32));$url=appUrl('tiktok-media.php').'?id='.$id.'&k='.$public;if(!str_starts_with($url,'https://'))throw new TikTokPublishError('Для публикации TikTok нужен публичный адрес HTTPS.');
            opsExec($pdo,"UPDATE tiktok_publications SET status='sending',public_key=?,public_until=?,next_check=?,updated_at=? WHERE id=?",[$public,socialLater(48*3600),socialLater(120),socialNow(),$id]);$sending=true;
            $aigc=$options['is_aigc'];unset($options['is_aigc']);
            $data=$request('init',['post_info'=>$options,'source_info'=>['source'=>'PULL_FROM_URL','photo_cover_index'=>0,'photo_images'=>[$url]],'post_mode'=>'DIRECT_POST','media_type'=>'PHOTO','is_aigc'=>$aigc],$token);
            $publish=$data['publish_id']??'';if(!is_string($publish)||!preg_match('/^[A-Za-z0-9._~:-]{1,128}$/D',$publish))throw new TikTokPublishError('TikTok не вернул номер отправки. Проверьте профиль; повторная отправка заблокирована.',true);
            opsExec($pdo,"UPDATE tiktok_publications SET publish_id=?,status='processing',last_error=NULL,next_check=?,updated_at=? WHERE id=?",[$publish,socialLater(60),socialNow(),$id]);
        });}catch(Throwable $e){
            $message=$e instanceof TikTokPublishError||$e instanceof TikTokError?$e->getMessage():'Обработка TikTok прервана. Проверьте подключение.';
            if($r['publish_id']){tiktokDeliverySet($pdo,$id,'processing',$message,300);return;}
            $uncertain=$sending&&(!($e instanceof TikTokPublishError)||$e->uncertain);tiktokDeliverySet($pdo,$id,$uncertain?'uncertain':'failed',$message,$uncertain?86400:60);
        }
    });
}
function tiktokPublishRunDue(PDO $pdo,array $cfg,?callable $request=null): int {
    $rows=opsAll($pdo,"SELECT id FROM tiktok_publications WHERE status IN ('queued','sending','processing','uncertain') AND due_at<=? AND next_check<=? ORDER BY next_check,id LIMIT 4",[socialNow(),socialNow()]);$n=0;
    foreach($rows as $r){try{tiktokPublishStep($pdo,(int)$r['id'],$cfg,$request);$n++;}catch(Throwable $e){/* A concurrent worker owns the delivery. */}}return $n;
}
function tiktokCancelPublication(PDO $pdo,int $uid,int $post): void {
    $r=opsOne($pdo,'SELECT id FROM tiktok_publications WHERE user_id=? AND post_id=?',[$uid,$post]);if(!$r)throw new RuntimeException('Публикация TikTok не найдена.');
    tiktokDeliveryLock($pdo,(int)$r['id'],function()use($pdo,$uid,$r){
        if(!opsExec($pdo,"UPDATE tiktok_publications SET status='cancelled',public_key=NULL,public_until=NULL,next_check=NULL,updated_at=? WHERE id=? AND user_id=? AND status='queued'",[socialNow(),$r['id'],$uid]))throw new RuntimeException('TikTok уже начал обработку. Отмена отправки недоступна.');
    });
}
