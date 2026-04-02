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

// Auto-create tables
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

$db->exec("CREATE TABLE IF NOT EXISTS router_heartbeats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) NOT NULL,
    router_id INT NOT NULL,
    hotspot_count INT DEFAULT 0,
    pppoe_count INT DEFAULT 0,
    last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_router (tenant, router_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Parse users
$users = $usersCsv !== '' ? array_filter(array_map('trim', explode(',', $usersCsv))) : [];

// Batch upsert — single query instead of one per user
if (!empty($users)) {
    $chunks = array_chunk($users, 100); // 100 users per batch
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $db->beginTransaction();
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,NOW())'));
                $sql = "INSERT INTO mikrotik_active_users (tenant, router_id, username, type, last_seen)
                    VALUES $placeholders
                    ON DUPLICATE KEY UPDATE last_seen = NOW()";
                $params = [];
                foreach ($chunk as $u) {
                    $params[] = $tenant;
                    $params[] = $routerId;
                    $params[] = $u;
                    $params[] = $type;
                }
                $db->prepare($sql)->execute($params);
            }
            $db->commit();
            break;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e->getCode() == 40001 && $attempt < 2) {
                usleep(50000 * ($attempt + 1));
                continue;
            }
            throw $e;
        }
    }
}

// Update heartbeat
$countCol = $type === 'hotspot' ? 'hotspot_count' : 'pppoe_count';
$stmt = $db->prepare("INSERT INTO router_heartbeats (tenant, router_id, {$countCol}, last_heartbeat)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE {$countCol} = VALUES({$countCol}), last_heartbeat = NOW()");
$stmt->execute([$tenant, $routerId, count($users)]);

echo "OK: " . count($users) . " $type";
