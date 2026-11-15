<?php
/** Server-supplied Gemini access is opt-in for explicitly listed workspaces. */
function managedAiAllowed(int $uid, array $cfg): bool {
    return !empty($cfg['gemini_key']) && in_array($uid,array_map('intval',$cfg['gemini_media_users']??[]),true);
}
function imageAiConnection(PDO $pdo,int $uid,?array $cfg=null): ?array {
    $row=opsOne($pdo,'SELECT * FROM image_ai_connections WHERE user_id=? AND active=1',[$uid]);
    if($row)return $row+['provider'=>'cloudflare'];
    $cfg??=appConfig();
    if(!managedAiAllowed($uid,$cfg))return null;
    $setting=opsOne($pdo,"SELECT payload FROM aulieata_records WHERE user_id=? AND kind='managed_ai' AND archived=0 ORDER BY id DESC LIMIT 1",[$uid]);
    if($setting && empty(json_decode($setting['payload'],true)['enabled']))return null;
    $account=substr(hash('sha256','gemini:'.$cfg['gemini_key']),0,32);
    $model=$cfg['gemini_image_model']??'gemini-3.1-flash-lite-image';
    return ['provider'=>'gemini','account_id'=>$account,'active'=>1,'checked_at'=>null,'revision'=>substr(hash('sha256',$account.$model),0,32)];
}
function managedAiImage(array $cfg,string $prompt): array {
    $model=$cfg['gemini_image_model']??'gemini-3.1-flash-lite-image';
    if(!preg_match('/^[a-zA-Z0-9._-]+$/D',$model))throw new RuntimeException('Проверьте модель изображений Gemini.');
    $body=['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseModalities'=>['TEXT','IMAGE']]];
    $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent');$raw='';
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$cfg['gemini_key']],
        CURLOPT_WRITEFUNCTION=>function($ch,$s)use(&$raw){if(strlen($raw)+strlen($s)>12000000)return 0;$raw.=$s;return strlen($s);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($status===429)throw new RuntimeException('Квота изображений Gemini исчерпана. Повторите позже.');
    if(!$ok||$status!==200)throw new RuntimeException('Gemini не подтвердил создание изображения. Автоматического повтора нет.');
    $data=json_decode($raw,true);
    foreach($data['candidates'][0]['content']['parts']??[] as $part){$image=$part['inlineData']??[];if(in_array($image['mimeType']??'',['image/png','image/jpeg'],true)&&!empty($image['data']))return ['image'=>$image['data']];}
    throw new RuntimeException('Gemini не вернул изображение. Уточните описание сцены.');
}
