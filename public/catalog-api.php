<?php
if(!isset($pdo,$uid,$in,$action)){http_response_code(404);exit;}
if($action==='catalog_starter_preview')out(['type'=>$businessProfile['type'],'label'=>$businessProfile['label'],'rows'=>catalogStarterRows($pdo,$businessProfile['type'])]);
if($action==='catalog_starter_apply'){
    if(($in['type']??'')!==$businessProfile['type'])out(['error'=>'Сфера изменилась. Обновите набор.'],409);
    $keys=$in['keys']??[];if(!is_array($keys)||!count($keys)||count($keys)>100)out(['error'=>'Выберите позиции'],422);
    $rows=catalogStarterRows($pdo,$businessProfile['type']);$known=array_column($rows,'key');foreach($keys as $key)if(!is_string($key)||!in_array($key,$known,true))out(['error'=>'Позиция не относится к этому набору'],422);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$uid]);
    if(businessProfile($pdo,$uid)['type']!==$in['type']){$pdo->rollBack();out(['error'=>'Сфера изменилась. Обновите набор.'],409);}
    $q=$pdo->prepare('SELECT c.item_name FROM business_catalog c LEFT JOIN aulieata_catalog_status a ON a.user_id=c.user_id AND a.item_id=c.id WHERE c.user_id=? AND COALESCE(a.archived,0)=0');$q->execute([$uid]);$seen=array_map(fn($n)=>mb_strtolower(trim($n)),$q->fetchAll(PDO::FETCH_COLUMN));$ids=[];$skipped=0;
    $add=$pdo->prepare('INSERT INTO business_catalog(user_id,item_name,item_price,item_cost,units_sold,category) VALUES(?,?,?,0,0,?)');
    foreach($rows as $row){if(!in_array($row['key'],$keys,true))continue;$name=mb_strtolower(trim($row['item_name']));if(in_array($name,$seen,true)){$skipped++;continue;}$add->execute([$uid,$row['item_name'],$row['item_price'],$row['category']]);$id=(int)$pdo->lastInsertId();saveCatalogDetails($pdo,$uid,$id,$row['details']);$ids[]=$id;$seen[]=$name;}
    $pdo->commit();out(['ok'=>true,'count'=>count($ids),'skipped'=>$skipped,'ids'=>$ids]);
}
if($action==='catalog'||$action==='edit_catalog'){
    $name=s($in['item_name']??'',180);$price=filter_var($in['item_price']??null,FILTER_VALIDATE_FLOAT);$cost=filter_var($in['item_cost']??0,FILTER_VALIDATE_FLOAT);$qty=filter_var($in['units_sold']??0,FILTER_VALIDATE_INT);
    if(!$name||$price===false||$price<=0||$price>1000000000||$cost===false||$cost<0||$cost>1000000000||$qty===false||$qty<0||$qty>100000000)out(['error'=>'Проверьте название, цену и количество продаж.'],422);
    $id=(int)($in['id']??0);$pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$uid]);
    $old=[];if($action==='edit_catalog'){$q=$pdo->prepare('SELECT c.id,d.payload FROM business_catalog c LEFT JOIN aulieata_catalog_details d ON d.user_id=c.user_id AND d.item_id=c.id WHERE c.id=? AND c.user_id=? FOR UPDATE');$q->execute([$id,$uid]);$found=$q->fetch();if(!$found){$pdo->rollBack();out(['error'=>'Позиция не найдена'],404);}$old=json_decode($found['payload']??'{}',true)?:[];}
    $before=$action==='edit_catalog'?opsItemSnapshot(opsItem($pdo,$uid,$id)):null;$details=catalogDetails(array_merge($old,$in),$businessProfile['type']);opsValidateCatalog($pdo,$uid,$id,$details);
    if($action==='edit_catalog'){$q=$pdo->prepare('UPDATE business_catalog SET item_name=?,item_price=?,item_cost=?,units_sold=?,category=? WHERE id=? AND user_id=?');$q->execute([$name,round($price,2),round($cost,2),$qty,s($in['category']??'Основное',80),$id,$uid]);}else{$q=$pdo->prepare('INSERT INTO business_catalog(user_id,item_name,item_price,item_cost,units_sold,category) VALUES(?,?,?,?,?,?)');$q->execute([$uid,$name,round($price,2),round($cost,2),$qty,s($in['category']??'Основное',80)]);$id=(int)$pdo->lastInsertId();}
    saveCatalogDetails($pdo,$uid,$id,$details);opsAudit($pdo,$uid,'catalog_edit',$id,['before'=>$before,'after'=>opsItemSnapshot(opsItem($pdo,$uid,$id))]);$pdo->commit();out(['ok'=>true,'id'=>$id]);
}
