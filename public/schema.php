<?php
require_once __DIR__.'/platform.php';
function appSchema($pdo): void {
    static $ready; $ready ??= new WeakMap();
    if (isset($ready[$pdo])) return;
$pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,kind VARCHAR(32) NOT NULL,payload LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,archived TINYINT DEFAULT 0,INDEX owner_kind(user_id,kind,archived)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_bookings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,resource VARCHAR(100) NOT NULL,starts_at DATETIME NOT NULL,client VARCHAR(180) NOT NULL,phone VARCHAR(40) NOT NULL,service VARCHAR(180) NOT NULL,status VARCHAR(20) DEFAULT 'confirmed',UNIQUE KEY slot(user_id,resource,starts_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_catalog_status (user_id INT NOT NULL,item_id INT NOT NULL,archived TINYINT DEFAULT 0,PRIMARY KEY(user_id,item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_market (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,owner_id INT NOT NULL,record_id BIGINT UNSIGNED NOT NULL,body LONGTEXT NOT NULL,target_quantity DECIMAL(15,3) NOT NULL,active TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY owner_record(owner_id,record_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS aulieata_coop_members (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,market_id BIGINT UNSIGNED NOT NULL,user_id INT NOT NULL,quantity DECIMAL(15,3) NOT NULL,name VARCHAR(180) NOT NULL,contact VARCHAR(180) NOT NULL,active TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY member(market_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    platformSchema($pdo);
    catalogDataSchema($pdo);
    opsSchema($pdo);
    $ready[$pdo] = true;
}
