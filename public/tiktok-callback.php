<?php
require_once __DIR__.'/database.php';
appSessionStart();
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Content-Type: text/html; charset=utf-8');
$ok=false;$message='Войдите в AulieAta AI как владелец и начните подключение TikTok в разделе «Настройки».';
try {
    if($_SERVER['REQUEST_METHOD']!=='GET')throw new RuntimeException('Начните подключение TikTok в настройках сайта.');
    if(empty($_SESSION['user_id'])||!empty($_SESSION['staff_id']))throw new RuntimeException($message);
    require_once __DIR__.'/schema.php';$pdo=appDatabase();appSchema($pdo);$uid=(int)$_SESSION['user_id'];
    tiktokFinish($pdo,$uid,appConfig(),$_SESSION,$_GET);
    $ok=true;$message='TikTok подключён. Профиль подтверждён, доступ сохранён на сервере. Подключение само по себе не публикует материалы.';
} catch(Throwable $e) {
    http_response_code(400);
    $message=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Не удалось завершить подключение. Повторите его в настройках сайта.';
}
// Never echo callback query values, authorization codes or provider error descriptions.
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TikTok · AulieAta AI</title><style>body{margin:0;background:#f5f3ec;color:#173a2c;font:18px/1.6 system-ui}main{max-width:650px;margin:10vh auto;padding:32px}a{display:inline-block;padding:12px 24px;background:#173a2c;color:white;border-radius:12px;text-decoration:none}</style><main><p>AulieAta AI · TikTok</p><h1><?= $ok?'Аккаунт подключён':'Подключение не завершено' ?></h1><p><?= htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></p><a href="index.php#settings">Вернуться в настройки</a></main></html>
