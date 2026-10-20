<?php
/** Selected idea -> durable image job -> private post -> optionally authorized Instagram queue. */
function ideaSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS idea_batches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,request_key VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL,payload LONGTEXT NOT NULL,error_text VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, UNIQUE KEY once_per_owner(user_id,request_key), INDEX owner_batches(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS idea_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,batch_id BIGINT UNSIGNED NOT NULL,
        idea_index INT NOT NULL,status VARCHAR(20) NOT NULL,payload LONGTEXT NOT NULL,image_id BIGINT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NULL,error_text VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
        UNIQUE KEY idea_once(user_id,batch_id,idea_index), INDEX waiting(status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ideaBatch(PDO $pdo,int $uid,int $id): array {
    $r=opsOne($pdo,'SELECT * FROM idea_batches WHERE user_id=? AND id=?',[$uid,$id]);
    if(!$r)throw new RuntimeException('Подборка не найдена.');if($r['status']==='generating'&&strtotime($r['created_at'].' UTC')<time()-180){opsExec($pdo,"UPDATE idea_batches SET status='failed',error_text='Подготовка прервалась. Запросите новую подборку.' WHERE id=? AND status='generating'",[$id]);$r['status']='failed';$r['error_text']='Подготовка прервалась. Запросите новую подборку.';}$r['data']=json_decode($r['payload'],true)?:[];unset($r['payload']);return $r;
}
function ideaGenerate(PDO $pdo,int $uid,array $in,array $cfg,?callable $request=null): array {
    $key=(string)($in['request_key']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$key))throw new RuntimeException('Обновите форму идей.');
    $brief=opsText($in['brief']??'',900);$profile=businessProfile($pdo,$uid);
    $row=opsTx($pdo,$uid,function()use($pdo,$uid,$key,$brief,$profile){
        $r=opsOne($pdo,'SELECT id FROM idea_batches WHERE user_id=? AND request_key=?',[$uid,$key]);if($r)return ['old'=>(int)$r['id']];
        if((int)opsOne($pdo,'SELECT COUNT(*) n FROM idea_batches WHERE user_id=?',[$uid])['n']>=100)throw new RuntimeException('Лимит 100 подборок. Обратитесь к владельцу сервиса.');
        opsExec($pdo,"INSERT INTO idea_batches(user_id,request_key,status,payload,created_at) VALUES(?,?,'generating',?,?)",[$uid,$key,opsJson(['brief'=>$brief,'type'=>$profile['type']]),socialNow()]);return ['id'=>(int)$pdo->lastInsertId()];
    });
    if(isset($row['old']))return ideaBatch($pdo,$uid,$row['old']);$id=$row['id'];
    try{
        $catalog=array_slice(publicCatalogData($pdo,$uid,$brief),0,18);
        $recent=[];foreach(opsAll($pdo,"SELECT payload FROM idea_batches WHERE user_id=? AND status='ready' ORDER BY id DESC LIMIT 2",[$uid]) as $old)foreach(json_decode($old['payload'],true)['ideas']??[] as $x)$recent[]=$x['title'];
        $context=['today'=>localBusinessDay(),'avoid_recent_titles'=>$recent,'sector'=>$profile['label'],'promotion'=>$profile['promotion'],'description'=>opsText($profile['description']??'',900),'request'=>$brief,
            'catalog'=>array_map(fn($x)=>['name'=>$x['item_name'],'category'=>$x['category'],'demo'=>$x['data_mode']==='demo'], $catalog)];
        $r=cloudText($pdo,$uid,$cfg,'ideas',
            'Ты креативный маркетолог локального бизнеса. Придумай РОВНО 10 разных реалистичных идей под сферу, аудиторию и цель. Минимум 2 акции offer, 2 мероприятия event, 2 полезных публикации content, 2 знакомства с продуктом product. Каждая идея должна иметь конкретную механику участия клиента и понятную пользу для бизнеса, а не общее название вроде «Новинка», «История» или «Вечеринка». Меняй подход: интерактив, знакомство с продуктом, совместный опыт, ответ на вопрос, сезонный повод. Учитывай today: не предлагай весенние или летние акции осенью. Не повторяй прошлые формулировки из avoid_recent_titles. Язык ru/kk из promotion. JSON {"ideas":[{"kind":"offer|event|content|product","title":"до 70 символов","concept":"польза и механика идеи до 240 символов, предложение владельцу, а не объявление существующего события","caption":"готовый привлекательный текст до 400 символов БЕЗ выдуманных фактов, дат, скидок, адресов, гарантий, цен и дефицита","needs":"что владельцу нужно уточнить перед публикацией до 160 символов","visual":"конкретная оригинальная рекламная сцена на английском до 350 символов"}]}. Демонстрационный каталог используй только как направление. Не обещай лечение, доход, гарантированный эффект. В caption не вставляй шаблоны в скобках и придуманные ссылки. В visual описывай одну выразительную сцену; не проси писать текст на картинке.',$context,$request);
        if(count($r['ideas']??[])!==10)throw new RuntimeException('ИИ не подготовил все 10 идей. Подборка не сохранена; попробуйте позже.');
        $ideas=[];$seen=[];foreach($r['ideas'] as $x){
            if(!is_array($x)||!in_array($x['kind']??'', ['offer','event','content','product'],true))throw new RuntimeException('ИИ вернул неподходящую идею.');
            $clean=['kind'=>$x['kind']];foreach(['title'=>70,'concept'=>300,'caption'=>500,'needs'=>200,'visual'=>450] as $k=>$limit){$clean[$k]=opsText($x[$k]??'',$limit);if(!$clean[$k])throw new RuntimeException('ИИ вернул неполную идею.');}
            $hash=mb_strtolower($clean['title']);if(isset($seen[$hash]))throw new RuntimeException('ИИ повторил идеи. Запросите новую подборку.');$seen[$hash]=true;$ideas[]=$clean;
        }
        opsExec($pdo,"UPDATE idea_batches SET status='ready',payload=? WHERE id=? AND user_id=?",[opsJson(['brief'=>$brief,'type'=>$profile['type'],'ideas'=>$ideas]),$id,$uid]);
    }catch(Throwable $e){opsExec($pdo,"UPDATE idea_batches SET status='failed',error_text=? WHERE id=? AND user_id=?",[$e instanceof PDOException?'Не удалось сохранить подборку.':opsText($e->getMessage(),500),$id,$uid]);}
    return ideaBatch($pdo,$uid,$id);
}
function ideaRunSafe(PDO $pdo,array $r): array {
    $p=json_decode($r['payload'],true)?:[];
    $image=opsOne($pdo,'SELECT * FROM image_ai_jobs WHERE id=? AND user_id=?',[$r['image_id'],$r['user_id']]);
    return ['id'=>(int)$r['id'],'batch_id'=>(int)$r['batch_id'],'idea_index'=>(int)$r['idea_index'],
        'title'=>$p['title']??'','mode'=>$p['mode']??'draft','status'=>$r['status'],'error'=>$r['error_text'],'created_at'=>$r['created_at'],
        'image'=>$image?imageAiSafe($image):null,
        'post'=>$r['post_id']?socialSafe(socialRow($pdo,(int)$r['user_id'],(int)$r['post_id']))+['tiktok'=>tiktokPostDelivery($pdo,(int)$r['user_id'],(int)$r['post_id'])]:null];
}
function ideaList(PDO $pdo,int $uid): array {
    $batches=opsAll($pdo,'SELECT id FROM idea_batches WHERE user_id=? ORDER BY id DESC LIMIT 8',[$uid]);
    return ['batches'=>array_map(fn($r)=>ideaBatch($pdo,$uid,(int)$r['id']),$batches),
        'runs'=>array_map(fn($r)=>ideaRunSafe($pdo,$r),opsAll($pdo,'SELECT * FROM idea_runs WHERE user_id=? ORDER BY id DESC LIMIT 50',[$uid])),
        'instagram'=>instagramStatus($pdo,$uid),'images'=>imageAiStatus($pdo,$uid)];
}
function ideaStart(PDO $pdo,int $uid,array $in): array {
    return opsTx($pdo,$uid,function()use($pdo,$uid,$in){
        $bid=(int)($in['batch_id']??0);$index=(int)($in['idea_index']??-1);
        $old=opsOne($pdo,'SELECT * FROM idea_runs WHERE user_id=? AND batch_id=? AND idea_index=?',[$uid,$bid,$index]);if($old)return ideaRunSafe($pdo,$old);
        $batch=ideaBatch($pdo,$uid,$bid);$idea=$batch['data']['ideas'][$index]??null;
        if($batch['status']!=='ready'||!$idea)throw new RuntimeException('Выберите готовую идею.');
        if(($batch['data']['type']??'')!==businessProfile($pdo,$uid)['type'])throw new RuntimeException('Сфера бизнеса изменилась. Создайте новую подборку.');
        $mode=$in['mode']??'draft';if(!in_array($mode,['draft','auto','multi'],true))throw new RuntimeException('Выберите режим подготовки.');
        $title=opsText($in['title']??$idea['title'],70);$copy=opsText($in['caption']??$idea['caption'],1000);$terms=opsText($in['terms']??'',600);
        if(!$title||!$copy)throw new RuntimeException('Заполните заголовок и текст поста.');
        $scheduled=trim((string)($in['scheduled_at']??''));$c=instagramStatus($pdo,$uid);
        if($mode==='auto'){
            if(($in['approved']??false)!==true)throw new RuntimeException('Подтвердите текст, условия и автоматическую публикацию изображения.');
            if(in_array($idea['kind'],['event','offer'],true)&&mb_strlen($terms)<10)throw new RuntimeException('Укажите реальные условия акции или дату, место и условия мероприятия.');
            if(!$c||$c['check_status']!=='verified'||$c['account_id']!==(string)($in['account_id']??''))throw new RuntimeException('Проверьте выбранный Instagram в настройках.');
        }
        if($scheduled!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$scheduled,new DateTimeZone('Asia/Qyzylorda'));if(!$d||$d->format('Y-m-d\TH:i')!==$scheduled||$d->getTimestamp()<time()+600||$d->getTimestamp()>time()+30*86400)throw new RuntimeException('Выберите время от 10 минут до 30 дней вперёд (UTC+5).');}
        $prompt=$idea['visual'].'. Premium advertising photography for '.businessProfile($pdo,$uid)['label'].'. One clear subject, sophisticated composition, natural realistic materials, warm editorial lighting, square 1:1 image. No text, letters, logos, watermark or invented certificates. Illustrative visual concept, not an exact product photograph.';
        $job=imageAiQueue($pdo,$uid,['request_key'=>'idea-'.$bid.'-'.$index.'-'.str_pad((string)$uid,8,'0',STR_PAD_LEFT),'prompt'=>$prompt]);
        $caption=$title."\n\n".$copy.($terms!==''?"\n\n".$terms:'')."\n\n".((businessProfile($pdo,$uid)['promotion']['language']??'ru')==='kk'?'Көрнекі сурет ЖИ көмегімен жасалды.':'Иллюстрация создана с помощью ИИ.');
        $p=['title'=>$title,'caption'=>$caption,'prompt'=>$prompt,'mode'=>$mode,'scheduled_at'=>$scheduled,'account_id'=>$mode==='auto'?$c['account_id']:'','account_name'=>$mode==='auto'?$c['username']:'','approved_at'=>$mode==='auto'?socialNow():null];
        opsExec($pdo,"INSERT INTO idea_runs(user_id,batch_id,idea_index,status,payload,image_id,created_at,updated_at) VALUES(?,?,?,'waiting_image',?,?,?,?)",[$uid,$bid,$index,opsJson($p),$job['id'],socialNow(),socialNow()]);$id=(int)$pdo->lastInsertId();
        opsAudit($pdo,$uid,'idea_selected',$id,['mode'=>$mode,'account'=>$p['account_name'],'scheduled_at'=>$scheduled,'caption'=>$caption]);
        return ideaRunSafe($pdo,opsOne($pdo,'SELECT * FROM idea_runs WHERE id=?',[$id]));
    });
}
function ideaLock(PDO $pdo,int $id,callable $fn){$key='aulieata.idea.'.$id;if(!(int)opsOne($pdo,'SELECT GET_LOCK(?,0) n',[$key])['n'])throw new RuntimeException('Идея обрабатывается. Повторите позже.');try{return $fn();}finally{opsOne($pdo,'SELECT RELEASE_LOCK(?) n',[$key]);}}
function ideaCancel(PDO $pdo,int $uid,int $id): void {
    ideaLock($pdo,$id,function()use($pdo,$uid,$id){$r=opsOne($pdo,'SELECT * FROM idea_runs WHERE id=? AND user_id=?',[$id,$uid]);if(!$r||$r['status']!=='waiting_image')throw new RuntimeException('Подготовка уже завершена. Управляйте постом в списке публикаций.');
        if($r['post_id']){$post=socialRow($pdo,$uid,(int)$r['post_id']);if($post['status']!=='draft')socialChange($pdo,$uid,(int)$r['post_id'],'cancel');}
        opsExec($pdo,"UPDATE idea_runs SET status='cancelled',updated_at=? WHERE id=? AND user_id=?",[socialNow(),$id,$uid]);
        opsExec($pdo,"UPDATE image_ai_jobs SET status='cancelled',updated_at=? WHERE id=? AND user_id=? AND status='queued'",[socialNow(),$r['image_id'],$uid]);
    });
}
function ideaPostImage(array $job): string {
    $path=imageAiPath($job['image_key']);$img=@imagecreatefromjpeg($path);if(!$img)throw new RuntimeException('Изображение недоступно.');
    $out=imagecreatetruecolor(1080,1080);imagecopyresampled($out,$img,0,0,0,0,1080,1080,imagesx($img),imagesy($img));
    ob_start();imagejpeg($out,null,88);$raw=ob_get_clean();imagedestroy($img);imagedestroy($out);return 'data:image/jpeg;base64,'.base64_encode($raw);
}
function ideaRunDue(PDO $pdo): int {
    $rows=opsAll($pdo,"SELECT id,user_id FROM idea_runs WHERE status='waiting_image' ORDER BY id LIMIT 20");$n=0;
    foreach($rows as $entry){try{ideaLock($pdo,(int)$entry['id'],function()use($pdo,$entry,&$n){
        $id=(int)$entry['id'];$uid=(int)$entry['user_id'];$r=opsOne($pdo,"SELECT * FROM idea_runs WHERE id=? AND status='waiting_image'",[$id]);if(!$r)return;
        $job=opsOne($pdo,'SELECT * FROM image_ai_jobs WHERE id=? AND user_id=?',[$r['image_id'],$uid]);
        if($job&&in_array($job['status'],['queued','processing'],true))return;
        try{
            if(!$job||$job['status']!=='done')throw new RuntimeException($job['error_text']??'Изображение не создано.');
            $p=json_decode($r['payload'],true);$post=socialSave($pdo,$uid,['request_key'=>'idea-post-'.str_pad((string)$id,16,'0',STR_PAD_LEFT),'title'=>$p['title'],'caption'=>$p['caption'],'design'=>['headline'=>$p['title'],'layout'=>'photo','source_label'=>'ИИ-иллюстрация','ai_generated'=>true,'destinations'=>$p['mode']==='multi'?['instagram','tiktok']:[]],'image'=>ideaPostImage($job)]);
            // Save the post ID before enqueueing. Retry after interruption finds the same post.
            opsExec($pdo,'UPDATE idea_runs SET post_id=? WHERE id=?',[$post['id'],$id]);
            if($p['mode']==='auto'&&$post['status']==='draft'){
                if((int)$post['version']!==1)throw new RuntimeException('Пост изменён или отменён. Автоматический запуск остановлен.');
                if($p['scheduled_at']!==''&&strtotime($p['scheduled_at'].'+05:00')<time()+60)throw new RuntimeException('Время публикации прошло во время подготовки. Изображение сохранено в черновиках; выберите новое время.');
                socialQueue($pdo,$uid,['id'=>$post['id'],'version'=>$post['version'],'approved'=>true,'account_id'=>$p['account_id'],'scheduled_at'=>$p['scheduled_at']]);
            }
            opsExec($pdo,"UPDATE idea_runs SET status='done',error_text='',updated_at=? WHERE id=?",[socialNow(),$id]);
        }catch(Throwable $e){opsExec($pdo,"UPDATE idea_runs SET status='failed',error_text=?,updated_at=? WHERE id=?",[$e instanceof PDOException?'Не удалось сохранить пост. Проверьте список черновиков.':opsText($e->getMessage(),500),socialNow(),$id]);}
        $n++;
    });}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;}}
    return $n;
}
