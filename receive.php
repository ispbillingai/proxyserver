<?php
// POST endpoint: billing servers push user data here

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$tenant = isset($data['tenant']) ? trim($data['tenant']) : '';
$users  = isset($data['users']) ? $data['users'] : [];

if ($tenant === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant required']);
    exit;
}

if (!is_array($users) || empty($users)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No users provided']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("INSERT INTO cached_users
    (tenant, router_name, username, profile_name, type, expiration, created_time, pushed_at)
    VALUES (:tenant, :router_name, :username, :profile_name, :type, :expiration, :created_time, NOW())
    ON DUPLICATE KEY UPDATE
        profile_name = VALUES(profile_name),
        type = VALUES(type),
        expiration = VALUES(expiration),
        created_time = VALUES(created_time),
        pushed_at = NOW()");

$count = 0;

foreach ($users as $u) {
    if (empty($u['username']) || empty($u['router_name'])) {
        continue;
    }

    $stmt->execute([
        ':tenant'       => $tenant,
        ':router_name'  => trim($u['router_name']),
        ':username'     => trim($u['username']),
        ':profile_name' => trim($u['profile_name'] ?? 'default'),
        ':type'         => trim($u['type'] ?? 'Hotspot'),
        ':expiration'   => trim($u['expiration'] ?? ''),
        ':created_time' => trim($u['created_time'] ?? ''),
    ]);
    $count++;
}

echo json_encode(['status' => 'ok', 'count' => $count]);
