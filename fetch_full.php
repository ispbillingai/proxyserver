<?php
/**
 * fetch_full.php - Proxy server endpoint for routers to pull pending users
 *
 * Called by MikroTik scheduler script every 1 minute.
 * Returns CSV lines the router script can parse:
 *
 * For Hotspot:  Hotspot,username,password,profile,time_limit,data_limit
 * For PPPoE:    PPPOE,username,password,profile,comment
 *
 * GET params:
 *   tenant    = subdomain (e.g. "demo")
 *   router_id = router ID
 *   limit     = max users to return (default 10)
 *
 * After returning, marks those rows as 'delivered'.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getDB();
} catch (PDOException $e) {
    echo "ERROR";
    exit;
}

// Auto-create table if not exists
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

$tenant   = trim($_GET['tenant'] ?? '');
$routerId = (int)($_GET['router_id'] ?? 0);
$limit    = (int)($_GET['limit'] ?? 10);

if ($tenant === '' || $routerId < 1) {
    echo "NO_USERS";
    exit;
}

if ($limit < 1) $limit = 10;
if ($limit > 50) $limit = 50;

// Fetch pending users for this router
$stmt = $pdo->prepare("
    SELECT * FROM `proxy_user_queue`
    WHERE tenant = :tenant
      AND router_id = :router_id
      AND status = 'pending'
    ORDER BY created_at ASC
    LIMIT :lim
");
$stmt->bindValue(':tenant', $tenant, PDO::PARAM_STR);
$stmt->bindValue(':router_id', $routerId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "NO_USERS";
    exit;
}

$ids = [];
$lines = [];

foreach ($rows as $r) {
    $ids[] = $r['id'];

    if ($r['type'] === 'PPPOE') {
        // PPPOE: type,username,password,profile,comment
        $pass = !empty($r['pppoe_password']) ? $r['pppoe_password'] : $r['password'];
        $comment = $r['fullname'] . ' | ' . $r['phonenumber'] . ' | Exp: ' . $r['expiration'];
        // Sanitize commas from comment
        $comment = str_replace(',', ';', $comment);
        $lines[] = "PPPOE,{$r['username']},{$pass},{$r['profile_name']},{$comment}";
    } else {
        // Hotspot: type,username,password,profile,time_limit,data_limit
        $timeLimit = '';
        $dataLimit = '';

        if ($r['typebp'] === 'Limited') {
            // Time limit
            if ($r['time_limit'] > 0 && !empty($r['time_unit'])) {
                if ($r['time_unit'] === 'Hrs') {
                    $timeLimit = $r['time_limit'] . ":00:00";
                } else {
                    $timeLimit = "00:" . $r['time_limit'] . ":00";
                }
            }
            // Data limit
            if ($r['data_limit'] > 0 && !empty($r['data_unit'])) {
                if (strcasecmp($r['data_unit'], 'GB') === 0) {
                    $dataLimit = $r['data_limit'] . "000000000";
                } else {
                    $dataLimit = $r['data_limit'] . "000000";
                }
            }
        }

        $lines[] = "Hotspot,{$r['username']},{$r['password']},{$r['profile_name']},{$timeLimit},{$dataLimit}";
    }
}

// Mark as delivered
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = $pdo->prepare("
        UPDATE `proxy_user_queue`
        SET status = 'delivered', delivered_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $update->execute($ids);
}

// Output lines for router to parse
echo implode("\n", $lines);
