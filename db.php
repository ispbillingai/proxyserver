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
        router_id INT NOT NULL,
        username VARCHAR(128) NOT NULL,
        profile_name VARCHAR(128) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'Hotspot',
        created_time VARCHAR(32),
        pushed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_router_user (tenant, router_id, username),
        KEY idx_tenant_router (tenant, router_id),
        KEY idx_pushed_at (pushed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $pdo;
}

// --- Receiver toggle (receive.php / receive_full.php) ---
// Stored as a row in system_settings with key='receivers_disabled_until'.
// Value is a DATETIME string; if > NOW(), receivers reject incoming POSTs.

function ensureSettingsTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        `key` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function receiversDisabledUntil() {
    try {
        ensureSettingsTable();
        $db = getDB();
        $stmt = $db->prepare("SELECT `value` FROM system_settings WHERE `key` = 'receivers_disabled_until'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || empty($row['value'])) return null;
        if (strtotime($row['value']) <= time()) return null;
        return $row['value'];
    } catch (Throwable $e) {
        return null;
    }
}

function disableReceiversFor($seconds) {
    ensureSettingsTable();
    $until = date('Y-m-d H:i:s', time() + (int)$seconds);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('receivers_disabled_until', :v)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([':v' => $until]);
    return $until;
}

function enableReceivers() {
    ensureSettingsTable();
    $db = getDB();
    $db->exec("DELETE FROM system_settings WHERE `key` = 'receivers_disabled_until'");
}
