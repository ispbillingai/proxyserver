<?php
// Receives heartbeat data from MikroTik routers
// POST params: tenant, router_id, hotspot_users (comma-separated), pppoe_users (comma-separated)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$tenant   = trim($_POST['tenant'] ?? '');
$routerId = intval($_POST['router_id'] ?? 0);
$hotspotCsv = trim($_POST['hotspot_users'] ?? '');
$pppoeCsv   = trim($_POST['pppoe_users'] ?? '');

if ($tenant === '' || $routerId === 0) {
    http_response_code(400);
    exit('Missing tenant or router_id');
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

// Parse usernames
$hotspotUsers = $hotspotCsv !== '' ? array_filter(array_map('trim', explode(',', $hotspotCsv))) : [];
$pppoeUsers   = $pppoeCsv !== ''   ? array_filter(array_map('trim', explode(',', $pppoeCsv)))   : [];

// Clear old entries for this router so we only keep currently active users
$stmt = $db->prepare("DELETE FROM mikrotik_active_users WHERE tenant = ? AND router_id = ?");
$stmt->execute([$tenant, $routerId]);

// Insert current active users
$insert = $db->prepare("INSERT INTO mikrotik_active_users (tenant, router_id, username, type) VALUES (?, ?, ?, ?)");

foreach ($hotspotUsers as $u) {
    $insert->execute([$tenant, $routerId, $u, 'hotspot']);
}
foreach ($pppoeUsers as $u) {
    $insert->execute([$tenant, $routerId, $u, 'pppoe']);
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

$stmt = $db->prepare("INSERT INTO router_heartbeats (tenant, router_id, hotspot_count, pppoe_count, last_heartbeat)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE hotspot_count = VALUES(hotspot_count), pppoe_count = VALUES(pppoe_count), last_heartbeat = NOW()");
$stmt->execute([$tenant, $routerId, count($hotspotUsers), count($pppoeUsers)]);

echo "OK: " . count($hotspotUsers) . " hotspot, " . count($pppoeUsers) . " pppoe";
