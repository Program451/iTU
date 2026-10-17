<?php
require_once __DIR__.'/catalog-starters.php';
function catalogDataSchema($pdo){
    static $ready=false;if($ready)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_catalog_details(user_id INT NOT NULL,item_id INT NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(user_id,item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_catalog_templates(business_type VARCHAR(40) PRIMARY KEY,version VARCHAR(30) NOT NULL,payload MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $types=$pdo->query('SELECT business_type FROM aulieata_catalog_templates')->fetchAll(PDO::FETCH_COLUMN);
    $insert=$pdo->prepare('INSERT IGNORE INTO aulieata_catalog_templates(business_type,version,payload) VALUES(?,?,?)');
    foreach(catalogStarterDefinitions() as $type=>$rows)if(!in_array($type,$types,true))$insert->execute([$type,'2026-10-v1',json_encode($rows,JSON_UNESCAPED_UNICODE)]);
    $ready=true;
}
function catalogDetails($d,$type){
    $status=$d['availability']??'unknown';$mode=$d['data_mode']??'live';
    if(!in_array($status,['available','out_of_stock','preorder','unknown'],true)||!in_array($mode,['demo','live'],true))throw new RuntimeException('Проверьте наличие и тип данных');
    $kind=$d['kind']??'product';$inventory=$d['inventory_mode']??'untracked';if(!in_array($kind,['product','service'],true)||!in_array($inventory,['untracked','stock','recipe'],true))throw new RuntimeException('Проверьте тип и учёт позиции');$duration=(int)($d['duration_minutes']??30);if($duration<5||$duration>480)throw new RuntimeException('Длительность: от 5 до 480 минут');$recipe=[];foreach(array_slice(is_array($d['recipe']??null)?$d['recipe']:[],0,30) as $p){$qty=filter_var($p['quantity']??0,FILTER_VALIDATE_FLOAT);if($qty===false||$qty<0.001||$qty>10000||(int)($p['item_id']??0)<1)throw new RuntimeException('Проверьте ингредиенты и расход на одну порцию');$recipe[]=['item_id'=>(int)$p['item_id'],'quantity'=>round($qty,3)];}if($inventory==='recipe'&&!$recipe)throw new RuntimeException('Добавьте хотя бы один ингредиент');
    return ['sku'=>mb_substr(trim((string)($d['sku']??'')),0,80),'kind'=>$kind,'inventory_mode'=>$inventory,'duration_minutes'=>$duration,'recipe'=>$inventory==='recipe'?$recipe:[],'availability'=>$status,'data_mode'=>$mode,'description'=>mb_substr(trim((string)($d['description']??'')),0,700),'unit'=>mb_substr(trim((string)($d['unit']??'')),0,80),'aliases'=>mb_substr(trim((string)($d['aliases']??'')),0,400),'business_type'=>$type];
}
function saveCatalogDetails($pdo,$uid,$id,$details){
    $q=$pdo->prepare('INSERT INTO aulieata_catalog_details(user_id,item_id,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');$q->execute([$uid,$id,json_encode($details,JSON_UNESCAPED_UNICODE)]);
}
function attachCatalogDetails($pdo,$uid,$rows){
    catalogDataSchema($pdo);$q=$pdo->prepare('SELECT item_id,payload FROM aulieata_catalog_details WHERE user_id=?');$q->execute([$uid]);$map=[];foreach($q->fetchAll() as $r)$map[(int)$r['item_id']]=json_decode($r['payload'],true)?:[];
    foreach($rows as &$r)$r['details']=$map[(int)$r['id']]??['availability'=>'unknown','data_mode'=>'live','description'=>'','unit'=>'','aliases'=>'','business_type'=>''];return $rows;
}
function publicCatalogData($pdo,$uid,$query='',$type=null){
    $type=$type??businessProfile($pdo,$uid)['type'];
    $q=$pdo->prepare('SELECT c.id,c.item_name,c.item_price,c.category FROM business_catalog c LEFT JOIN aulieata_catalog_status a ON a.user_id=c.user_id AND a.item_id=c.id WHERE c.user_id=? AND COALESCE(a.archived,0)=0 ORDER BY c.id DESC');$q->execute([$uid]);$rows=attachCatalogDetails($pdo,$uid,$q->fetchAll());
    $terms=array_slice(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($query),-1,PREG_SPLIT_NO_EMPTY),0,60);$out=[];
    foreach($rows as $r){$d=$r['details'];if(!empty($d['business_type'])&&$d['business_type']!==$type)continue;$hay=mb_strtolower($r['item_name'].' '.$r['category'].' '.($d['aliases']??''));$score=0;foreach($terms as $t)if(mb_strlen($t)>2&&mb_strpos($hay,$t)!==false)$score++;$out[]=['id'=>(int)$r['id'],'item_name'=>$r['item_name'],'item_price'=>(float)$r['item_price'],'currency'=>'KZT','category'=>$r['category'],'unit'=>$d['unit']??'','description'=>$d['description']??'','aliases'=>$d['aliases']??'','availability'=>$d['availability']??'unknown','data_mode'=>$d['data_mode']??'live','_score'=>$score];}
    usort($out,fn($a,$b)=>$b['_score']<=>$a['_score']);$out=array_slice($out,0,120);foreach($out as &$r)unset($r['_score']);unset($r);return function_exists('opsPublicAvailability')?opsPublicAvailability($pdo,$uid,$out):$out;
}
function catalogStarterRows($pdo,$type){
    catalogDataSchema($pdo);$q=$pdo->prepare('SELECT payload FROM aulieata_catalog_templates WHERE business_type=?');$q->execute([$type]);$rows=json_decode($q->fetchColumn()?:'[]',true);$out=[];
    foreach($rows as $i=>$r)$out[]=['key'=>$type.':'.$i,'item_name'=>$r[0],'item_price'=>$r[1],'category'=>$r[2],'details'=>catalogDetails(['unit'=>$r[3],'description'=>$r[4],'aliases'=>$r[5],'availability'=>$r[6]??'available','data_mode'=>'demo'],$type)];return $out;
}
