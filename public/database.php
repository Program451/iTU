<?php
/** Private configuration is kept one directory above the document root. */
function appDatabase(): PDO {
    $configPath = dirname(__DIR__).'/aulieata-secrets.php';
    if (!is_file($configPath)) throw new RuntimeException('Private configuration is missing');
    $config = require $configPath;
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
