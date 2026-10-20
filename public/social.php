<?php
/** Free poster drafts and an approval-gated Instagram publishing queue. */
function socialSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_posts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        request_key VARCHAR(64) NOT NULL, version INT NOT NULL DEFAULT 1,
        title VARCHAR(180) NOT NULL, caption TEXT NOT NULL, design TEXT NOT NULL,
        image_key CHAR(48) NOT NULL, image_hash CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'draft', account_id VARCHAR(40) NULL,
        account_name VARCHAR(100) NULL, approved_at DATETIME NULL, due_at DATETIME NULL,
        container_id VARCHAR(40) NULL, media_id VARCHAR(40) NULL, permalink VARCHAR(500) NULL,
        public_key CHAR(64) NULL, public_until DATETIME NULL,
        next_check DATETIME NULL, attempts INT NOT NULL DEFAULT 0, last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE KEY request_once(user_id,request_key), INDEX due(status,next_check,due_at), INDEX owner(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function socialNow(): string { return gmdate('Y-m-d H:i:s'); }
function socialLater(int $seconds): string { return gmdate('Y-m-d H:i:s', time()+$seconds); }
function socialImagePath(string $key): string {
    if (!preg_match('/^[a-f0-9]{48}$/D',$key)) throw new RuntimeException('Изображение не найдено.');
    return dirname(__DIR__).'/aulieata-media/social/'.$key.'.jpg';
}
function socialImageSave(string $value): array {
    if (strlen($value)>2200000 || !str_starts_with($value,'data:image/jpeg;base64,')) throw new RuntimeException('Нужна JPEG-карточка размером до 1,5 МБ.');
    $raw=base64_decode(substr($value,23),true);
    if (!$raw || strlen($raw)>1600000) throw new RuntimeException('Изображение слишком большое.');
    $size=@getimagesizefromstring($raw);
    if (!$size || $size[2]!==IMAGETYPE_JPEG || $size[0]!==1080 || !in_array($size[1],[1080,1350],true)) throw new RuntimeException('Нужна карточка 1080 × 1080 или 1080 × 1350.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('На сервере нужен модуль GD для изображений.');
    $img=@imagecreatefromstring($raw); if (!$img) throw new RuntimeException('Не удалось прочитать изображение.');
    $key=bin2hex(random_bytes(24)); $path=socialImagePath($key); $dir=dirname($path);
    try {
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Не удалось создать хранилище изображений.');
        if (!imagejpeg($img,$path,90)) throw new RuntimeException('Не удалось сохранить изображение.');
        chmod($path,0600);
    } finally { imagedestroy($img); }
    return ['key'=>$key,'hash'=>hash_file('sha256',$path)];
}
function socialRow(PDO $pdo,int $uid,int $id,bool $lock=false): array {
    $r=opsOne($pdo,'SELECT * FROM social_posts WHERE user_id=? AND id=?'.($lock?' FOR UPDATE':''),[$uid,$id]);
    if (!$r) throw new RuntimeException('Пост не найден.'); return $r;
}
function socialSafe(array $r): array {
    $keys=['id','version','title','caption','status','account_name','approved_at','due_at','media_id','permalink','last_error','created_at','updated_at'];
    $out=array_intersect_key($r,array_flip($keys));
    $out['id']=(int)$r['id']; $out['version']=(int)$r['version'];
    $out['design']=json_decode($r['design'],true)?:[];
    $out['image_url']='social-media.php?id='.$out['id'].'&v='.$out['version'];
    return $out;
}
function socialList(PDO $pdo,int $uid): array {
    $deliveries=[];foreach(opsAll($pdo,'SELECT * FROM tiktok_publications WHERE user_id=?',[$uid]) as $d)$deliveries[(int)$d['post_id']]=tiktokPublicationSafe($d);
    return array_map(function($r)use($deliveries){$p=socialSafe($r);$p['tiktok']=$deliveries[(int)$r['id']]??null;return $p;},opsAll($pdo,'SELECT * FROM social_posts WHERE user_id=? ORDER BY id DESC LIMIT 100',[$uid]));
}
function socialSave(PDO $pdo,int $uid,array $d): array {
    $id=(int)($d['id']??0); $version=(int)($d['version']??0); $request=(string)($d['request_key']??'');
    if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$request)) throw new RuntimeException('Обновите форму поста.');
    $title=trim((string)($d['title']??'')); $caption=trim((string)($d['caption']??''));
    if ($title===''||mb_strlen($title)>180||$caption===''||mb_strlen($caption)>2200) throw new RuntimeException('Заполните заголовок до 180 и подпись до 2200 символов.');
    $input=$d['design']??[]; $design=[];
    foreach (['brand'=>70,'headline'=>100,'detail'=>160,'price'=>40,'cta'=>70,'layout'=>12,'theme'=>20,'language'=>2,'source_label'=>180] as $k=>$limit) $design[$k]=opsText($input[$k]??'',$limit);
    $design['demo']=!empty($input['demo']);
    $design['ai_generated']=($input['ai_generated']??false)===true;
    $design['destinations']=array_values(array_intersect(is_array($input['destinations']??null)?$input['destinations']:[],['instagram','tiktok']));
    if ($design['headline']==='') throw new RuntimeException('Добавьте заголовок изображения.');
    $image=null;$oldImage=null;
    try {
        $result=opsTx($pdo,$uid,function()use($pdo,$uid,$d,$id,$version,$request,$title,$caption,$design,&$image,&$oldImage){
            if (!$id) {
                $existing=opsOne($pdo,'SELECT * FROM social_posts WHERE user_id=? AND request_key=?',[$uid,$request]);
                if ($existing) return socialSafe($existing);
                $count=opsOne($pdo,'SELECT COUNT(*) n FROM social_posts WHERE user_id=?',[$uid]);
                if ((int)$count['n']>=100) throw new RuntimeException('Достигнут лимит: 100 сохранённых постов. Обратитесь к владельцу сервера.');
            } else {
                $old=socialRow($pdo,$uid,$id,true);
                if(opsOne($pdo,"SELECT id FROM tiktok_publications WHERE post_id=? AND user_id=? AND status IN ('queued','sending','processing','published','uncertain')",[$id,$uid]))throw new RuntimeException('Пост уже подтверждён для TikTok. Для нового содержания создайте новый пост.');
                if ($old['status']!=='draft'||(int)$old['version']!==$version) throw new RuntimeException('Пост изменился или уже поставлен в очередь. Обновите список.');
            }
            $image=socialImageSave((string)($d['image']??'')); $now=socialNow();
            if ($id) {
                opsExec($pdo,'UPDATE social_posts SET title=?,caption=?,design=?,image_key=?,image_hash=?,version=version+1,updated_at=? WHERE user_id=? AND id=?',[$title,$caption,opsJson($design),$image['key'],$image['hash'],$now,$uid,$id]);
            } else {
                opsExec($pdo,'INSERT INTO social_posts(user_id,request_key,title,caption,design,image_key,image_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$uid,$request,$title,$caption,opsJson($design),$image['key'],$image['hash'],$now,$now]);
                $id=(int)$pdo->lastInsertId();
            }
            $saved=socialSafe(socialRow($pdo,$uid,$id));
            // Old versions are private; remove only this draft's superseded local file.
            if (isset($old)) $oldImage=$old['image_key'];
            return $saved;
        });
        if($oldImage&&is_file(socialImagePath($oldImage)))@unlink(socialImagePath($oldImage));
        return $result;
    } catch(Throwable $e) { if($image)@unlink(socialImagePath($image['key'])); throw $e; }
}
function socialLock(PDO $pdo,int $id,callable $fn) {
    $key='aulieata_social_'.$id;
    if (!(int)opsOne($pdo,'SELECT GET_LOCK(?,0) acquired',[$key])['acquired']) throw new RuntimeException('Пост обрабатывается. Повторите через несколько секунд.');
    try{return $fn();}finally{opsOne($pdo,'SELECT RELEASE_LOCK(?) released',[$key]);}
}
function socialQueue(PDO $pdo,int $uid,array $d): array {
    if (($d['approved']??false)!==true) throw new RuntimeException('Подтвердите изображение, текст и аккаунт.');
    $id=(int)($d['id']??0); $version=(int)($d['version']??0);
    return socialLock($pdo,$id,fn()=>opsTx($pdo,$uid,function()use($pdo,$uid,$id,$version,$d){
        $r=socialRow($pdo,$uid,$id,true);
        // Repeated clicks or lost responses cannot enqueue another delivery.
        if (in_array($r['status'],['queued','processing','publishing','published','uncertain'],true) && (int)$r['version']===$version) return socialSafe($r);
        if ($r['status']!=='draft'||(int)$r['version']!==$version) throw new RuntimeException('Сначала откройте актуальный черновик.');
        $c=instagramStatus($pdo,$uid);
        if (!$c||$c['check_status']!=='verified'||$c['account_id']!==(string)($d['account_id']??'')) throw new RuntimeException('Подключение Instagram изменилось. Проверьте аккаунт в настройках.');
        if (!is_file(socialImagePath($r['image_key']))||!hash_equals($r['image_hash'],hash_file('sha256',socialImagePath($r['image_key'])))) throw new RuntimeException('Изображение изменилось. Сохраните пост заново.');
        $local=trim((string)($d['scheduled_at']??'')); $due=socialNow();
        if ($local!=='') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new DateTimeZone('Asia/Qyzylorda'));
            if (!$date||$date->format('Y-m-d\TH:i')!==$local||$date->getTimestamp()<time()+60||$date->getTimestamp()>time()+30*86400) throw new RuntimeException('Выберите время от одной минуты до 30 дней вперёд, UTC+5.');
            $due=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        opsExec($pdo,"UPDATE social_posts SET status='queued',account_id=?,account_name=?,approved_at=?,due_at=?,next_check=?,attempts=0,last_error=NULL,container_id=NULL,media_id=NULL,permalink=NULL,public_key=NULL,public_until=NULL,updated_at=? WHERE user_id=? AND id=?",[$c['account_id'],$c['username'],socialNow(),$due,$due,socialNow(),$uid,$id]);
        opsAudit($pdo,$uid,'social_approved',$id,['version'=>$version,'account'=>$c['username'],'due_at'=>$due,'image_hash'=>$r['image_hash']]);
        return socialSafe(socialRow($pdo,$uid,$id));
    }));
}
function socialChange(PDO $pdo,int $uid,int $id,string $action): array {
    return socialLock($pdo,$id,fn()=>opsTx($pdo,$uid,function()use($pdo,$uid,$id,$action){
        $r=socialRow($pdo,$uid,$id,true);
        if ($action==='archive'&&$r['status']==='draft') $status='archived';
        elseif ($action==='restore'&&$r['status']==='archived') $status='draft';
        elseif ($action==='cancel'&&in_array($r['status'],['queued','processing','failed'],true)) $status='draft';
        else throw new RuntimeException('Это действие сейчас недоступно. Неопределённую публикацию нельзя отправлять повторно.');
        opsExec($pdo,'UPDATE social_posts SET status=?,version=version+1,approved_at=NULL,public_key=NULL,public_until=NULL,container_id=NULL,next_check=NULL,last_error=NULL,updated_at=? WHERE user_id=? AND id=?',[$status,socialNow(),$uid,$id]);
        opsAudit($pdo,$uid,'social_'.$action,$id,[]);return socialSafe(socialRow($pdo,$uid,$id));
    }));
}
class SocialProviderError extends RuntimeException {
    public bool $uncertain;
    public function __construct(string $message,bool $uncertain=false){parent::__construct($message);$this->uncertain=$uncertain;}
}
function socialRequest(string $token,string $method,string $path,array $params=[]): array {
    if (!in_array($method,['GET','POST'],true)||!preg_match('~^/[0-9]{5,40}(?:/(?:media|media_publish|content_publishing_limit))?$~D',$path)) throw new RuntimeException('Unsupported publishing request');
    $url='https://graph.instagram.com/v25.0'.$path;
    if($method==='GET')$url.='?'.http_build_query($params);
    $ch=curl_init($url);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json']];
    if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($params);}
    curl_setopt_array($ch,$options);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);
    if($code!==200||!is_array($data)||isset($data['error'])){
        $invalid=(int)($data['error']['code']??0)===190;
        $message=$invalid?'Маркер Instagram истёк или отозван. Замените его в настройках.':'Instagram не подтвердил запрос. Проверьте доступ на публикацию, изображение и лимит аккаунта.';
        throw new SocialProviderError($message,$code===0||$code>=500||!is_array($data)||!isset($data['error']));
    }
    return $data;
}
function socialId(array $data): string {
    $id=(string)($data['id']??''); if(!preg_match('/^[0-9]{5,40}$/D',$id))throw new SocialProviderError('Instagram не вернул идентификатор. Результат нужно проверить.',true);return $id;
}
function socialSet(PDO $pdo,int $id,string $status,?string $error=null,int $delay=60): void {
    opsExec($pdo,'UPDATE social_posts SET status=?,last_error=?,next_check=?,updated_at=? WHERE id=?',[$status,$error,socialLater($delay),socialNow(),$id]);
}
function socialPublishStep(PDO $pdo,int $id,array $cfg,?callable $request=null): void {
    $request??='socialRequest';
    socialLock($pdo,$id,function()use($pdo,$id,$cfg,$request){
        $r=opsOne($pdo,'SELECT * FROM social_posts WHERE id=?',[$id]);
        if(!$r||!in_array($r['status'],['queued','processing','publishing','uncertain'],true)||$r['due_at']>socialNow()||$r['next_check']>socialNow())return;
        $afterSend=in_array($r['status'],['publishing','uncertain'],true);
        $c=opsOne($pdo,'SELECT account_id,token_box FROM aulieata_instagram_connections WHERE user_id=?',[$r['user_id']]);
        if(!$c||$c['account_id']!==$r['account_id']){socialSet($pdo,$id,$afterSend?'uncertain':'failed','Подключённый аккаунт изменён или отключён.',3600);return;}
        try{$token=openToken($c['token_box'],$cfg);}catch(Throwable $e){socialSet($pdo,$id,$afterSend?'uncertain':'failed','Не удалось открыть сохранённый токен.',3600);return;}
        if(!$r['approved_at']){socialSet($pdo,$id,'failed','Нет подтверждения владельца.');return;}
        opsExec($pdo,'UPDATE social_posts SET attempts=attempts+1 WHERE id=?',[$id]);
        try{
            if($r['status']==='queued'){
                if(!is_file(socialImagePath($r['image_key']))||!hash_equals($r['image_hash'],hash_file('sha256',socialImagePath($r['image_key']))))throw new SocialProviderError('Изображение изменилось. Сохраните новый черновик.');
                if(strtotime($r['due_at'].' UTC')<time()-7200)throw new SocialProviderError('Время публикации пропущено более чем на 2 часа. Проверьте актуальность поста и назначьте новое время.');
                $quota=$request($token,'GET','/'.$r['account_id'].'/content_publishing_limit',['fields'=>'config,quota_usage']);
                $q=$quota['data'][0]??[];
                if(isset($q['config']['quota_total'],$q['quota_usage'])&&(int)$q['quota_usage']>=(int)$q['config']['quota_total'])throw new SocialProviderError('Лимит публикаций Instagram исчерпан. Выберите другое время.');
                $public=bin2hex(random_bytes(32));
                // Persist before network I/O. A crash here cannot accidentally publish later.
                opsExec($pdo,"UPDATE social_posts SET status='processing',public_key=?,public_until=?,next_check=?,updated_at=? WHERE id=?",[$public,socialLater(48*3600),socialLater(60),socialNow(),$id]);
                $url=appUrl('social-media.php').'?id='.$id.'&k='.$public;
                $container=socialId($request($token,'POST','/'.$r['account_id'].'/media',['image_url'=>$url,'caption'=>$r['caption']]));
                opsExec($pdo,'UPDATE social_posts SET container_id=? WHERE id=?',[$container,$id]);
                return;
            }
            if(!$r['container_id']){socialSet($pdo,$id,'failed','Подготовка изображения прервана. Верните пост в черновики и подтвердите заново.');return;}
            $info=$request($token,'GET','/'.$r['container_id'],['fields'=>'status_code']);$state=$info['status_code']??'';
            if($state==='PUBLISHED'){$afterSend=true;socialSet($pdo,$id,'published');return;}
            if($afterSend){socialSet($pdo,$id,'uncertain','Ответ о публикации не подтверждён. Проверьте профиль Instagram; повторная отправка заблокирована.',3600);return;}
            if(in_array($state,['ERROR','EXPIRED'],true))throw new SocialProviderError('Instagram не подготовил изображение или срок контейнера истёк. Верните пост в черновики.');
            if($state!=='FINISHED'){
                if((int)$r['attempts']>=15)throw new SocialProviderError('Instagram слишком долго обрабатывает изображение. Верните пост в черновики.');
                socialSet($pdo,$id,'processing',null,60);return;
            }
            // Once this marker is committed, recovery is read-only, even after a timeout/crash.
            socialSet($pdo,$id,'publishing',null,120);
            $afterSend=true;
            try{$media=socialId($request($token,'POST','/'.$r['account_id'].'/media_publish',['creation_id'=>$r['container_id']]));}
            catch(Throwable $e){socialSet($pdo,$id,'uncertain','Отправка началась, но ответ не подтверждён. Проверим статус без повторной публикации.',180);return;}
            opsExec($pdo,"UPDATE social_posts SET status='published',media_id=?,last_error=NULL,updated_at=? WHERE id=?",[$media,socialNow(),$id]);
            try{
                $info=$request($token,'GET','/'.$media,['fields'=>'permalink']);$link=(string)($info['permalink']??'');
                if(preg_match('~^https://(?:www\.)?instagram\.com/(?:p|reel)/[A-Za-z0-9_-]+/?$~D',$link))opsExec($pdo,'UPDATE social_posts SET permalink=? WHERE id=?',[$link,$id]);
            }catch(Throwable $e){/* Publication ID already confirms success; link is optional. */}
        }catch(Throwable $e){
            $safe=$e instanceof SocialProviderError?$e->getMessage():'Обработка прервана. Проверьте подключение и повторите проверку позже.';
            if($afterSend){socialSet($pdo,$id,'uncertain',$safe,3600);return;}
            // Only pre-publication read failures are retried; creating a container never sends a post.
            if($r['status']==='processing' && $e instanceof SocialProviderError && $e->uncertain && (int)$r['attempts']<15){socialSet($pdo,$id,'processing',$safe,120);return;}
            socialSet($pdo,$id,'failed',$safe);
        }
    });
}
function socialRunDue(PDO $pdo,array $cfg,?callable $request=null): int {
    $rows=opsAll($pdo,"SELECT id FROM social_posts WHERE status IN ('queued','processing','publishing','uncertain') AND due_at<=? AND next_check<=? ORDER BY next_check,id LIMIT 8",[socialNow(),socialNow()]);$n=0;
    foreach($rows as $row){try{socialPublishStep($pdo,(int)$row['id'],$cfg,$request);$n++;}catch(Throwable $e){/* Another worker/cancellation owns the lock; leave it for the next tick. */}}return $n;
}
