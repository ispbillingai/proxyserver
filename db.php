<?php
// MySQL connection + auto-create table

function getDB() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    global $db_host, $db_user, $db_password, $db_name;

    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Auto-create table
    $pdo->exec("CREATE TABLE IF NOT EXISTS cached_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant VARCHAR(64) NOT NULL,
        router_name VARCHAR(64) NOT NULL,
        username VARCHAR(128) NOT NULL,
        profile_name VARCHAR(128) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'Hotspot',
        expiration VARCHAR(32),
        created_time VARCHAR(32),
        pushed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_router_user (tenant, router_name, username),
        KEY idx_tenant_router (tenant, router_name),
        KEY idx_pushed_at (pushed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $pdo;
}
