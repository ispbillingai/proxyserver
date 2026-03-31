<?php
// Cron: deletes mikrotik_active_users older than 5 minutes
// Add to crontab: * * * * * php /var/www/html/proxyserver/mikrotik_cleanup.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = getDB();

try {
    $stmt = $db->exec("DELETE FROM mikrotik_active_users WHERE last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    echo "Cleaned up $stmt expired mikrotik users\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
