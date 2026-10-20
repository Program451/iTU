<?php
/** Platform announcements are separate from business-owner and staff permissions. */
function announcementSchema(PDO $pdo): void {
    static $ready; $ready ??= new WeakMap();
    if (isset($ready[$pdo])) return;
    foreach ([
        "app_admins(id INT AUTO_INCREMENT PRIMARY KEY,phone VARCHAR(40) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "app_announcement_media(id CHAR(32) PRIMARY KEY,admin_id INT NOT NULL,file_key VARCHAR(48) NOT NULL,mime VARCHAR(40) NOT NULL,bytes BIGINT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "app_announcements(id BIGINT AUTO_INCREMENT PRIMARY KEY,admin_id INT NOT NULL,kind VARCHAR(12) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'draft',title_ru VARCHAR(180) NOT NULL,title_kk VARCHAR(180) NOT NULL,body_ru TEXT NOT NULL,body_kk TEXT NOT NULL,version VARCHAR(40) NOT NULL DEFAULT '',media_id CHAR(32) NULL,published_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX audience(status,kind,published_at))",
        "app_announcement_settings(id TINYINT PRIMARY KEY,welcome_id BIGINT NULL)",
        "app_announcement_state(subject VARCHAR(40) PRIMARY KEY,welcome_seen TINYINT NOT NULL DEFAULT 1)",
        "app_announcement_reads(subject VARCHAR(40) NOT NULL,announcement_id BIGINT NOT NULL,seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(subject,announcement_id))"
    ] as $definition) $pdo->exec('CREATE TABLE IF NOT EXISTS '.$definition.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('INSERT IGNORE INTO app_announcement_settings(id) VALUES(1)');
    $ready[$pdo] = true;
}

function announcementAdmin(PDO $pdo): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    $q = $pdo->prepare('SELECT id,phone,name,created_at FROM app_admins WHERE id=? AND active=1');
    $q->execute([(int)$_SESSION['admin_id']]);
    return $q->fetch() ?: null;
}

function announcementRegister(PDO $pdo, int $uid): void {
    $q = $pdo->prepare('INSERT INTO app_announcement_state(subject,welcome_seen) VALUES(?,0)');
    $q->execute(['user:'.$uid]);
}

function announcementSubject(): string {
    return !empty($_SESSION['staff_id']) ? 'staff:'.(int)$_SESSION['staff_id'] : 'user:'.(int)$_SESSION['user_id'];
}

function announcementPublic(array $row): array {
    return array_intersect_key($row, array_flip(['id','kind','title_ru','title_kk','body_ru','body_kk','version','media_id','published_at','published_epoch']));
}

function announcementList(PDO $pdo, string $subject, int $uid, bool $history = false): array {
    $q = $pdo->prepare('SELECT welcome_seen FROM app_announcement_state WHERE subject=?');
    // Accounts created before this feature, and staff accounts, do not receive onboarding.
    $q->execute([$subject]); $seen = $q->fetchColumn();
    $welcome = $seen !== false && (int)$seen === 0;
    $sql = "SELECT a.*,UNIX_TIMESTAMP(a.published_at) AS published_epoch FROM app_announcements a JOIN users u ON u.id=? LEFT JOIN app_announcement_reads r ON r.announcement_id=a.id AND r.subject=? WHERE a.status='published' AND ((a.kind='welcome' AND a.id=(SELECT welcome_id FROM app_announcement_settings WHERE id=1) AND ?) OR (a.kind='update' AND a.published_at >= u.created_at))";
    if (!$history) $sql .= ' AND r.announcement_id IS NULL';
    $sql .= $history ? ' ORDER BY a.published_at DESC,a.id DESC LIMIT 100' : " ORDER BY (a.kind='welcome') DESC,a.published_at ASC,a.id ASC LIMIT 100";
    $q = $pdo->prepare($sql); $q->execute([$uid,$subject, $history || $welcome ? 1 : 0]);
    return array_map('announcementPublic', $q->fetchAll());
}

function announcementAccessible(PDO $pdo, int $uid, int $id = 0, ?string $media = null): ?array {
    $sql = "SELECT a.* FROM app_announcements a JOIN users u ON u.id=? WHERE a.status='published' AND ((a.kind='welcome' AND a.id=(SELECT welcome_id FROM app_announcement_settings WHERE id=1)) OR (a.kind='update' AND a.published_at>=u.created_at)) AND ";
    $sql .= $media !== null ? 'a.media_id=?' : 'a.id=?';
    $q = $pdo->prepare($sql.' LIMIT 1'); $q->execute([$uid,$media ?? $id]);
    return $q->fetch() ?: null;
}

function announcementAcknowledge(PDO $pdo, string $subject, int $uid, int $id): void {
    $pdo->beginTransaction();
    try {
        // Serializes acknowledgement with publication/withdrawal of the welcome message.
        $pdo->query('SELECT id FROM app_announcement_settings WHERE id=1 FOR UPDATE')->fetch();
        $found = announcementAccessible($pdo,$uid,$id);
        if (!$found) throw new DomainException('announcement_unavailable');
        $q = $pdo->prepare('INSERT IGNORE INTO app_announcement_reads(subject,announcement_id) VALUES(?,?)'); $q->execute([$subject,$id]);
        if ($found['kind'] === 'welcome') {
            $q = $pdo->prepare('INSERT INTO app_announcement_state(subject,welcome_seen) VALUES(?,1) ON DUPLICATE KEY UPDATE welcome_seen=1'); $q->execute([$subject]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function announcementSave(PDO $pdo, int $admin, array $input): int {
    $kind = $input['kind'] ?? '';
    if (!in_array($kind, ['welcome','update'], true)) throw new DomainException('announcement_invalid');
    $values = [];
    foreach (['title_ru'=>180,'title_kk'=>180,'body_ru'=>10000,'body_kk'=>10000,'version'=>40] as $key=>$max) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || mb_strlen($value) > $max) throw new DomainException('announcement_invalid');
        $values[$key] = trim($value);
    }
    if (!$values['title_ru'] || !$values['body_ru']) throw new DomainException('announcement_required');
    $media = $input['media_id'] ?? null;
    if ($media) {
        $q = $pdo->prepare('SELECT id FROM app_announcement_media WHERE id=?'); $q->execute([$media]);
        if (!$q->fetchColumn()) throw new DomainException('video_unavailable');
    }
    $id = (int)($input['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        if ($id) {
            $q = $pdo->prepare('SELECT status FROM app_announcements WHERE id=? FOR UPDATE'); $q->execute([$id]);
            if ($q->fetchColumn() !== 'draft') throw new DomainException('announcement_immutable');
            $q = $pdo->prepare('UPDATE app_announcements SET kind=?,title_ru=?,title_kk=?,body_ru=?,body_kk=?,version=?,media_id=?,admin_id=? WHERE id=?');
            $q->execute([$kind,...array_values($values),$media?:null,$admin,$id]);
        } else {
            $q = $pdo->prepare('INSERT INTO app_announcements(kind,title_ru,title_kk,body_ru,body_kk,version,media_id,admin_id) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$kind,...array_values($values),$media?:null,$admin]); $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function announcementPublish(PDO $pdo, int $id): void {
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM app_announcement_settings WHERE id=1 FOR UPDATE')->fetch();
        $q = $pdo->prepare('SELECT * FROM app_announcements WHERE id=? FOR UPDATE'); $q->execute([$id]); $row = $q->fetch();
        if (!$row) throw new DomainException('announcement_unavailable');
        if ($row['status'] === 'published') { $pdo->commit(); return; }
        if ($row['status'] !== 'draft') throw new DomainException('announcement_immutable');
        if (!$row['title_kk'] || !$row['body_kk']) throw new DomainException('announcement_bilingual');
        if (!$row['media_id']) throw new DomainException('announcement_video_required');
        $q = $pdo->prepare('UPDATE app_announcements SET status=\'published\',published_at=NOW() WHERE id=?'); $q->execute([$id]);
        if ($row['kind'] === 'welcome') {
            $q = $pdo->prepare('UPDATE app_announcement_settings SET welcome_id=? WHERE id=1'); $q->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function announcementMediaDirectory(): string {
    $cfg = appConfig();
    $path = $cfg['announcement_media_dir'] ?? dirname(__DIR__).'/aulieata-media/announcements';
    if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new RuntimeException('Media storage unavailable');
    $resolved = realpath($path); $public = realpath(__DIR__);
    if (!$resolved || $resolved === $public || str_starts_with(str_replace('\\','/',$resolved).'/',str_replace('\\','/',$public).'/')) throw new RuntimeException('Media must be outside public');
    return $resolved;
}

function announcementUploadLimit(?string $upload = null, ?string $post = null): int {
    $bytes = static function(string $value): int {
        $value = trim($value);
        $number = (float)$value;
        if ($number <= 0) return PHP_INT_MAX;
        $unit = strtolower(substr($value,-1));
        return (int)min(PHP_INT_MAX, $number * match($unit) {'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1});
    };
    // Reserve room for the multipart envelope and display an honest whole-MB limit.
    return max(0,(int)floor(min(100*1048576,$bytes($upload ?? ini_get('upload_max_filesize')),$bytes($post ?? ini_get('post_max_size'))-65536)/1048576)*1048576);
}

function announcementUpload(PDO $pdo, int $admin, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('video_upload_failed');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) throw new DomainException('video_invalid');
    $size = filesize($tmp);
    if ($size === false || $size < 32 || $size > announcementUploadLimit()) throw new DomainException('video_too_large');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extension = ['video/mp4'=>'mp4','video/webm'=>'webm'][$mime] ?? null;
    if (!$extension) throw new DomainException('video_invalid');
    $id = bin2hex(random_bytes(16)); $key = $id.'.'.$extension;
    $path = announcementMediaDirectory().DIRECTORY_SEPARATOR.$key;
    if (!move_uploaded_file($tmp,$path)) throw new RuntimeException('Video storage failed');
    try {
        $q = $pdo->prepare('INSERT INTO app_announcement_media(id,admin_id,file_key,mime,bytes) VALUES(?,?,?,?,?)'); $q->execute([$id,$admin,$key,$mime,$size]);
    } catch (Throwable $e) { unlink($path); throw $e; }
    return ['id'=>$id,'bytes'=>$size,'mime'=>$mime];
}

/** Single byte ranges allow native video controls to seek without loading the whole upload. */
function announcementByteRange(?string $header, int $size): array {
    if ($header === null || $header === '') return [0,$size-1,false];
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$header,$m) || ($m[1] === '' && $m[2] === '')) throw new DomainException('range');
    if ($m[1] === '') { $length = (int)$m[2]; if ($length < 1) throw new DomainException('range'); $start = max(0,$size-$length); $end = $size-1; }
    else { $start = (int)$m[1]; $end = $m[2] === '' ? $size-1 : min((int)$m[2],$size-1); }
    if ($start >= $size || $start > $end) throw new DomainException('range');
    return [$start,$end,true];
}
