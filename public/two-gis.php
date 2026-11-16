<?php
/** Official Places API: company search and rating statistics, never review text or replies. */
function gisSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gis_connections(user_id INT PRIMARY KEY,key_box TEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1,checked_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function gisStatus(PDO $pdo,int $uid): array {
    $r=opsOne($pdo,'SELECT active,checked_at FROM gis_connections WHERE user_id=?',[$uid]);return ['connected'=>!empty($r['active']),'checked_at'=>$r['checked_at']??null,'review_text_api'=>false,'reply_api'=>false];
}
function gisRequest(string $key,string $query,?callable $transport=null): array {
    $params=['key'=>$key,'q'=>$query,'type'=>'branch','page_size'=>5,'fields'=>'items.reviews','locale'=>'ru_KZ'];
    if($transport)$body=$transport($params);
    else{$ch=curl_init('https://catalog.api.2gis.com/3.0/items?'.http_build_query($params));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_errno($ch);curl_close($ch);if($error||$code!==200)throw new RuntimeException($code===429?'Лимит запросов 2ГИС исчерпан. Повторите позже.':'2ГИС не принял запрос. Проверьте срок ключа и доступ к Places API.');$body=json_decode((string)$raw,true);}
    if(!is_array($body)||(int)($body['meta']['code']??0)!==200||!is_array($body['result']['items']??null))throw new RuntimeException('2ГИС не вернул корректный результат. Проверьте ключ и запрос.');
    $items=[];foreach(array_slice($body['result']['items'],0,5) as $item){$id=explode('_',(string)($item['id']??''))[0];if(!preg_match('/^\d{5,30}$/D',$id)||empty($item['name']))continue;$reviews=$item['reviews']??[];$rating=$reviews['general_rating']??$reviews['rating']??null;$count=$reviews['general_review_count']??$reviews['review_count']??null;
        $items[]=['id'=>$id,'name'=>opsText($item['name'],300),'address'=>opsText($item['address_name']??'',500),'rating'=>is_numeric($rating)&&(float)$rating>=0&&(float)$rating<=5?(float)$rating:null,'review_count'=>is_numeric($count)&&(int)$count>=0?(int)$count:null,'url'=>'https://2gis.kz/firm/'.$id];}
    return ['items'=>$items,'fetched_at'=>opsNow(),'source'=>'2GIS Places API','review_text_api'=>false];
}
function gisConnect(PDO $pdo,int $uid,string $key,array $cfg,?callable $transport=null): array {
    $key=trim($key);if(!preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$key))throw new RuntimeException('Проверьте API-ключ 2ГИС.');
    gisRequest($key,'Тараз кафе',$transport);opsExec($pdo,'INSERT INTO gis_connections(user_id,key_box,active,checked_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE key_box=VALUES(key_box),active=1,checked_at=VALUES(checked_at)',[$uid,sealToken($key,$cfg),opsNow()]);return gisStatus($pdo,$uid);
}
function gisSearch(PDO $pdo,int $uid,string $query,array $cfg,?callable $transport=null): array {
    $query=opsText($query,150);if(mb_strlen($query)<3)throw new RuntimeException('Укажите город и название компании.');$r=opsOne($pdo,'SELECT key_box FROM gis_connections WHERE user_id=? AND active=1',[$uid]);if(!$r)throw new RuntimeException('Сначала подключите API-ключ 2ГИС.');opsRateLimit($pdo,'gis-search:'.$uid,100,86400);return gisRequest(openToken($r['key_box'],$cfg),$query,$transport);
}
function reviewManual(PDO $pdo,int $uid,array $input): array {
    $text=opsText($input['text']??'',8000);$url=opsText($input['url']??'',500);$u=parse_url($url);
    if(!$text||!is_array($u)||($u['scheme']??'')!=='https'||!in_array(strtolower($u['host']??''),['2gis.kz','2gis.ru','2gis.com','www.2gis.kz','www.2gis.ru','www.2gis.com'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port'])||!preg_match('~/firm/\d+~',$u['path']??''))throw new RuntimeException('Вставьте текст отзыва и HTTPS-ссылку на карточку компании в 2ГИС.');
    $rating=(int)opsNumber($input['rating']??0,1,5);$key=hash('sha256',$url.'|'.$text);
    return opsTx($pdo,$uid,function()use($pdo,$uid,$text,$url,$rating,$key){foreach(opsRecords($pdo,$uid,'review_case') as $r)if(($r['data']['import_key']??'')===$key)return ['id'=>$r['id'],'duplicate'=>true];$id=opsRecord($pdo,$uid,'review_case',['name'=>'Отзыв из 2ГИС · ручной ввод','source'=>'manual_2gis','source_url'=>$url,'import_key'=>$key,'is_demo'=>false,'rating'=>$rating,'author'=>'Отзыв, добавленный владельцем','text'=>$text,'state'=>'new']);return ['id'=>$id];});
}
