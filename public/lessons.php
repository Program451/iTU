<?php
/** The learning library is independent of one-time announcements. */
require_once __DIR__.'/announcements.php';
function lessonSchema(PDO $pdo): void {
    static $ready; $ready??=new WeakMap();if(isset($ready[$pdo]))return;
    announcementSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_lessons (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_id INT NOT NULL,revision INT NOT NULL DEFAULT 1,
        category VARCHAR(24) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'draft',sort_order INT NOT NULL DEFAULT 0,
        title_ru VARCHAR(180) NOT NULL,title_kk VARCHAR(180) NOT NULL DEFAULT '',body_ru TEXT NOT NULL,body_kk TEXT NOT NULL,
        media_id CHAR(32) NULL,published_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX library(status,sort_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_lesson_reads (subject VARCHAR(40) NOT NULL,lesson_id BIGINT UNSIGNED NOT NULL,
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(subject,lesson_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready[$pdo]=true;
}
function lessonCategories(): array {return ['general','cloudflare','gemini','instagram','tiktok','telegram'];}
function lessonList(PDO $pdo,?string $subject=null): array {
    if($subject===null)return $pdo->query('SELECT * FROM app_lessons ORDER BY sort_order,id DESC LIMIT 300')->fetchAll();
    $q=$pdo->prepare("SELECT l.id,l.category,l.title_ru,l.title_kk,l.body_ru,l.body_kk,l.media_id,l.published_at,l.sort_order,
        (r.lesson_id IS NOT NULL) AS completed FROM app_lessons l LEFT JOIN app_lesson_reads r ON r.lesson_id=l.id AND r.subject=?
        WHERE l.status='published' ORDER BY l.sort_order,l.id DESC LIMIT 300");$q->execute([$subject]);return $q->fetchAll();
}
function lessonSave(PDO $pdo,int $admin,array $in): int {
    $category=$in['category']??'general';if(!in_array($category,lessonCategories(),true))throw new DomainException('Выберите тему урока.');
    $v=[];foreach(['title_ru'=>180,'title_kk'=>180,'body_ru'=>10000,'body_kk'=>10000] as $key=>$max){$value=$in[$key]??'';if(!is_string($value)||mb_strlen($value)>$max)throw new DomainException('Текст урока слишком длинный.');$v[$key]=trim($value);}
    if($v['title_ru']==='')throw new DomainException('Добавьте название урока на русском языке.');
    $order=filter_var($in['sort_order']??0,FILTER_VALIDATE_INT);if($order===false||$order<0||$order>9999)throw new DomainException('Порядок урока: от 0 до 9999.');
    $media=$in['media_id']??null;if($media!==null&&$media!==''){
        if(!is_string($media)||!preg_match('/^[a-f0-9]{32}$/D',$media))throw new DomainException('Видео недоступно.');
        $q=$pdo->prepare('SELECT id FROM app_announcement_media WHERE id=? AND admin_id=?');$q->execute([$media,$admin]);if(!$q->fetchColumn())throw new DomainException('Видео недоступно.');
    }
    $id=(int)($in['id']??0);$pdo->beginTransaction();
    try{
        if($id){$q=$pdo->prepare('SELECT status,revision FROM app_lessons WHERE id=? AND admin_id=? FOR UPDATE');$q->execute([$id,$admin]);$old=$q->fetch();
            if(!$old||$old['status']==='published'||(int)$old['revision']!==(int)($in['revision']??0))throw new DomainException('Урок изменился. Обновите список; опубликованный урок можно скопировать.');
            $q=$pdo->prepare("UPDATE app_lessons SET category=?,sort_order=?,title_ru=?,title_kk=?,body_ru=?,body_kk=?,media_id=?,status='draft',revision=revision+1 WHERE id=? AND admin_id=?");
            $q->execute([$category,$order,...array_values($v),$media?:null,$id,$admin]);
        }else{
            if((int)$pdo->query('SELECT COUNT(*) FROM app_lessons')->fetchColumn()>=300)throw new DomainException('Достигнут лимит 300 уроков.');
            $q=$pdo->prepare('INSERT INTO app_lessons(admin_id,category,sort_order,title_ru,title_kk,body_ru,body_kk,media_id) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$admin,$category,$order,...array_values($v),$media?:null]);$id=(int)$pdo->lastInsertId();
        }$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function lessonSetStatus(PDO $pdo,int $admin,int $id,string $status,int $revision): void {
    if(!in_array($status,['published','withdrawn'],true))throw new DomainException('Недоступное действие.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM app_lessons WHERE id=? AND admin_id=? FOR UPDATE');$q->execute([$id,$admin]);$r=$q->fetch();
        if(!$r)throw new DomainException('Урок не найден.');
        if($r['status']===$status){$pdo->commit();return;}
        if((int)$r['revision']!==$revision)throw new DomainException('Урок изменился. Обновите список.');
        if($status==='published'){
            $q=$pdo->prepare('SELECT file_key FROM app_announcement_media WHERE id=?');$q->execute([$r['media_id']]);$key=$q->fetchColumn();
            if(!$key||!is_file(announcementMediaDirectory().DIRECTORY_SEPARATOR.$key))throw new DomainException('Перед публикацией загрузите видео.');
        }
        $q=$pdo->prepare('UPDATE app_lessons SET status=?,revision=revision+1,published_at=IF(?=\'published\',UTC_TIMESTAMP(),published_at) WHERE id=?');$q->execute([$status,$status,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function lessonMediaAccessible(PDO $pdo,string $media): bool {
    $q=$pdo->prepare("SELECT id FROM app_lessons WHERE media_id=? AND status='published' LIMIT 1");$q->execute([$media]);return (bool)$q->fetchColumn();
}
function lessonComplete(PDO $pdo,string $subject,int $id,bool $completed): void {
    $pdo->beginTransaction();try{
        $q=$pdo->prepare("SELECT id FROM app_lessons WHERE id=? AND status='published' FOR UPDATE");$q->execute([$id]);if(!$q->fetchColumn())throw new DomainException('Урок больше недоступен. Обновите список.');
        $q=$pdo->prepare($completed?'INSERT IGNORE INTO app_lesson_reads(subject,lesson_id) VALUES(?,?)':'DELETE FROM app_lesson_reads WHERE subject=? AND lesson_id=?');$q->execute([$subject,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
