<?php
/** Optional AULIEATA_CONFIG points to a private configuration outside the web root. */
function appConfig(): array {
    $path = getenv('AULIEATA_CONFIG') ?: dirname(__DIR__).'/aulieata-secrets.php';
    if (!is_file($path)) throw new RuntimeException('Private configuration is missing');
    $config = require $path;
    if (!is_array($config)) throw new RuntimeException('Invalid private configuration');
    return $config;
}

function appUrl(string $path = ''): string {
    $base = rtrim((string)(appConfig()['base_url'] ?? 'https://itudron.ru'), '/');
    $parts = parse_url($base);
    $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment']) ||
        (($parts['scheme'] ?? '') !== 'https' && !($local && ($parts['scheme'] ?? '') === 'http'))) {
        throw new RuntimeException('Configure an HTTPS base_url');
    }
    return $base.($path === '' ? '' : '/'.ltrim($path, '/'));
}

function appSessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Private configuration is kept one directory above the document root. */
function appDatabase(): PDO {
    $config = appConfig();
    if (!is_array($config) || empty($config['db_user']) || empty($config['db_name'])) {
        throw new RuntimeException('Database configuration is incomplete');
    }
    $host = (string)($config['db_host'] ?? 'localhost');
    $port = (int)($config['db_port'] ?? 3306);
    $database = (string)$config['db_name'];
    if (strpbrk($host.$database, ";\r\n") !== false || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid database configuration');
    }
    return new PDO('mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4',
        $config['db_user'], $config['db_pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
}
