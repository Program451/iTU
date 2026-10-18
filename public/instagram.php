<?php
/** Instagram Login connection. Tokens never leave the server after saving. */
class InstagramConnectionError extends RuntimeException {
    public bool $invalidToken;
    public function __construct(string $message, bool $invalidToken = false) {
        parent::__construct($message); $this->invalidToken = $invalidToken;
    }
}

function instagramSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_instagram_connections (
        user_id INT PRIMARY KEY, account_id VARCHAR(40) NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL, account_type VARCHAR(30) NOT NULL,
        token_box TEXT NOT NULL, check_status VARCHAR(30) NOT NULL DEFAULT 'verified',
        checked_at DATETIME NOT NULL, quota_usage INT NULL, quota_total INT NULL,
        quota_duration INT NULL, quota_checked_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function instagramRequest(string $token, string $path, array $params = []): array {
    if (!preg_match('~^/(?:me|[0-9]+/content_publishing_limit)$~D', $path)) {
        throw new RuntimeException('Unsupported Instagram request');
    }
    $ch = curl_init('https://graph.instagram.com/v25.0'.$path.'?'.http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json']]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($status !== 200 || !is_array($data) || isset($data['error'])) {
        $invalid = (int)($data['error']['code'] ?? 0) === 190;
        throw new InstagramConnectionError($invalid
            ? 'Токен Instagram недействителен или истёк. Сгенерируйте новый маркер и замените его.'
            : 'Instagram не подтвердил запрос. Повторите проверку позже или проверьте доступ приложения в Meta.', $invalid);
    }
    return $data;
}

function instagramInspectToken(string $token, ?callable $request = null): array {
    if (!preg_match('/^[A-Za-z0-9_.-]{30,4096}$/D', $token)) {
        throw new RuntimeException('Вставьте полный маркер доступа Instagram.');
    }
    $request ??= 'instagramRequest';
    $profile = $request($token, '/me', ['fields' => 'id,user_id,username,account_type']);
    $id = (string)($profile['user_id'] ?? '');
    $name = (string)($profile['username'] ?? '');
    $type = (string)($profile['account_type'] ?? '');
    if (!preg_match('/^[0-9]{5,40}$/D', $id) || !preg_match('/^[A-Za-z0-9_.]{1,100}$/D', $name)
        || !in_array($type, ['BUSINESS', 'MEDIA_CREATOR', 'CREATOR'], true)) {
        throw new RuntimeException('Нужен профессиональный аккаунт Instagram: Компания или Автор.');
    }
    $result = ['account_id' => $id, 'username' => $name, 'account_type' => $type,
        'quota_usage' => null, 'quota_total' => null, 'quota_duration' => null];
    try {
        $quota = $request($token, '/'.$id.'/content_publishing_limit', ['fields' => 'config,quota_usage']);
        $row = $quota['data'][0] ?? [];
        foreach (['quota_usage' => $row['quota_usage'] ?? null,
            'quota_total' => $row['config']['quota_total'] ?? null,
            'quota_duration' => $row['config']['quota_duration'] ?? null] as $key => $value) {
            if (is_int($value) && $value >= 0) $result[$key] = $value;
        }
    } catch (InstagramConnectionError $e) {
        // An unavailable optional quota endpoint must not imply publish permission.
        if ($e->invalidToken) throw $e;
    }
    return $result;
}

function instagramStatus(PDO $pdo, int $uid): ?array {
    $q = $pdo->prepare('SELECT account_id,username,account_type,check_status,checked_at,quota_usage,quota_total,quota_duration,quota_checked_at FROM aulieata_instagram_connections WHERE user_id=?');
    $q->execute([$uid]); $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['quota_usage','quota_total','quota_duration'] as $key) {
        $row[$key] = $row[$key] === null ? null : (int)$row[$key];
    }
    return $row;
}

function instagramConnect(PDO $pdo, int $uid, array $cfg, string $token, ?callable $request = null): array {
    $token = trim($token); $profile = instagramInspectToken($token, $request);
    $box = sealToken($token, $cfg); $now = opsNow();
    $pdo->beginTransaction();
    try {
        // Serialize replacements within one workspace; account_id also has a global unique key.
        $q = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $q->execute([$uid]);
        if (!$q->fetchColumn()) throw new RuntimeException('Рабочее пространство не найдено.');
        $old = instagramStatus($pdo, $uid);
        if ($old && $old['account_id'] !== $profile['account_id']) {
            throw new RuntimeException('Сначала отключите прежний Instagram-аккаунт в этом рабочем пространстве.');
        }
        // Explicit conflict handling avoids transferring another tenant's account via upsert.
        $other = $pdo->prepare('SELECT user_id FROM aulieata_instagram_connections WHERE account_id=?');
        $other->execute([$profile['account_id']]); $otherUid = $other->fetchColumn();
        if ($otherUid && (int)$otherUid !== $uid) throw new RuntimeException('Этот Instagram уже подключён к другому рабочему пространству.');
        if (!$old) {
            $q = $pdo->prepare("INSERT INTO aulieata_instagram_connections
                (user_id,account_id,username,account_type,token_box,check_status,checked_at,quota_usage,quota_total,quota_duration,quota_checked_at)
                VALUES(?,?,?,?,?,'verified',?,?,?,?,?)");
            $q->execute([$uid,$profile['account_id'],$profile['username'],$profile['account_type'],$box,$now,
                $profile['quota_usage'],$profile['quota_total'],$profile['quota_duration'],$profile['quota_total'] === null ? null : $now]);
        } else {
            $q = $pdo->prepare("UPDATE aulieata_instagram_connections SET username=?,account_type=?,token_box=?,check_status='verified',checked_at=?,quota_usage=?,quota_total=?,quota_duration=?,quota_checked_at=? WHERE user_id=?");
            $q->execute([$profile['username'],$profile['account_type'],$box,$now,$profile['quota_usage'],
                $profile['quota_total'],$profile['quota_duration'],$profile['quota_total'] === null ? null : $now,$uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('Этот Instagram уже подключён к другому рабочему пространству.');
        }
        throw $e;
    }
    return instagramStatus($pdo, $uid);
}

function instagramCheck(PDO $pdo, int $uid, array $cfg, ?callable $request = null): array {
    $q = $pdo->prepare('SELECT account_id,token_box FROM aulieata_instagram_connections WHERE user_id=?');
    $q->execute([$uid]); $old = $q->fetch(PDO::FETCH_ASSOC);
    if (!$old) throw new RuntimeException('Сначала подключите Instagram.');
    try {
        $profile = instagramInspectToken(openToken($old['token_box'], $cfg), $request);
        if ($profile['account_id'] !== $old['account_id']) throw new InstagramConnectionError('Аккаунт изменился. Подключите Instagram заново.', true);
    } catch (InstagramConnectionError $e) {
        $q = $pdo->prepare('UPDATE aulieata_instagram_connections SET check_status=?,checked_at=?,quota_usage=NULL,quota_total=NULL,quota_duration=NULL,quota_checked_at=NULL WHERE user_id=? AND token_box=?');
        $q->execute([$e->invalidToken ? 'invalid' : 'unavailable',opsNow(),$uid,$old['token_box']]);
        throw $e;
    }
    $now = opsNow();
    $q = $pdo->prepare("UPDATE aulieata_instagram_connections SET username=?,account_type=?,check_status='verified',checked_at=?,quota_usage=?,quota_total=?,quota_duration=?,quota_checked_at=? WHERE user_id=? AND token_box=?");
    $q->execute([$profile['username'],$profile['account_type'],$now,$profile['quota_usage'],$profile['quota_total'],
        $profile['quota_duration'],$profile['quota_total'] === null ? null : $now,$uid,$old['token_box']]);
    // Do not restore a connection removed or replaced while the network request was in flight.
    $q = $pdo->prepare('SELECT COUNT(*) FROM aulieata_instagram_connections WHERE user_id=? AND token_box=?');
    $q->execute([$uid,$old['token_box']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Подключение изменилось во время проверки. Обновите страницу.');
    return instagramStatus($pdo, $uid);
}

function instagramDisconnect(PDO $pdo, int $uid): void {
    $q = $pdo->prepare('DELETE FROM aulieata_instagram_connections WHERE user_id=?'); $q->execute([$uid]);
}
