<?php
require_once __DIR__.'/database.php';
require_once __DIR__.'/announcements.php';
appSessionStart();
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) { http_response_code(405); exit; }
    if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id'])) { http_response_code(404); exit; }
    $id = $_GET['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { http_response_code(404); exit; }
    $pdo = appDatabase(); announcementSchema($pdo);
    $admin = announcementAdmin($pdo);
    if (!$admin) {
        if (empty($_SESSION['user_id'])) { http_response_code(404); exit; }
        if (!empty($_SESSION['staff_id'])) { require_once __DIR__.'/operations.php'; opsStaffGate($pdo,(int)$_SESSION['user_id'],'load',[]); }
        $allowed = announcementAccessible($pdo,(int)$_SESSION['user_id'],0,$id);
        if (!$allowed) { http_response_code(404); exit; }
    }
    $q = $pdo->prepare('SELECT * FROM app_announcement_media WHERE id=?'); $q->execute([$id]); $media = $q->fetch();
    if (!$media || !preg_match('/^[a-f0-9]{32}\.(mp4|webm)$/D',$media['file_key'])) { http_response_code(404); exit; }
    $path = announcementMediaDirectory().DIRECTORY_SEPARATOR.$media['file_key'];
    if (!is_file($path)) { http_response_code(404); exit; }
    $size = filesize($path);
    try { [$start,$end,$partial] = announcementByteRange($_SERVER['HTTP_RANGE'] ?? null,$size); }
    catch (DomainException $e) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
    header('Content-Type: '.$media['mime']); header('Accept-Ranges: bytes'); header('Content-Length: '.($end-$start+1));
    if ($partial) { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
    session_write_close();
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $stream = fopen($path,'rb'); fseek($stream,$start); $left = $end-$start+1;
    while ($left > 0 && !feof($stream) && !connection_aborted()) { $chunk = fread($stream,min(65536,$left)); if ($chunk === false || $chunk === '') break; echo $chunk; $left -= strlen($chunk); }
    fclose($stream);
} catch (Throwable $e) { error_log('Announcement video: '.$e->getMessage()); http_response_code(404); }
