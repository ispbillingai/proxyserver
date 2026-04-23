<?php
// POST endpoint: billing servers push user data here
// Uses INSERT IGNORE - if username already exists for tenant+router, skips it

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

$disabledUntil = receiversDisabledUntil();
if ($disabledUntil !== null) {
    http_response_code(503);
    header('Retry-After: ' . max(1, strtotime($disabledUntil) - time()));
    echo json_encode(['status' => 'disabled', 'message' => 'Receivers temporarily disabled', 'until' => $disabledUntil]);
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

// Upsert: insert new user or refresh pushed_at if already exists
$stmt = $db->prepare("INSERT INTO cached_users
    (tenant, router_id, username, profile_name, type, created_time, pushed_at)
    VALUES (:tenant, :router_id, :username, :profile_name, :type, :created_time, :pushed_at)
    ON DUPLICATE KEY UPDATE pushed_at = VALUES(pushed_at), profile_name = VALUES(profile_name), type = VALUES(type)");

$count = 0;

foreach ($users as $u) {
    if (empty($u['username']) || empty($u['router_id'])) {
        continue;
    }

    $stmt->execute([
        ':tenant'       => $tenant,
        ':router_id'    => (int)$u['router_id'],
        ':username'     => trim($u['username']),
        ':profile_name' => trim($u['profile_name'] ?? 'default'),
        ':type'         => trim($u['type'] ?? 'Hotspot'),
        ':created_time' => trim($u['created_time'] ?? ''),
        ':pushed_at'    => date('Y-m-d H:i:s'),
    ]);
    $count++;
}

echo json_encode(['status' => 'ok', 'count' => $count]);
