<?php
require_once __DIR__.'/database.php';
require_once __DIR__.'/announcements.php';
appSessionStart();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function announcementResponse(array $body, int $code = 200): void {
    http_response_code($code); echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
try {
    if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id'])) announcementResponse(['error'=>'auth_required'],401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') announcementResponse(['error'=>'method_invalid'],405);
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) announcementResponse(['error'=>'session_expired'],403);
    $multipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '','multipart/form-data');
    $input = $multipart ? $_POST : json_decode(file_get_contents('php://input'),true);
    if (!is_array($input)) announcementResponse(['error'=>'request_invalid'],400);
    $action = $input['action'] ?? '';
    $pdo = appDatabase(); announcementSchema($pdo);
    $admin = announcementAdmin($pdo);
    if (str_starts_with($action,'admin_')) {
        if (!$admin) announcementResponse(['error'=>'admin_required'],403);
        if ($action === 'admin_list') announcementResponse(['items'=>$pdo->query('SELECT *,UNIX_TIMESTAMP(published_at) AS published_epoch FROM app_announcements ORDER BY id DESC LIMIT 200')->fetchAll(),'profile'=>$admin]);
        if ($action === 'admin_save') announcementResponse(['id'=>announcementSave($pdo,(int)$admin['id'],$input)]);
        if ($action === 'admin_publish') { announcementPublish($pdo,(int)($input['id'] ?? 0)); announcementResponse(['ok'=>true]); }
        if ($action === 'admin_withdraw') {
            $pdo->beginTransaction(); $pdo->query('SELECT id FROM app_announcement_settings WHERE id=1 FOR UPDATE')->fetch();
            $q = $pdo->prepare("UPDATE app_announcements SET status='withdrawn' WHERE id=? AND status='published'"); $q->execute([(int)($input['id'] ?? 0)]);
            $pdo->commit(); announcementResponse(['ok'=>true]);
        }
        if ($action === 'admin_upload') {
            if (!$multipart) announcementResponse(['error'=>'video_invalid'],422);
            if ((int)$pdo->query('SELECT COALESCE(SUM(bytes),0) FROM app_announcement_media')->fetchColumn() > 5*1024*1024*1024) announcementResponse(['error'=>'video_storage_full'],422);
            announcementResponse(announcementUpload($pdo,(int)$admin['id'],$_FILES['video'] ?? []));
        }
        if ($action === 'admin_profile') {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) announcementResponse(['error'=>'profile_invalid'],422);
            $q = $pdo->prepare('UPDATE app_admins SET name=? WHERE id=?'); $q->execute([$name,$admin['id']]); announcementResponse(['ok'=>true]);
        }
    } else {
        if (empty($_SESSION['user_id'])) announcementResponse(['error'=>'auth_required'],401);
        $uid = (int)$_SESSION['user_id'];
        if (!empty($_SESSION['staff_id'])) { require_once __DIR__.'/operations.php'; opsStaffGate($pdo,$uid,'load',[]); }
        $subject = announcementSubject();
        if ($action === 'pending' || $action === 'history') announcementResponse(['items'=>announcementList($pdo,$subject,$uid,$action==='history')]);
        if ($action === 'acknowledge') { announcementAcknowledge($pdo,$subject,$uid,(int)($input['id'] ?? 0)); announcementResponse(['ok'=>true]); }
    }
    announcementResponse(['error'=>'request_invalid'],400);
} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    announcementResponse(['error'=>$e->getMessage()],422);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Announcements: '.$e->getMessage());
    announcementResponse(['error'=>'service_unavailable'],503);
}
