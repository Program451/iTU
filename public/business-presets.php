<?php
// Editorial presets only: never create stock, schedules, credentials or paid jobs.
function businessPresets(): array {
    static $presets;
    return $presets ??= json_decode(file_get_contents(__DIR__.'/business-presets.json'), true, 32, JSON_THROW_ON_ERROR);
}
function businessPromotionOptions(): array {
    return ['goal'=>['requests'=>'Больше обращений','awareness'=>'Знакомство с бизнесом','loyalty'=>'Повторные обращения'],
        'tone'=>['friendly'=>'Дружелюбно','expert'=>'Деловито и по делу','simple'=>'Просто и кратко'],
        'language'=>['ru'=>'Русский','kk'=>'Қазақша'],
        'theme'=>['forest'=>'Лесной зелёный','cream'=>'Тёплый минимализм','night'=>'Ночной индиго']];
}
function businessPromotionDefaults(string $type): array {
    $preset=businessPresets()[$type]??businessPresets()['services'];
    return ['goal'=>'requests','tone'=>'friendly','language'=>'ru','theme'=>$preset['theme'],'audience'=>'','cta'=>''];
}
function businessProfileInput(array $data, array $previous): array {
    $type=$data['type']??null;
    if(!is_string($type)||($type!=='beginner'&&!isset(businessPresets()[$type])))throw new RuntimeException('Выберите сферу бизнеса');
    $out=['type'=>$type];
    foreach(['description','rules'] as $key){
        $value=$data[$key]??'';
        if(!is_string($value)||mb_strlen($value)>3000)throw new RuntimeException('Описание и правила — текст до 3000 символов');
        $out[$key]=trim($value);
    }
    $map=is_array($previous['promotion_by_type']??null)?$previous['promotion_by_type']:[];
    $map=array_intersect_key($map,businessPresets());
    if(array_key_exists('promotion',$data)){
        if(!is_array($data['promotion']))throw new RuntimeException('Проверьте настройки контента');
        $p=businessPromotionDefaults($type);
        foreach(businessPromotionOptions() as $key=>$options){
            $value=$data['promotion'][$key]??$p[$key];
            if(!is_string($value)||!isset($options[$value]))throw new RuntimeException('Проверьте цель, стиль и язык контента');
            $p[$key]=$value;
        }
        foreach(['audience'=>300,'cta'=>70] as $key=>$max){
            $value=$data['promotion'][$key]??'';
            if(!is_string($value)||mb_strlen($value)>$max)throw new RuntimeException('Проверьте длину аудитории и призыва к действию');
            $p[$key]=trim($value);
        }
        $map[$type]=$p;
    }
    $out['promotion_by_type']=$map;
    $out['details_by_type']=array_intersect_key(is_array($previous['details_by_type']??null)?$previous['details_by_type']:[],['beginner'=>true]+businessPresets());
    $oldType=$previous['type']??$type;
    $out['details_by_type'][$oldType]=['description'=>$previous['description']??'','rules'=>$previous['rules']??''];
    $out['details_by_type'][$type]=['description'=>$out['description'],'rules'=>$out['rules']];
    return $out;
}
function businessSaveProfile(PDO $pdo, int $uid, array $data): void {
    $pdo->beginTransaction();
    try {
        // Serialise profile writes in this workspace so another type's preferences survive.
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);
        if(!$lock->fetchColumn())throw new RuntimeException('Рабочее пространство не найдено');
        $q=$pdo->prepare("SELECT payload FROM aulieata_records WHERE user_id=? AND kind='profile' AND archived=0 ORDER BY id DESC LIMIT 1");$q->execute([$uid]);
        $previous=json_decode($q->fetchColumn()?:'{}',true)?:[];
        $profile=businessProfileInput($data,$previous);
        $q=$pdo->prepare("UPDATE aulieata_records SET archived=1 WHERE user_id=? AND kind='profile' AND archived=0");$q->execute([$uid]);
        $q=$pdo->prepare("INSERT INTO aulieata_records(user_id,kind,payload) VALUES(?,'profile',?)");$q->execute([$uid,json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $q=$pdo->prepare('UPDATE users SET biz_category=? WHERE id=?');$q->execute([$profile['type'],$uid]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function businessPublicProfile(array $profile): array {return array_intersect_key($profile,array_flip(['type','label','description','rules']));}
