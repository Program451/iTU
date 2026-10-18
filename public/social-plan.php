<?php
/** User-selected weekly slots; the existing durable publisher handles delivery. */
function socialPlanClean(array $input): array {
    $time=(string)($input['time']??'12:00');
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new RuntimeException('Укажите время ЧЧ:ММ.');
    $days=$input['days']??[1,3,5];if(!is_array($days)||!count($days)||count($days)>7)throw new RuntimeException('Выберите дни недели.');
    $out=[];foreach($days as $d){if(filter_var($d,FILTER_VALIDATE_INT)===false||(int)$d<1||(int)$d>7)throw new RuntimeException('Проверьте дни недели.');$out[]=(int)$d;}
    $out=array_values(array_unique($out));sort($out);return ['time'=>$time,'days'=>$out,'timezone'=>'Asia/Qyzylorda'];
}
function socialPlanSettings(PDO $pdo,int $uid): array {
    $r=opsOne($pdo,"SELECT payload FROM aulieata_records WHERE user_id=? AND kind='social_schedule' AND archived=0 ORDER BY id DESC LIMIT 1",[$uid]);
    return socialPlanClean(json_decode($r['payload']??'{}',true)?:[]);
}
function socialPlanStore(PDO $pdo,int $uid,array $s): array {
    $s=socialPlanClean($s);return opsTx($pdo,$uid,function()use($pdo,$uid,$s){
        $r=opsOne($pdo,"SELECT id FROM aulieata_records WHERE user_id=? AND kind='social_schedule' AND archived=0 ORDER BY id DESC LIMIT 1",[$uid]);
        if($r)opsPutRecord($pdo,$uid,(int)$r['id'],$s);else opsRecord($pdo,$uid,'social_schedule',$s);return $s;
    });
}
function socialPlanSlots(array $settings,int $count,array $occupied=[],?DateTimeImmutable $now=null): array {
    $s=socialPlanClean($settings);$tz=new DateTimeZone($s['timezone']);$now=($now??new DateTimeImmutable('now'))->setTimezone($tz);
    $cursor=$now->setTime((int)substr($s['time'],0,2),(int)substr($s['time'],3,2));$slots=[];
    for($i=0;$i<=30&&count($slots)<$count;$i++,$cursor=$cursor->modify('+1 day')){
        if($cursor->getTimestamp()<$now->getTimestamp()+120||$cursor->getTimestamp()>$now->getTimestamp()+30*86400||!in_array((int)$cursor->format('N'),$s['days'],true))continue;
        $utc=$cursor->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if(in_array($utc,$occupied,true))continue;
        $slots[]=$cursor->format('Y-m-d\TH:i');
    }
    if(count($slots)<$count)throw new RuntimeException('В ближайшие 30 дней недостаточно свободных дней. Выберите меньше постов или больше дней недели.');
    return $slots;
}
function socialPlanPreview(PDO $pdo,int $uid,array $d): array {
    $ids=$d['ids']??[];if(!is_array($ids)||count($ids)<1||count($ids)>30)throw new RuntimeException('Выберите от 1 до 30 черновиков.');
    $ids=array_map('intval',$ids);if(count(array_unique($ids))!==count($ids))throw new RuntimeException('Один пост выбран несколько раз.');
    $settings=socialPlanClean($d['settings']??[]);$c=instagramStatus($pdo,$uid);
    if(!$c||$c['check_status']!=='verified')throw new RuntimeException('Сначала проверьте подключение Instagram.');
    $occupied=array_column(opsAll($pdo,"SELECT due_at FROM social_posts WHERE user_id=? AND status IN ('queued','processing','publishing','uncertain','published')",[$uid]),'due_at');
    $slots=socialPlanSlots($settings,count($ids),$occupied);$posts=[];
    foreach($ids as $i=>$id){$row=socialRow($pdo,$uid,$id);if($row['status']!=='draft')throw new RuntimeException('Выбранный пост уже изменён. Обновите список.');$posts[]=socialSafe($row)+['scheduled_at'=>$slots[$i]];}
    return ['account_id'=>$c['account_id'],'account_name'=>$c['username'],'settings'=>$settings,'posts'=>$posts];
}
function socialPlanApply(PDO $pdo,int $uid,array $d): array {
    if(($d['approved']??false)!==true)throw new RuntimeException('Подтвердите выбранные материалы и расписание.');
    $key=(string)($d['request_key']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$key))throw new RuntimeException('Обновите предпросмотр расписания.');
    $rows=$d['posts']??[];if(!is_array($rows)||count($rows)<1||count($rows)>30)throw new RuntimeException('Выберите от 1 до 30 постов.');
    return opsTx($pdo,$uid,function()use($pdo,$uid,$d,$key,$rows){
        foreach(opsRecords($pdo,$uid,'social_batches') as $batch)if(($batch['data']['request_key']??'')===$key)return $batch['data']['result'];
        $ids=[];$slots=[];$results=[];
        foreach($rows as $r){$id=(int)($r['id']??0);$slot=(string)($r['scheduled_at']??'');if(in_array($id,$ids,true)||in_array($slot,$slots,true))throw new RuntimeException('Повтор поста или времени в расписании.');$ids[]=$id;$slots[]=$slot;
            $existing=socialRow($pdo,$uid,$id,true);if($existing['status']!=='draft'||(int)$existing['version']!==(int)($r['version']??0))throw new RuntimeException('Пост изменился. Откройте новый предпросмотр.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$slot,new DateTimeZone('Asia/Qyzylorda'));
            if(!$date||$date->format('Y-m-d\TH:i')!==$slot)throw new RuntimeException('Некорректная дата публикации.');
            $conflict=opsOne($pdo,"SELECT id FROM social_posts WHERE user_id=? AND due_at=? AND status IN ('queued','processing','publishing','uncertain')",[$uid,$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            if($conflict)throw new RuntimeException('Это время уже занято. Откройте новый предпросмотр.');
            $results[]=socialQueue($pdo,$uid,['id'=>$id,'version'=>(int)$r['version'],'account_id'=>$d['account_id']??'','scheduled_at'=>$slot,'approved'=>true]);
        }
        socialPlanStore($pdo,$uid,$d['settings']??[]);$result=['posts'=>$results,'count'=>count($results)];
        opsRecord($pdo,$uid,'social_batches',['request_key'=>$key,'result'=>$result]);
        opsRecord($pdo,$uid,'automation_logs',['name'=>'Расписание публикаций','text'=>'Поставлено постов: '.count($results).'. Доставка выполняется сервером; повторная отправка отключена.']);
        return $result;
    });
}
