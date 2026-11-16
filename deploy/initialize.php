<?php
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
$cfg=appConfig();$pdo=appDatabase();appSchema($pdo);
$input=json_decode(file_get_contents('/run/aulieata/owner.json'),true,512,JSON_THROW_ON_ERROR);
$phone=$input['phone'];
if(!preg_match('/^\+7\d{10}$/D',$phone)||strlen($input['password'])<20)throw new RuntimeException('Invalid owner credentials');
$owner=opsOne($pdo,'SELECT * FROM users WHERE phone=?',[$phone]);
if(!$owner){
    opsExec($pdo,'INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)',[$phone,password_hash($input['password'],PASSWORD_DEFAULT),'AulieAta AI','it','Тараз']);
    $uid=(int)$pdo->lastInsertId();
    businessSaveProfile($pdo,$uid,['type'=>'it','description'=>'AulieAta AI — платформа управления малым бизнесом: каталог, клиенты, заказы, сотрудники, Telegram и подготовка маркетингового контента.','rules'=>'Это рабочее пространство проекта AulieAta AI. Цены, наличие и услуги добавляет владелец. Не обещайте оплату, заказ или запись без подтверждения системы.']);
    opsRecord($pdo,$uid,'knowledge',['name'=>'О платформе AulieAta AI','text'=>'AulieAta AI помогает предпринимателю управлять каталогом, клиентами, заказами, остатками, расписанием и сотрудниками. ИИ помогает с текстами, голосовыми запросами, импортом данных и контентом. Для вопросов владельцу напишите «Нужен сотрудник».','audience'=>'public']);
    opsSaveSettings($pdo,$uid,['bot_mode'=>'ai','bot_voice_enabled'=>true,'orders_enabled'=>false,'bookings_enabled'=>false]);
    $owner=opsOne($pdo,'SELECT * FROM users WHERE id=?',[$uid]);
}
$uid=(int)$owner['id'];
echo 'Owner workspace: '.$uid.PHP_EOL;
if(($argv[1]??'')!=='telegram')exit;
$token=$cfg['telegram_token'];$bot=telegramCall($token,'getMe');
$old=opsOne($pdo,'SELECT * FROM aulieata_connections WHERE user_id=?',[$uid]);
$route=$old['route_key']??bin2hex(random_bytes(24));$secret=$old?openToken($old['secret_box'],$cfg):bin2hex(random_bytes(32));
$url=appUrl('telegram.php').'?connection='.$route;
$info=telegramCall($token,'getWebhookInfo');
if(!empty($info['url'])&&$info['url']!==$url)throw new RuntimeException('Bot is attached to a different webhook; existing route preserved');
opsExec($pdo,'INSERT INTO aulieata_connections(user_id,bot_id,username,token_box,route_key,secret_box,active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE token_box=VALUES(token_box),secret_box=VALUES(secret_box),active=1',[$uid,$bot['id'],$bot['username'],sealToken($token,$cfg),$route,sealToken($secret,$cfg)]);
telegramCall($token,'setWebhook',['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>'["message"]','max_connections'=>2]);
$check=telegramCall($token,'getWebhookInfo');
if(($check['url']??'')!==$url)throw new RuntimeException('Webhook not confirmed');
echo 'Telegram @'.$bot['username'].' connected; pending: '.($check['pending_update_count']??0).PHP_EOL;
