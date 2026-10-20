<?php
require_once __DIR__.'/database.php'; require_once __DIR__.'/announcements.php';
appSessionStart(); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
try { $pdo=appDatabase(); announcementSchema($pdo); $admin=announcementAdmin($pdo); }
catch(Throwable $e) { http_response_code(503); exit('Не удалось открыть кабинет. Повторите позже.'); }
if (!$admin) { header('Location: login.php'); exit; }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$boot = ['csrf'=>$_SESSION['csrf'],'profile'=>$admin,'upload_limit'=>announcementUploadLimit()];
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Кабинет администратора · AulieAta AI</title><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="announcements.css"><script src="i18n-catalog.js?v=20261020f"></script><script src="i18n.js"></script></head><body class="admin-app">
<aside class="admin-sidebar"><a class="admin-brand" href="admin.php"><span class="admin-mark">a</span>AulieAta <small>AI</small></a><p class="admin-eyebrow" data-i18n>УПРАВЛЕНИЕ ПЛАТФОРМОЙ</p><nav><button class="active" id="navAnnouncements" onclick="adminView('announcements')"><span>◫</span><span data-i18n>Объявления</span></button><button id="navProfile" onclick="adminView('profile')"><span>◎</span><span data-i18n>Профиль</span></button></nav><div class="admin-identity"><span class="admin-avatar">A</span><div><b id="adminName"></b><small data-i18n>Администратор</small></div></div><a class="admin-logout" href="login.php?logout=1" data-i18n>Выйти</a></aside>
<main class="admin-main"><header class="admin-topbar"><span data-i18n>Кабинет администратора</span><div class="admin-languages"><button onclick="adminLanguage('ru')">РУС</button><button onclick="adminLanguage('kk')">ҚАЗ</button></div></header><div class="admin-content" id="adminContent"></div></main><dialog id="announcementDialog" class="announcement-dialog" aria-labelledby="announcementTitle"></dialog><div class="admin-toast" id="adminToast" role="status" hidden></div>
<script>const ADMIN_BOOT=<?=json_encode($boot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>;</script><script src="announcement-client.js?v=20261020b"></script><script src="admin-ui.js?v=20261020b"></script></body></html>
