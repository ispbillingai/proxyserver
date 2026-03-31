<?php
// Receives heartbeat data from MikroTik routers
// POST params: tenant, router_id, type (hotspot|pppoe), users (comma-separated)
// Upserts users (updates last_seen if exists). Cleanup handled by cron.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$tenant   = trim($_POST['tenant'] ?? '');
$routerId = intval($_POST['router_id'] ?? 0);
$type     = trim($_POST['type'] ?? '');
$usersCsv = trim($_POST['users'] ?? '');

if ($tenant === '' || $routerId === 0 || !in_array($type, ['hotspot', 'pppoe'])) {
    http_response_code(400);
    exit('Missing tenant, router_id, or invalid type');
}

$db = getDB();

// Auto-create table
$db->exec("CREATE TABLE IF NOT EXISTS mikrotik_active_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) NOT NULL,
    router_id INT NOT NULL,
    username VARCHAR(128) NOT NULL,
    type ENUM('hotspot','pppoe') NOT NULL,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_router_user_type (tenant, router_id, username, type),
    KEY idx_tenant_router (tenant, router_id),
    KEY idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Parse and upsert users
$users = $usersCsv !== '' ? array_filter(array_map('trim', explode(',', $usersCsv))) : [];

$upsert = $db->prepare("INSERT INTO mikrotik_active_users (tenant, router_id, username, type, last_seen)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen = NOW()");

foreach ($users as $u) {
    $upsert->execute([$tenant, $routerId, $u, $type]);
}

// Update router heartbeat timestamp
$db->exec("CREATE TABLE IF NOT EXISTS router_heartbeats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) NOT NULL,
    router_id INT NOT NULL,
    hotspot_count INT DEFAULT 0,
    pppoe_count INT DEFAULT 0,
    last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_router (tenant, router_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$countCol = $type === 'hotspot' ? 'hotspot_count' : 'pppoe_count';
$stmt = $db->prepare("INSERT INTO router_heartbeats (tenant, router_id, {$countCol}, last_heartbeat)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE {$countCol} = VALUES({$countCol}), last_heartbeat = NOW()");
$stmt->execute([$tenant, $routerId, count($users)]);

echo "OK: " . count($users) . " $type";
