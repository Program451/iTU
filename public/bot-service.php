<?php
/** Deterministic customer service: no external model, no invented business facts. */
function botWords(string $text): array {
    return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($text))?:[],fn($s)=>mb_strlen($s)>2));
}
function botKk(string $text): bool { return (bool)preg_match('/[әғқңөұүһі]|салам|сәлем/iu',$text); }
function botMatches(array $catalog,string $text): array {
    $query=mb_strtolower($text);$words=botWords($text);$found=[];
    foreach($catalog as $item){
        $aliases=$item['aliases']??[];if(!is_array($aliases))$aliases=preg_split('/[,;\n]+/u',(string)$aliases)?:[];
        $names=array_merge([(string)$item['item_name']],$aliases);$score=0;
        foreach($names as $name){$name=mb_strtolower(trim((string)$name));if($name!==''&&mb_strpos($query,$name)!==false)$score=max($score,10+mb_strlen($name));}
        $body=mb_strtolower(implode(' ',$names).' '.($item['category']??''));
        foreach($words as $word)if(mb_strpos($body,$word)!==false)$score++;
        if($score>0)$found[]=['item'=>$item,'score'=>$score];
    }
    usort($found,fn($a,$b)=>$b['score']<=>$a['score']);return array_column($found,'item');
}
function botCatalogText(array $items,bool $kk=false): string {
    if(!$items)return $kk?'Каталог әлі толтырылмаған. «Қызметкер» деп жазыңыз.':'Каталог ещё не заполнен. Напишите «Нужен сотрудник».';
    $lines=[];$demo=false;
    foreach(array_slice($items,0,14) as $item){
        $isDemo=($item['data_mode']??'demo')==='demo';$demo=$demo||$isDemo;
        $status=($kk?['available'=>'қолжетімді','out_of_stock'=>'жоқ','preorder'=>'алдын ала тапсырыс','unknown'=>'қолда барын нақтылау керек']:['available'=>'доступно','out_of_stock'=>'нет в наличии','preorder'=>'под заказ','unknown'=>'наличие уточняется'])[$item['availability']??'unknown']??'';
        $lines[]=($isDemo?($kk?'[Үлгі] ':'[Пример] '):'').$item['item_name'].' — '.number_format((float)$item['item_price'],2,',',' ').' ₸ / '.($item['unit']??'шт.').' · '.$status;
    }
    if($demo)$lines[]=$kk?'Үлгі бағалар мен қолжетімділік нақты ұсыныс емес.':'Демонстрационные цены и наличие — примеры, а не реальное предложение.';
    return implode("\n",$lines);
}
function botQuickAnswer($pdo,int $uid,string $text,array $catalog): ?string {
    $kk=botKk($text);$t=trim(mb_strtolower($text));
    if(preg_match('/^(?:привет|здравствуй(?:те)?|добр(?:ый|ы)\s+(?:день|вечер)|доброе утро|салам|сәлем(?:етсіз бе)?|саламатсыз ба|ассалаумағалейкум)[.!?\s]*$/u',$t))return $kk?'Сәлеметсіз бе! Баға мен каталогты көрсетемін, тапсырысқа көмектесемін. Не қажет? «Мәзір» немесе «Қызметкер» деп жаза аласыз.':'Здравствуйте! Помогу с ассортиментом, ценами и заказом. Что вас интересует? Можно написать «Меню» или «Нужен сотрудник».';
    if(preg_match('/^(?:спасибо|рахмет|благодарю)[.!\s]*$/u',$t))return $kk?'Рақмет! Тағы сұрағыңыз болса, жазыңыз.':'Пожалуйста! Обращайтесь, если понадобится помощь.';
    if(preg_match('/^(?:меню|каталог|прайс|м[әе]з[іи]р|ассортимент|что у вас есть|покажи(?:те)? (?:меню|каталог))[.!?\s]*$/u',$t))return botCatalogText($catalog,$kk);
    // Business knowledge is public-only and matched by its question/title, never private notes.
    $words=botWords($text);$best=null;$bestScore=0;
    foreach(businessKnowledge($pdo,$uid,true,$text) as $doc){$name=mb_strtolower($doc['name']);$score=0;foreach($words as $w)if(mb_strpos($name,$w)!==false)$score++;if($score>$bestScore){$best=$doc;$bestScore=$score;}}
    if($best&&($bestScore>=2||mb_strtolower(trim($best['name']))===$t))return opsText($best['text'],3000);
    $matches=botMatches($catalog,$text);
    if($matches&&!preg_match('/закаж|заказ|заказать|оформ|купить|брон|запис|тапсырыс|жазыл|сатып/iu',$text))return botCatalogText(array_slice($matches,0,5),$kk);
    return null;
}
function botLocalIntent(array $catalog,string $text): ?array {
    if(preg_match('/сотрудник|оператор|администратор|человек|қызметкер|менеджер/iu',$text))return ['intent'=>'handoff'];
    if(preg_match('/брон|запис|жазыл/iu',$text))return ['intent'=>'booking','clarification'=>botKk($text)?'Қызметкер жазылуға көмектеседі. «Қызметкер» деп жазыңыз.':'Для записи со свободным временем напишите «Нужен сотрудник». Он увидит запрос в кабинете.'];
    if(!preg_match('/заказ|закаж|заказать|хочу купить|оформ|тапсырыс|сатып ал/iu',$text))return null;
    $matches=botMatches($catalog,$text);$exact=[];$lower=mb_strtolower($text);
    foreach($matches as $m){$name=mb_strtolower($m['item_name']);if(mb_strpos($lower,$name)!==false)$exact[]=$m;}
    $message=botKk($text)?'Бір тауардың нақты атауын және санын жазыңыз. Мысалы: «Тапсырыс: Латте, 2».':'Напишите точное название одной позиции и количество. Например: «Заказ: Латте, 2». Несколько позиций оформляйте отдельно или через сотрудника.';
    if(count($exact)!==1)return ['intent'=>'order','clarification'=>$message,'items'=>[]];
    $numbers=[];preg_match_all('/(?<![\p{L}\d])\d+(?:[.,]\d+)?(?![\p{L}\d])/u',$text,$numbers);
    if(count($numbers[0])!==1)return ['intent'=>'order','clarification'=>$message,'items'=>[]];
    $qty=(float)str_replace(',','.',$numbers[0][0]);if($qty<1||$qty>100||floor($qty)!==$qty)return ['intent'=>'order','clarification'=>$message,'items'=>[]];
    return ['intent'=>'order','items'=>[['item_id'=>(int)$exact[0]['id'],'quantity'=>$qty]]];
}
function botUnavailable(bool $kk=false): string {return $kk?'Сұрақты нақтылап жазыңыз немесе «Мәзір», «Тапсырыс: тауар, саны», «Қызметкер» деп жазыңыз.':'Уточните вопрос или напишите «Меню», «Заказ: название, количество», «Нужен сотрудник». Сложный вопрос можно передать сотруднику.';}
