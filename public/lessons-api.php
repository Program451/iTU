<?php
require_once __DIR__.'/database.php';require_once __DIR__.'/lessons.php';appSessionStart();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function lessonResponse(array $body,int $code=200): void {http_response_code($code);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
    if(empty($_SESSION['user_id'])&&empty($_SESSION['admin_id']))lessonResponse(['error'=>'Войдите в аккаунт.'],401);
    if($_SERVER['REQUEST_METHOD']!=='POST')lessonResponse(['error'=>'Метод недоступен.'],405);
    if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))lessonResponse(['error'=>'Обновите страницу и повторите действие.'],403);
    $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))lessonResponse(['error'=>'Проверьте запрос.'],400);
    $pdo=appDatabase();lessonSchema($pdo);$action=$in['action']??'';$admin=announcementAdmin($pdo);
    if(str_starts_with($action,'admin_')){
        if(!$admin)lessonResponse(['error'=>'Раздел доступен администратору.'],403);
        $aid=(int)$admin['id'];
        if($action==='admin_list')lessonResponse(['items'=>lessonList($pdo)]);
        if($action==='admin_save'){ $id=lessonSave($pdo,$aid,$in);lessonResponse(['id'=>$id,'items'=>lessonList($pdo)]); }
        if(in_array($action,['admin_publish','admin_withdraw'],true)){lessonSetStatus($pdo,$aid,(int)($in['id']??0),$action==='admin_publish'?'published':'withdrawn',(int)($in['revision']??0));lessonResponse(['items'=>lessonList($pdo)]);}
    }else{
        if(empty($_SESSION['user_id']))lessonResponse(['error'=>'Войдите в рабочее пространство.'],403);
        if(!empty($_SESSION['staff_id'])){require_once __DIR__.'/operations.php';opsStaffGate($pdo,(int)$_SESSION['user_id'],'load',[]);}
        $subject=announcementSubject();
        if($action==='list')lessonResponse(['items'=>lessonList($pdo,$subject)]);
        if($action==='complete'){lessonComplete($pdo,$subject,(int)($in['id']??0),($in['completed']??false)===true);lessonResponse(['ok'=>true]);}
    }lessonResponse(['error'=>'Действие недоступно.'],400);
}catch(DomainException $e){lessonResponse(['error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Lesson library unavailable: '.get_class($e));lessonResponse(['error'=>'Не удалось загрузить уроки. Попробуйте позже.'],503);}
