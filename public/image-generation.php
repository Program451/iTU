<?php
/** Cloudflare Workers AI: fixed model, durable queue, no automatic retries or paid fallback. */
const IMAGE_AI_MODEL = '@cf/black-forest-labs/flux-1-schnell';
const IMAGE_AI_USER_LIMIT = 5;
const IMAGE_AI_ACCOUNT_LIMIT = 50;

function imageAiSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS image_ai_connections (
        user_id INT PRIMARY KEY, account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        token_box TEXT NOT NULL, revision CHAR(32) NOT NULL, active TINYINT NOT NULL DEFAULT 1,
        checked_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS image_ai_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, input_hash CHAR(64) NOT NULL,
        account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, revision CHAR(32) NOT NULL,
        prompt TEXT NOT NULL, status VARCHAR(20) NOT NULL, image_key CHAR(48) NULL,
        width INT NULL, height INT NULL, error_text VARCHAR(500) NOT NULL DEFAULT '',
        attempted_day DATE NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE KEY request_once(user_id,request_key), INDEX queue_status(status,id),
        INDEX account_usage(account_id,attempted_day), INDEX owner_jobs(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function imageAiNow(): string { return gmdate('Y-m-d H:i:s'); }
function imageAiLock(PDO $pdo, callable $fn) {
    if ((int)$pdo->query("SELECT GET_LOCK('aulieata.image-ai',0)")->fetchColumn()!==1)
        throw new RuntimeException('Генератор занят. Повторите через минуту.');
    try { return $fn(); } finally { $pdo->query("SELECT RELEASE_LOCK('aulieata.image-ai')"); }
}

/** Never return provider error bodies: they may echo request headers or private prompts. */
function imageAiRequest(string $account, string $token, ?string $prompt=null): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$account)||!preg_match('/^[A-Za-z0-9_-]{30,200}$/D',$token))
        throw new RuntimeException('Проверьте Account ID и API Token Cloudflare.');
    $path=$prompt===null?'/ai/models/search?name='.rawurlencode(IMAGE_AI_MODEL):'/ai/run/'.IMAGE_AI_MODEL;
    $ch=curl_init('https://api.cloudflare.com/client/v4/accounts/'.$account.$path);$body='';
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>12000000)return 0;$body.=$chunk;return strlen($chunk);}]);
    if($prompt!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['prompt'=>$prompt,'steps'=>4],JSON_THROW_ON_ERROR)]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode($body,true);
    if($status===401||$status===403)throw new RuntimeException('Cloudflare отклонил доступ. Проверьте аккаунт и права токена Workers AI.');
    if($status===429)throw new RuntimeException('Лимит Cloudflare исчерпан или сервис занят. Автоматического повтора нет. Попробуйте позже.');
    if(!$ok||$status<200||$status>=300||empty($data['success'])||!is_array($data['result']??null))
        throw new RuntimeException('Cloudflare не подтвердил результат. Автоматического повтора нет; попытка могла использовать квоту.');
    return $data['result'];
}

function imageAiConnect(PDO $pdo,int $uid,array $in,array $cfg,?callable $request=null): array {
    $account=strtolower(trim((string)($in['account_id']??'')));$token=trim((string)($in['token']??''));
    if(($in['free_plan']??false)!==true)throw new RuntimeException('Для этого режима подтвердите тариф Workers Free в Cloudflare.');
    if(!preg_match('/^[a-f0-9]{32}$/D',$account)||!preg_match('/^[A-Za-z0-9_-]{30,200}$/D',$token))throw new RuntimeException('Проверьте Account ID и API Token.');
    $models=($request??'imageAiRequest')($account,$token,null);
    if(!array_filter($models,fn($m)=>is_array($m)&&($m['name']??'')===IMAGE_AI_MODEL))throw new RuntimeException('Модель FLUX.1 Schnell недоступна этому токену.');
    return imageAiLock($pdo,function()use($pdo,$uid,$account,$token,$cfg){
        opsTx($pdo,$uid,function()use($pdo,$uid,$account,$token,$cfg){
            opsExec($pdo,"UPDATE image_ai_jobs SET status='cancelled',error_text='Настройки подключения изменены.',updated_at=? WHERE user_id=? AND status='queued'",[imageAiNow(),$uid]);
            opsExec($pdo,'INSERT INTO image_ai_connections(user_id,account_id,token_box,revision,checked_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),token_box=VALUES(token_box),revision=VALUES(revision),active=1,checked_at=VALUES(checked_at)',[$uid,$account,sealToken($token,$cfg),bin2hex(random_bytes(16)),imageAiNow()]);
        });return imageAiStatus($pdo,$uid);
    });
}
function imageAiDisconnect(PDO $pdo,int $uid): void {
    imageAiLock($pdo,function()use($pdo,$uid){opsTx($pdo,$uid,function()use($pdo,$uid){
        opsExec($pdo,"UPDATE image_ai_connections SET active=0,token_box='' WHERE user_id=?",[$uid]);
        opsExec($pdo,"UPDATE image_ai_jobs SET status='cancelled',error_text='Подключение отключено.',updated_at=? WHERE user_id=? AND status='queued'",[imageAiNow(),$uid]);
    });});
}
function imageAiUsage(PDO $pdo,int $uid,string $account): array {
    $day=gmdate('Y-m-d');
    $own=(int)opsOne($pdo,'SELECT COUNT(*) n FROM image_ai_jobs WHERE user_id=? AND attempted_day=?',[$uid,$day])['n'];
    $shared=(int)opsOne($pdo,'SELECT COUNT(*) n FROM image_ai_jobs WHERE account_id=? AND attempted_day=?',[$account,$day])['n'];
    return ['used'=>$own,'limit'=>IMAGE_AI_USER_LIMIT,'remaining'=>max(0,min(IMAGE_AI_USER_LIMIT-$own,IMAGE_AI_ACCOUNT_LIMIT-$shared)),'reset_at'=>gmdate('Y-m-d',time()+86400).' 00:00:00'];
}
function imageAiStatus(PDO $pdo,int $uid): array {
    $c=opsOne($pdo,'SELECT account_id,active,checked_at FROM image_ai_connections WHERE user_id=?',[$uid]);
    return ['connected'=>!empty($c['active']),'account_id'=>$c['account_id']??'','checked_at'=>$c['checked_at']??null,'model'=>'FLUX.1 Schnell']+imageAiUsage($pdo,$uid,$c['account_id']??'');
}
function imageAiSafe(array $r): array {
    return ['id'=>(int)$r['id'],'prompt'=>$r['prompt'],'status'=>$r['status'],'error'=>$r['error_text'],'created_at'=>$r['created_at'],
        'width'=>$r['width']?(int)$r['width']:null,'height'=>$r['height']?(int)$r['height']:null,
        'image_url'=>$r['status']==='done'?'image-ai-media.php?id='.(int)$r['id']:null];
}
function imageAiList(PDO $pdo,int $uid): array {return array_map('imageAiSafe',opsAll($pdo,'SELECT * FROM image_ai_jobs WHERE user_id=? ORDER BY id DESC LIMIT 200',[$uid]));}
function imageAiQueue(PDO $pdo,int $uid,array $in): array {
    $key=(string)($in['request_key']??'');$prompt=trim((string)($in['prompt']??''));
    if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$key)||mb_strlen($prompt)<10||mb_strlen($prompt)>1800)throw new RuntimeException('Добавьте описание сцены: от 10 до 1800 символов.');
    $hash=hash('sha256',$prompt);
    return opsTx($pdo,$uid,function()use($pdo,$uid,$key,$prompt,$hash){
        $old=opsOne($pdo,'SELECT * FROM image_ai_jobs WHERE user_id=? AND request_key=?',[$uid,$key]);
        if($old){if(!hash_equals($old['input_hash'],$hash))throw new RuntimeException('Этот запрос уже использован. Обновите список изображений.');return imageAiSafe($old);}
        $c=opsOne($pdo,'SELECT * FROM image_ai_connections WHERE user_id=? AND active=1',[$uid]);
        if(!$c)throw new RuntimeException('Сначала подключите Cloudflare в настройках.');
        if(imageAiUsage($pdo,$uid,$c['account_id'])['remaining']<1)throw new RuntimeException('Дневной лимит использован. Он обновится в 05:00 по времени Казахстана.');
        if((int)opsOne($pdo,"SELECT COUNT(*) n FROM image_ai_jobs WHERE user_id=? AND status IN ('queued','processing')",[$uid])['n']>=2)throw new RuntimeException('Дождитесь выполнения текущих запросов.');
        if((int)opsOne($pdo,"SELECT COUNT(*) n FROM image_ai_jobs WHERE user_id=? AND status IN ('done','queued','processing')",[$uid])['n']>=200)throw new RuntimeException('Библиотека достигла лимита 200 изображений. Обратитесь к владельцу сервиса для расширения хранения.');
        opsExec($pdo,"INSERT INTO image_ai_jobs(user_id,request_key,input_hash,account_id,revision,prompt,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'queued',?,?)",[$uid,$key,$hash,$c['account_id'],$c['revision'],$prompt,imageAiNow(),imageAiNow()]);
        return imageAiSafe(opsOne($pdo,'SELECT * FROM image_ai_jobs WHERE id=?',[(int)$pdo->lastInsertId()]));
    });
}
function imageAiCancel(PDO $pdo,int $uid,int $id): void {
    if(!opsExec($pdo,"UPDATE image_ai_jobs SET status='cancelled',updated_at=? WHERE id=? AND user_id=? AND status='queued'",[imageAiNow(),$id,$uid]))throw new RuntimeException('Запрос уже выполняется или не найден.');
}
function imageAiPath(string $key): string {
    if(!preg_match('/^[a-f0-9]{48}$/D',$key))throw new RuntimeException('Изображение не найдено.');
    return dirname(__DIR__).'/aulieata-media/generated/'.$key.'.jpg';
}
function imageAiStore(string $base64): array {
    if(strlen($base64)>11000000)throw new RuntimeException('Слишком большое изображение.');
    $raw=base64_decode($base64,true);$size=$raw!==false?@getimagesizefromstring($raw):false;
    if(!$size||strlen($raw)>8000000||$size[0]<256||$size[1]<256||$size[0]*$size[1]>4200000||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true))throw new RuntimeException('Cloudflare вернул неподходящий файл изображения.');
    $img=@imagecreatefromstring($raw);if(!$img)throw new RuntimeException('Не удалось прочитать изображение.');
    $key=bin2hex(random_bytes(24));$path=imageAiPath($key);$dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){imagedestroy($img);throw new RuntimeException('Хранилище изображений недоступно.');}
    try {if(!imagejpeg($img,$path,92))throw new RuntimeException('Не удалось сохранить изображение.');chmod($path,0600);}finally{imagedestroy($img);}
    return ['key'=>$key,'width'=>$size[0],'height'=>$size[1]];
}

/** One call per job. A crashed or timed-out request is never resubmitted automatically. */
function imageAiRun(PDO $pdo,array $cfg,?callable $request=null): int {
    return imageAiLock($pdo,function()use($pdo,$cfg,$request){
        opsExec($pdo,"UPDATE image_ai_jobs SET status='failed',error_text='Запуск прерван. Результат не подтверждён; автоматического повтора нет.',updated_at=? WHERE status='processing'",[imageAiNow()]);
        $job=opsOne($pdo,"SELECT * FROM image_ai_jobs WHERE status='queued' ORDER BY id LIMIT 1");if(!$job)return 0;
        $uid=(int)$job['user_id'];$id=(int)$job['id'];
        $c=opsOne($pdo,'SELECT * FROM image_ai_connections WHERE user_id=? AND active=1',[$uid]);
        if(!$c||$c['revision']!==$job['revision']){opsExec($pdo,"UPDATE image_ai_jobs SET status='cancelled',error_text='Подключение изменено.',updated_at=? WHERE id=? AND status='queued'",[imageAiNow(),$id]);return 1;}
        if(imageAiUsage($pdo,$uid,$c['account_id'])['remaining']<1){opsExec($pdo,"UPDATE image_ai_jobs SET status='failed',error_text='Дневной лимит исчерпан. Новый запрос возможен после 05:00 по времени Казахстана.',updated_at=? WHERE id=? AND status='queued'",[imageAiNow(),$id]);return 1;}
        // Atomic claim also resolves a concurrent cancel. Quota is reserved before the network call.
        if(!opsExec($pdo,"UPDATE image_ai_jobs SET status='processing',attempted_day=?,updated_at=? WHERE id=? AND status='queued'",[gmdate('Y-m-d'),imageAiNow(),$id]))return 0;
        $file=null;
        try{
            $data=($request??'imageAiRequest')($c['account_id'],openToken($c['token_box'],$cfg),$job['prompt']);
            $file=imageAiStore((string)($data['image']??''));
            opsExec($pdo,"UPDATE image_ai_jobs SET status='done',image_key=?,width=?,height=?,updated_at=? WHERE id=? AND status='processing'",[$file['key'],$file['width'],$file['height'],imageAiNow(),$id]);
        }catch(Throwable $e){
            if($file&&is_file(imageAiPath($file['key'])))unlink(imageAiPath($file['key']));
            $safe=$e instanceof RuntimeException&&!($e instanceof PDOException)?mb_substr($e->getMessage(),0,500):'Не удалось сохранить результат. Автоматического повтора нет.';
            opsExec($pdo,"UPDATE image_ai_jobs SET status='failed',error_text=?,updated_at=? WHERE id=?",[$safe,imageAiNow(),$id]);
        }
        return 1;
    });
}
