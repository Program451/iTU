<?php
if(!isset($pdo,$uid,$in,$action)){http_response_code(404);exit;}
$opsRole=opsStaffGate($pdo,$uid,$action,$in);
if(str_starts_with($action,'ops_')){
    $d=$in['data']??$in;if(!is_array($d))throw new RuntimeException('Проверьте форму');
    if(in_array($action,['ops_transcribe','ops_owner_plan','ops_supplier_parse','ops_shift_parse','ops_budget'])){opsRateLimit($pdo,'ai:'.$uid,100,3600);session_write_close();}
    if($action==='ops_load'){
        $decode=function($rows){foreach($rows as &$r){$r['data']=json_decode($r['payload'],true)?:[];unset($r['payload']);}return $rows;};
        $orders=$decode(opsAll($pdo,'SELECT * FROM ops_orders WHERE user_id=? ORDER BY id DESC LIMIT 300',[$uid]));if($opsRole==='operator')foreach($orders as &$o){unset($o['data']['cost'],$o['data']['allocations']);}
        $audit=$opsRole==='operator'?[]:$decode(opsAll($pdo,'SELECT * FROM ops_audit WHERE user_id=? ORDER BY id DESC LIMIT 100',[$uid]));
        $result=['role'=>$opsRole,'orders'=>$orders,'resources'=>$decode(opsAll($pdo,'SELECT * FROM ops_resources WHERE user_id=? ORDER BY id',[$uid])),'appointments'=>$decode(opsAll($pdo,'SELECT * FROM ops_appointments WHERE user_id=? ORDER BY starts_at DESC LIMIT 500',[$uid])),'tickets'=>opsRecords($pdo,$uid,'ops_ticket'),'shifts'=>opsRecords($pdo,$uid,'ops_shift'),'passports'=>opsRecords($pdo,$uid,'ops_passport'),'staff'=>opsAll($pdo,'SELECT id,name,role,active,login FROM ops_staff WHERE user_id=? ORDER BY id',[$uid]),'settings'=>opsSettings($pdo,$uid),'audit'=>$audit,'batches'=>$opsRole==='operator'?[]:opsBatchRows($pdo,$uid),'suppliers'=>$opsRole==='operator'?[]:opsRecords($pdo,$uid,'ops_supplier'),'campaigns'=>opsRecords($pdo,$uid,'ops_campaign'),'imports'=>$opsRole==='operator'?[]:opsRecords($pdo,$uid,'ops_import'),'plans'=>$opsRole==='owner'?opsRecords($pdo,$uid,'ops_plan'):[],'demands'=>$opsRole==='operator'?[]:opsAll($pdo,'SELECT id,source_id,reason,topic,excerpt,created_at FROM ops_demands WHERE user_id=? ORDER BY id DESC LIMIT 500',[$uid]),'outbox'=>$opsRole==='owner'?opsAll($pdo,'SELECT id,status,due_at,ref_key FROM ops_outbox WHERE user_id=? ORDER BY id DESC LIMIT 100',[$uid]):[]];out($result);
    }
    if($action==='ops_bot_preview'){
        $text=opsText($d['text']??'',1000);if(!$text)throw new RuntimeException('Напишите вопрос');
        $catalog=publicCatalogData($pdo,$uid,$text);$reply=botQuickAnswer($pdo,$uid,$text,$catalog);
        if($reply===null&&(opsSettings($pdo,$uid)['bot_mode']??'free')==='cloud'){opsRateLimit($pdo,'bot-preview:'.$uid,20,3600);session_write_close();$intent=botCloudIntent($pdo,$uid,$text,[],$catalog,[],[],$runtimeConfig);if(($intent['intent']??'')==='order'&&!empty($intent['items']))$reply=opsQuoteText(opsQuote($pdo,$uid,$intent['items']),botKk($text))."\nПредпросмотр: заказ не создавался.";elseif(($intent['intent']??'')==='handoff')$reply='Бот предложит помощь сотрудника. В предпросмотре обращение не создаётся.';else $reply=opsText($intent['answer']??$intent['clarification']??'',2400)?:'Бот уточнит данные. Запись и заказ в предпросмотре не создаются.';}
        out(['text'=>$reply??'Для действий с заказом или записи используйте Telegram. Здесь проверяются только справочные ответы без создания записей. '.botUnavailable(botKk($text))]);
    }
    if($action==='ops_bot_health'){
        opsRateLimit($pdo,'bot-health:'.$uid,20,3600);
        $conn=opsOne($pdo,'SELECT token_box,route_key FROM aulieata_connections WHERE user_id=? AND active=1',[$uid]);
        if(!$conn)throw new RuntimeException('Подключите Telegram в настройках.');
        $info=telegramCall(openToken($conn['token_box'],$runtimeConfig),'getWebhookInfo');
        $expected=appUrl('telegram.php?connection='.$conn['route_key']);$ok=hash_equals($expected,(string)($info['url']??''));
        $text=$ok?'Webhook подключён к этому бизнесу.':'Webhook отличается от адреса этого бизнеса. Переподключите бота в настройках.';
        $text.=' Ожидают доставки: '.(int)($info['pending_update_count']??0).'.';
        if(!empty($info['last_error_date'])&&(int)$info['last_error_date']>time()-3600)$text.=' За последний час Telegram сообщил об ошибке доставки. Проверьте журнал хостинга.';
        out(['ok'=>$ok,'text'=>$text]);
    }
    if($action==='ops_settings_save')out(['settings'=>opsSaveSettings($pdo,$uid,$d)]);
    if($action==='ops_quote'){$q=opsQuote($pdo,$uid,$d['items']??[],$d['campaign_code']??'');unset($q['allocations'],$q['cost']);out($q);}
    if($action==='ops_order_create')out(opsCreateOrder($pdo,$uid,$d));
    if($action==='ops_order_status'){if(($d['status']??'')==='refunded'&&$opsRole==='operator')throw new RuntimeException('Возврат отмечает руководитель');out(opsOrderStatus($pdo,$uid,(int)$d['id'],$d['status']??'',!empty($d['restock'])));}
    if($action==='ops_resource_save')out(opsSaveResource($pdo,$uid,(int)($d['id']??0),$d));
    if($action==='ops_slots')out(['slots'=>opsSlots($pdo,$uid,(int)$d['resource_id'],(int)$d['item_id'],$d['date']??'')]);
    if($action==='ops_appointment_save')out(opsAppointmentSave($pdo,$uid,$d));
    if($action==='ops_appointment_status')out(opsAppointmentStatus($pdo,$uid,(int)$d['id'],$d['status']??''));
    if($action==='ops_transcribe')out(['text'=>opsTranscribe($runtimeConfig,$d['audio']??'')]);
    if($action==='ops_budget')out(['options'=>opsBudget($pdo,$uid,$d,$runtimeConfig)]);
    if($action==='ops_scenario')out(opsScenario($pdo,$uid,$d));
    if($action==='ops_supplier_parse')out(opsSupplierParse($runtimeConfig,$d));
    if($action==='ops_supplier_save')out(opsSupplierSave($pdo,$uid,$d));
    if($action==='ops_supplier_compare')out(['offers'=>opsCompareSuppliers($pdo,$uid,$d['match_key']??'',$d['unit']??'',$d['quantity']??0)]);
    if($action==='ops_supplier_draft')out(opsSupplierDraft($pdo,$uid,$d));
    if($action==='ops_shift_parse')out(opsShiftParse($runtimeConfig,$d['text']??''));
    if($action==='ops_shift_save')out(opsShiftSave($pdo,$uid,$d));
    if($action==='ops_shift_task')out(opsShiftTask($pdo,$uid,(int)$d['id'],(int)$d['index'],!empty($d['done'])));
    if($action==='ops_ticket_update')out(opsTicketUpdate($pdo,$uid,(int)$d['id'],$d));
    if($action==='ops_ticket_reply')out(opsTicketReply($pdo,$uid,(int)$d['id'],$d['text']??'',$runtimeConfig));
    if($action==='ops_passport_save')out(opsPassportSave($pdo,$uid,$d));
    if($action==='ops_passport_event')out(opsPassportEvent($pdo,$uid,(int)$d['id'],$d));
    if($action==='ops_link'){$kind=$d['kind']??'';$id=(int)($d['id']??0);if($kind==='passport')opsGetRecord($pdo,$uid,$id,'ops_passport');$token=opsLink($pdo,$uid,$kind,$id,!empty($d['rotate']));out(['url'=>appUrl('portal.php').'?token='.$token]);}
    if($action==='ops_owner_plan')out(opsOwnerPlan($pdo,$uid,$d['text']??'',$runtimeConfig));
    if($action==='ops_owner_apply')out(opsOwnerApply($pdo,$uid,(int)$d['id'],$d['selected']??[]));
    if($action==='ops_owner_code'){$code=(string)random_int(10000000,99999999);opsExec($pdo,"UPDATE aulieata_records SET archived=1 WHERE user_id=? AND kind='ops_owner_code'",[$uid]);opsRecord($pdo,$uid,'ops_owner_code',['hash'=>hash('sha256',$code),'expires'=>time()+600]);out(['code'=>$code,'expires_in'=>600]);}
    if($action==='ops_import_preview')out(opsImportPreview($pdo,$uid,$d));
    if($action==='ops_import_apply')out(opsImportApply($pdo,$uid,(int)$d['id']));
    if($action==='ops_undo')out(opsUndo($pdo,$uid,(int)$d['id']));
    if($action==='ops_report')out(opsReport($pdo,$uid,$d['from']??localBusinessDay(),$d['to']??localBusinessDay()));
    if($action==='ops_campaign_save')out(opsTx($pdo,$uid,fn()=>opsSaveCampaign($pdo,$uid,$d)));
    if($action==='ops_staff_invite'){$name=opsText($d['name']??'',100);$role=$d['role']??'operator';if(!$name||!in_array($role,['operator','manager']))throw new RuntimeException('Укажите имя и роль');$token=bin2hex(random_bytes(32));opsExec($pdo,'INSERT INTO ops_staff(user_id,name,role,invite_hash,invite_expires) VALUES(?,?,?,?,?)',[$uid,$name,$role,hash('sha256',$token),(new DateTimeImmutable(opsNow()))->modify('+3 days')->format('Y-m-d H:i:s')]);$id=(int)$pdo->lastInsertId();opsAudit($pdo,$uid,'staff_invite',$id,['name'=>$name,'role'=>$role]);out(['id'=>$id,'url'=>appUrl('staff.php').'?invite='.$token]);}
    if($action==='ops_staff_revoke'){opsExec($pdo,'UPDATE ops_staff SET active=0,invite_hash=NULL WHERE id=? AND user_id=?',[(int)$d['id'],$uid]);opsAudit($pdo,$uid,'staff_revoke',(int)$d['id'],[]);out(['ok'=>true]);}
    throw new RuntimeException('Неизвестное действие');
}
// Keep reservations safe when editing or archiving pre-existing resource forms.
if($action==='resource_save'&&($in['kind']??'')==='stock_batches')out(opsSaveBatch($pdo,$uid,(int)($in['id']??0),$in['data']??[]));
if($action==='archive'){out(opsTx($pdo,$uid,function()use($pdo,$uid,$in){$id=(int)($in['id']??0);$r=opsOne($pdo,'SELECT kind FROM aulieata_records WHERE user_id=? AND id=?',[$uid,$id]);if(!$r)throw new RuntimeException('Запись не найдена');if($r['kind']==='stock_batches'&&opsOne($pdo,'SELECT order_id FROM ops_reservations WHERE user_id=? AND batch_id=? AND active=1 LIMIT 1',[$uid,$id]))throw new RuntimeException('Партия зарезервирована. Сначала отмените или завершите заказ.');if($r['kind']==='ops_passport')opsExec($pdo,"UPDATE ops_links SET active=0 WHERE user_id=? AND target_id=? AND kind='passport'",[$uid,$id]);opsExec($pdo,'UPDATE aulieata_records SET archived=1 WHERE user_id=? AND id=?',[$uid,$id]);opsExec($pdo,'UPDATE aulieata_market SET active=0 WHERE owner_id=? AND record_id=?',[$uid,$id]);return ['ok'=>true];}));}
if($action==='book')throw new RuntimeException('Используйте новое расписание с длительностью услуги');
if($action==='resource_save'&&($in['kind']??'')==='business_capacity'&&!empty($in['data']['item_id']))opsItem($pdo,$uid,(int)$in['data']['item_id']);
