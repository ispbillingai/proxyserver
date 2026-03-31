<?php
// Fetch active MikroTik users and router heartbeat data
// GET params: tenant (required), router_id (optional), type (optional: hotspot|pppoe)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$tenant   = trim($_GET['tenant'] ?? '');
$routerId = intval($_GET['router_id'] ?? 0);
$type     = trim($_GET['type'] ?? '');

if ($tenant === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tenant']);
    exit;
}

$db = getDB();

// Fetch active users
$sql = "SELECT username, type, router_id, last_seen FROM mikrotik_active_users WHERE tenant = ?";
$params = [$tenant];

if ($routerId > 0) {
    $sql .= " AND router_id = ?";
    $params[] = $routerId;
}
if ($type === 'hotspot' || $type === 'pppoe') {
    $sql .= " AND type = ?";
    $params[] = $type;
}

$sql .= " ORDER BY type, username";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Fetch heartbeat info
$hbSql = "SELECT router_id, hotspot_count, pppoe_count, last_heartbeat FROM router_heartbeats WHERE tenant = ?";
$hbParams = [$tenant];

if ($routerId > 0) {
    $hbSql .= " AND router_id = ?";
    $hbParams[] = $routerId;
}

$hbSql .= " ORDER BY router_id";
$stmt = $db->prepare($hbSql);
$stmt->execute($hbParams);
$heartbeats = $stmt->fetchAll();

echo json_encode([
    'tenant' => $tenant,
    'users' => $users,
    'routers' => $heartbeats,
    'total_users' => count($users)
]);
