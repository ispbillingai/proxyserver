<?php
// Cron script: delete records older than 10 minutes
// Run via: */2 * * * * php /var/www/html/proxyserver/cleanup.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = getDB();

$maxAge = CLEANUP_MAX_AGE;

$cutoff = date('Y-m-d H:i:s', time() - $maxAge);
$stmt = $db->prepare("DELETE FROM cached_users WHERE pushed_at < :cutoff");
$stmt->execute([':cutoff' => $cutoff]);

$deleted = $stmt->rowCount();

if ($deleted > 0) {
    echo date('Y-m-d H:i:s') . " Cleaned up $deleted expired records\n";
}

// proxy_user_queue: delete anything older than 5 minutes (pending or delivered)
$queueCutoff = date('Y-m-d H:i:s', time() - 300);
$stmt2 = $db->prepare("DELETE FROM proxy_user_queue WHERE created_at < :cutoff");
try {
    $stmt2->execute([':cutoff' => $queueCutoff]);
    $deleted2 = $stmt2->rowCount();
    if ($deleted2 > 0) {
        echo date('Y-m-d H:i:s') . " Cleaned up $deleted2 proxy_user_queue records\n";
    }
} catch (PDOException $e) {
    // Table may not exist yet; ignore
}
