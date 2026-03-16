<?php
// Cron script: collects server metrics every minute
// Run via: * * * * * php /var/www/html/proxyserver/metrics.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = getDB();

// Auto-create metrics table
$db->exec("CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    cpu_percent FLOAT DEFAULT 0,
    ram_used_mb FLOAT DEFAULT 0,
    ram_total_mb FLOAT DEFAULT 0,
    ram_percent FLOAT DEFAULT 0,
    disk_used_gb FLOAT DEFAULT 0,
    disk_total_gb FLOAT DEFAULT 0,
    disk_percent FLOAT DEFAULT 0,
    mysql_connections INT DEFAULT 0,
    mysql_queries INT DEFAULT 0,
    apache_workers INT DEFAULT 0,
    load_avg_1 FLOAT DEFAULT 0,
    load_avg_5 FLOAT DEFAULT 0,
    load_avg_15 FLOAT DEFAULT 0,
    cached_users_count INT DEFAULT 0,
    KEY idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Collect metrics
$metrics = [];

// CPU usage
$load = @sys_getloadavg();
$metrics['load_avg_1'] = $load ? round($load[0], 2) : 0;
$metrics['load_avg_5'] = $load ? round($load[1], 2) : 0;
$metrics['load_avg_15'] = $load ? round($load[2], 2) : 0;

// CPU percent (from /proc/stat snapshot)
$cpuPercent = 0;
$cpuLine = @shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2}'");
if ($cpuLine !== null) {
    $cpuPercent = round((float)trim($cpuLine), 1);
}
$metrics['cpu_percent'] = $cpuPercent;

// RAM
$meminfo = @file_get_contents('/proc/meminfo');
$ramTotal = 0;
$ramAvail = 0;
if ($meminfo) {
    if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $ramTotal = (int)$m[1]; // kB
    if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) $ramAvail = (int)$m[1];
}
$ramUsed = $ramTotal - $ramAvail;
$metrics['ram_total_mb'] = round($ramTotal / 1024, 1);
$metrics['ram_used_mb'] = round($ramUsed / 1024, 1);
$metrics['ram_percent'] = $ramTotal > 0 ? round(($ramUsed / $ramTotal) * 100, 1) : 0;

// Disk
$diskTotal = @disk_total_space('/');
$diskFree = @disk_free_space('/');
$diskUsed = $diskTotal - $diskFree;
$metrics['disk_total_gb'] = $diskTotal ? round($diskTotal / 1073741824, 1) : 0;
$metrics['disk_used_gb'] = $diskTotal ? round($diskUsed / 1073741824, 1) : 0;
$metrics['disk_percent'] = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

// MySQL connections
$mysqlConns = 0;
$mysqlQueries = 0;
try {
    $row = $db->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();
    if ($row) $mysqlConns = (int)$row['Value'];
    $row2 = $db->query("SHOW STATUS LIKE 'Queries'")->fetch();
    if ($row2) $mysqlQueries = (int)$row2['Value'];
} catch (Throwable $e) {}
$metrics['mysql_connections'] = $mysqlConns;
$metrics['mysql_queries'] = $mysqlQueries;

// Apache/FPM workers
$apacheWorkers = 0;
$workerCount = @shell_exec("pgrep -c apache2 2>/dev/null || pgrep -c httpd 2>/dev/null || echo 0");
if ($workerCount !== null) {
    $apacheWorkers = (int)trim($workerCount);
}
// Also check php-fpm
$fpmCount = @shell_exec("pgrep -c php-fpm 2>/dev/null || echo 0");
if ($fpmCount !== null) {
    $apacheWorkers += (int)trim($fpmCount);
}
$metrics['apache_workers'] = $apacheWorkers;

// Cached users count
$metrics['cached_users_count'] = (int)$db->query("SELECT COUNT(*) c FROM cached_users")->fetch()['c'];

// Insert
$stmt = $db->prepare("INSERT INTO server_metrics
    (cpu_percent, ram_used_mb, ram_total_mb, ram_percent, disk_used_gb, disk_total_gb, disk_percent,
     mysql_connections, mysql_queries, apache_workers, load_avg_1, load_avg_5, load_avg_15, cached_users_count)
    VALUES (:cpu_percent, :ram_used_mb, :ram_total_mb, :ram_percent, :disk_used_gb, :disk_total_gb, :disk_percent,
     :mysql_connections, :mysql_queries, :apache_workers, :load_avg_1, :load_avg_5, :load_avg_15, :cached_users_count)");
$stmt->execute($metrics);

// Cleanup old metrics (keep 24 hours)
$db->exec("DELETE FROM server_metrics WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

echo date('Y-m-d H:i:s') . " Metrics recorded\n";
