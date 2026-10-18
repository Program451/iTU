<?php
if (!isset($pdo,$uid,$in,$action,$opsRole)) { http_response_code(404); exit; }
if (str_starts_with($action, 'instagram_')) {
    if ($opsRole !== 'owner') out(['error'=>'Подключением управляет владелец бизнеса.'],403);
    if ($action === 'instagram_connect' || $action === 'instagram_check') opsRateLimit($pdo,'instagram:'.$uid,30,3600);
    if ($action === 'instagram_connect') out(['connection'=>instagramConnect($pdo,$uid,$runtimeConfig,(string)($in['token']??''))]);
    if ($action === 'instagram_check') out(['connection'=>instagramCheck($pdo,$uid,$runtimeConfig)]);
    if ($action === 'instagram_disconnect') { instagramDisconnect($pdo,$uid); out(['ok'=>true]); }
}
