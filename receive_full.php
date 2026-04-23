<?php
/**
 * receive_full.php - Proxy server endpoint to receive user activations
 *
 * Receives rich user data (Hotspot + PPPoE) when both main IP and VPN are offline.
 * The router pulls pending users via fetch_full.php
 *
 * POST JSON:
 * {
 *   "tenant": "demo",
 *   "router_id": 6,
 *   "users": [{
 *     "username": "2547xxx-AB12",
 *     "password": "1234",
 *     "pppoe_password": "",
 *     "profile_name": "10Mbps",
 *     "type": "Hotspot",            // or "PPPOE"
 *     "expiration": "2026-05-14 23:59:00",
 *     "fullname": "John Doe",
 *     "phonenumber": "254700000000",
 *     "email": "",
 *     "typebp": "Unlimited",        // or "Limited"
 *     "limit_type": "",             // Time_Limit, Data_Limit, Both_Limit
 *     "time_limit": 0,
 *     "time_unit": "",
 *     "data_limit": 0,
 *     "data_unit": ""
 *   }]
 * }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

try {
    $pdo = getDB();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
    exit;
}

$disabledUntil = receiversDisabledUntil();
if ($disabledUntil !== null) {
    http_response_code(503);
    header('Retry-After: ' . max(1, strtotime($disabledUntil) - time()));
    echo json_encode(['status' => 'disabled', 'message' => 'Receivers temporarily disabled', 'until' => $disabledUntil]);
    exit;
}

// Auto-create table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `proxy_user_queue` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant` VARCHAR(100) NOT NULL,
        `router_id` INT NOT NULL,
        `username` VARCHAR(100) NOT NULL,
        `password` VARCHAR(100) NOT NULL DEFAULT '1234',
        `pppoe_password` VARCHAR(100) DEFAULT '',
        `profile_name` VARCHAR(100) NOT NULL,
        `type` ENUM('Hotspot','PPPOE') NOT NULL DEFAULT 'Hotspot',
        `expiration` VARCHAR(50) DEFAULT '',
        `fullname` VARCHAR(200) DEFAULT '',
        `phonenumber` VARCHAR(50) DEFAULT '',
        `email` VARCHAR(200) DEFAULT '',
        `typebp` VARCHAR(20) DEFAULT 'Unlimited',
        `limit_type` VARCHAR(20) DEFAULT '',
        `time_limit` INT DEFAULT 0,
        `time_unit` VARCHAR(10) DEFAULT '',
        `data_limit` INT DEFAULT 0,
        `data_unit` VARCHAR(10) DEFAULT '',
        `status` ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `delivered_at` DATETIME DEFAULT NULL,
        UNIQUE KEY `uq_tenant_router_user` (`tenant`, `router_id`, `username`),
        INDEX `idx_tenant_router` (`tenant`, `router_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Read POST ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['tenant']) || empty($data['router_id']) || empty($data['users'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant, router_id, and users[] required']);
    exit;
}

$tenant   = trim($data['tenant']);
$routerId = (int)$data['router_id'];
$users    = $data['users'];
$inserted = 0;

$stmt = $pdo->prepare("
    INSERT INTO `proxy_user_queue`
        (tenant, router_id, username, password, pppoe_password, profile_name, type,
         expiration, fullname, phonenumber, email,
         typebp, limit_type, time_limit, time_unit, data_limit, data_unit, status)
    VALUES
        (:tenant, :router_id, :username, :password, :pppoe_password, :profile_name, :type,
         :expiration, :fullname, :phonenumber, :email,
         :typebp, :limit_type, :time_limit, :time_unit, :data_limit, :data_unit, 'pending')
    ON DUPLICATE KEY UPDATE
        password = VALUES(password),
        pppoe_password = VALUES(pppoe_password),
        profile_name = VALUES(profile_name),
        expiration = VALUES(expiration),
        fullname = VALUES(fullname),
        phonenumber = VALUES(phonenumber),
        email = VALUES(email),
        typebp = VALUES(typebp),
        limit_type = VALUES(limit_type),
        time_limit = VALUES(time_limit),
        time_unit = VALUES(time_unit),
        data_limit = VALUES(data_limit),
        data_unit = VALUES(data_unit),
        status = 'pending',
        created_at = NOW()
");

foreach ($users as $u) {
    try {
        $stmt->execute([
            ':tenant'         => $tenant,
            ':router_id'      => $routerId,
            ':username'       => trim($u['username'] ?? ''),
            ':password'       => trim($u['password'] ?? '1234'),
            ':pppoe_password' => trim($u['pppoe_password'] ?? ''),
            ':profile_name'   => trim($u['profile_name'] ?? ''),
            ':type'           => ($u['type'] ?? 'Hotspot') === 'PPPOE' ? 'PPPOE' : 'Hotspot',
            ':expiration'     => trim($u['expiration'] ?? ''),
            ':fullname'       => trim($u['fullname'] ?? ''),
            ':phonenumber'    => trim($u['phonenumber'] ?? ''),
            ':email'          => trim($u['email'] ?? ''),
            ':typebp'         => trim($u['typebp'] ?? 'Unlimited'),
            ':limit_type'     => trim($u['limit_type'] ?? ''),
            ':time_limit'     => (int)($u['time_limit'] ?? 0),
            ':time_unit'      => trim($u['time_unit'] ?? ''),
            ':data_limit'     => (int)($u['data_limit'] ?? 0),
            ':data_unit'      => trim($u['data_unit'] ?? ''),
        ]);
        $inserted++;
    } catch (PDOException $e) {
        error_log("receive_full: insert error for {$u['username']}: " . $e->getMessage());
    }
}

echo json_encode(['status' => 'ok', 'inserted' => $inserted]);
