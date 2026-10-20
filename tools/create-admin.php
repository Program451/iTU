<?php
// Explicit provisioning only. No default account, password, or phone is shipped.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/announcements.php';
try {
    $phone = preg_replace('/\D/','',getenv('AULIEATA_ADMIN_PHONE') ?: '');
    if (strlen($phone) === 11 && $phone[0] === '8') $phone = '7'.substr($phone,1);
    $password = getenv('AULIEATA_ADMIN_PASSWORD') ?: '';
    $name = trim(getenv('AULIEATA_ADMIN_NAME') ?: 'Администратор');
    if (!preg_match('/^7\d{10}$/D',$phone) || strlen($password) < 8 || strlen($password) > 128 || !$name || mb_strlen($name)>100) throw new RuntimeException('Set AULIEATA_ADMIN_PHONE, AULIEATA_ADMIN_PASSWORD (8–128 characters), and optionally AULIEATA_ADMIN_NAME.');
    $pdo = appDatabase(); announcementSchema($pdo);
    $q = $pdo->prepare('INSERT INTO app_admins(phone,password_hash,name) VALUES(?,?,?)');
    $q->execute(['+'.$phone,password_hash($password,PASSWORD_DEFAULT),$name]);
    echo "Administrator created. Sign in at login.php.\n";
} catch (Throwable $e) { fwrite(STDERR,"Administrator was not created. Check configuration and ensure the phone is not already an administrator.\n"); exit(1); }
