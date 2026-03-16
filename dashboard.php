<?php
// Proxy Cache Server Dashboard

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ?action=dashboard');
    exit;
}

// Auth
if (!isset($_SESSION['dash_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === DASHBOARD_PASSWORD) {
            $_SESSION['dash_auth'] = true;
        } else {
            $error = 'Invalid password';
        }
    }
    if (!isset($_SESSION['dash_auth'])) {
        ?>
        <!DOCTYPE html>
        <html><head><title>Proxy Server - Login</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#0f172a;display:flex;justify-content:center;align-items:center;height:100vh;}
        .login{background:#1e293b;padding:40px;border-radius:12px;width:360px;box-shadow:0 25px 50px rgba(0,0,0,0.3);}
        .login h2{color:#f1f5f9;font-size:20px;margin-bottom:6px;}
        .login p{color:#64748b;font-size:13px;margin-bottom:24px;}
        .login .logo{width:48px;height:48px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:22px;color:#fff;font-weight:700;}
        input{width:100%;padding:12px 14px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:14px;margin-bottom:16px;outline:none;transition:border 0.2s;}
        input:focus{border-color:#6366f1;}
        button{width:100%;padding:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;color:#fff;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity 0.2s;}
        button:hover{opacity:0.9;}
        .err{color:#f87171;font-size:13px;margin-bottom:12px;}
        </style></head>
        <body><div class="login">
        <div class="logo">P</div>
        <h2>Proxy Cache Server</h2>
        <p>Sign in to access the dashboard</p>
        <?php if (isset($error)) echo "<p class='err'>$error</p>"; ?>
        <form method="POST"><input type="password" name="password" placeholder="Enter password" autofocus>
        <button type="submit">Sign In</button></form></div></body></html>
        <?php
        exit;
    }
}

// POST actions
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['init_db'])) {
        try {
            $db = getDB();
            // Also create metrics table
            $db->exec("CREATE TABLE IF NOT EXISTS server_metrics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                cpu_percent FLOAT DEFAULT 0,
                ram_used_mb FLOAT DEFAULT 0, ram_total_mb FLOAT DEFAULT 0, ram_percent FLOAT DEFAULT 0,
                disk_used_gb FLOAT DEFAULT 0, disk_total_gb FLOAT DEFAULT 0, disk_percent FLOAT DEFAULT 0,
                mysql_connections INT DEFAULT 0, mysql_queries INT DEFAULT 0, apache_workers INT DEFAULT 0,
                load_avg_1 FLOAT DEFAULT 0, load_avg_5 FLOAT DEFAULT 0, load_avg_15 FLOAT DEFAULT 0,
                cached_users_count INT DEFAULT 0,
                KEY idx_recorded (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $message = 'All database tables created/verified successfully.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    if (isset($_POST['purge_all'])) {
        try { $db = getDB(); $db->exec("DELETE FROM cached_users"); $message = 'All cached records purged.'; $messageType = 'success'; } catch (Throwable $e) { $message = 'Error: ' . $e->getMessage(); $messageType = 'error'; }
    }
    if (isset($_POST['run_cleanup'])) {
        try { $db = getDB(); $stmt = $db->prepare("DELETE FROM cached_users WHERE pushed_at < DATE_SUB(NOW(), INTERVAL :age SECOND)"); $stmt->execute([':age' => CLEANUP_MAX_AGE]); $message = 'Cleanup done. Removed ' . $stmt->rowCount() . ' expired records.'; $messageType = 'success'; } catch (Throwable $e) { $message = 'Error: ' . $e->getMessage(); $messageType = 'error'; }
    }
}

$db = getDB();
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'last_push';
$dir  = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'ASC' : 'DESC';
$nextDir = $dir === 'DESC' ? 'asc' : 'desc';

// Stats
$totalUsers = $db->query("SELECT COUNT(*) c FROM cached_users")->fetch()['c'];
$totalTenants = $db->query("SELECT COUNT(DISTINCT tenant) c FROM cached_users")->fetch()['c'];
$totalRouters = $db->query("SELECT COUNT(DISTINCT CONCAT(tenant,'|',router_name)) c FROM cached_users")->fetch()['c'];
$recentPush = $db->query("SELECT MAX(pushed_at) m FROM cached_users")->fetch()['m'];
$recentAgo = $recentPush ? (time() - strtotime($recentPush)) : null;

// Tenant stats with push rate (pushes in last 5 min)
$tenantStats = $db->query("SELECT tenant, COUNT(*) as user_count,
    COUNT(DISTINCT router_name) as router_count,
    MAX(pushed_at) as last_push,
    MIN(pushed_at) as first_push
    FROM cached_users GROUP BY tenant ORDER BY
    " . ($sort === 'users' ? 'user_count' : ($sort === 'routers' ? 'router_count' : ($sort === 'tenant' ? 'tenant' : 'last_push'))) . " $dir")->fetchAll();

$routerStats = $db->query("SELECT tenant, router_name, COUNT(*) as user_count,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant, router_name ORDER BY
    " . ($sort === 'users' ? 'user_count' : ($sort === 'router' ? 'router_name' : ($sort === 'tenant' ? 'tenant' : 'last_push'))) . " $dir LIMIT 100")->fetchAll();

$recentUsers = $db->query("SELECT tenant, router_name, username, profile_name, type, pushed_at
    FROM cached_users ORDER BY pushed_at DESC LIMIT 50")->fetchAll();

// Top pushers (who sends most)
$topPushers = $db->query("SELECT tenant, COUNT(*) as total_users, COUNT(DISTINCT router_name) as routers,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant ORDER BY total_users DESC LIMIT 20")->fetchAll();

// Server health metrics (last 60 entries = ~1 hour if cron runs every minute)
$healthData = [];
$hasMetrics = false;
try {
    $test = $db->query("SELECT 1 FROM server_metrics LIMIT 1");
    $hasMetrics = true;
    $healthData = $db->query("SELECT * FROM server_metrics ORDER BY recorded_at DESC LIMIT 60")->fetchAll();
    $healthData = array_reverse($healthData); // chronological order for charts
} catch (Throwable $e) {}

$latestHealth = !empty($healthData) ? end($healthData) : null;

// MySQL status
$mysqlStatus = [];
try {
    foreach (['Threads_connected','Max_used_connections','Queries','Uptime','Bytes_received','Bytes_sent','Slow_queries','Open_tables','Table_open_cache'] as $var) {
        $r = $db->query("SHOW STATUS LIKE '$var'")->fetch();
        if ($r) $mysqlStatus[$var] = $r['Value'];
    }
    foreach (['max_connections','innodb_buffer_pool_size','query_cache_size','max_allowed_packet'] as $var) {
        $r = $db->query("SHOW VARIABLES LIKE '$var'")->fetch();
        if ($r) $mysqlStatus[$var] = $r['Value'];
    }
} catch (Throwable $e) {}

function sortLink($field, $label, $currentSort, $currentDir) {
    $page = $_GET['page'] ?? 'overview';
    $arrow = '';
    $nd = 'desc';
    if ($currentSort === $field) {
        $arrow = $currentDir === 'DESC' ? ' &#9660;' : ' &#9650;';
        $nd = $currentDir === 'DESC' ? 'asc' : 'desc';
    }
    return "<a href='?action=dashboard&page=$page&sort=$field&dir=$nd' style='color:#64748b;text-decoration:none;'>$label$arrow</a>";
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function formatUptime($seconds) {
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    return ($d > 0 ? "{$d}d " : '') . "{$h}h {$m}m";
}

function statusBadge($ago) {
    if ($ago < 60) return ['green', 'Active'];
    if ($ago < 300) return ['amber', 'Idle'];
    return ['red', 'Stale'];
}

function progressBar($percent, $color = '#6366f1') {
    $bg = 'rgba(255,255,255,0.05)';
    $w = min(100, max(0, $percent));
    if ($percent > 80) $color = '#ef4444';
    elseif ($percent > 60) $color = '#f59e0b';
    return "<div style='background:$bg;border-radius:4px;height:8px;width:100%;overflow:hidden;'><div style='background:$color;height:100%;width:{$w}%;border-radius:4px;transition:width 0.3s;'></div></div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Proxy Cache Server</title>
    <meta http-equiv="refresh" content="30">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #e2e8f0; display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: #1e293b; border-right: 1px solid #334155; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 10; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #334155; }
        .sidebar-header .brand { display: flex; align-items: center; gap: 10px; }
        .sidebar-header .logo { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; font-size: 16px; flex-shrink: 0; }
        .sidebar-header h1 { font-size: 15px; color: #f1f5f9; font-weight: 600; }
        .sidebar-header p { font-size: 11px; color: #64748b; margin-top: 2px; }
        .sidebar-nav { padding: 12px; flex: 1; overflow-y: auto; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: #94a3b8; font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all 0.15s; }
        .sidebar-nav a:hover { background: #334155; color: #e2e8f0; }
        .sidebar-nav a.active { background: #6366f1; color: #fff; }
        .sidebar-nav a .icon { width: 18px; text-align: center; font-style: normal; }
        .sidebar-nav .section-label { font-size: 10px; color: #475569; text-transform: uppercase; letter-spacing: 1px; padding: 16px 12px 6px; font-weight: 600; }
        .sidebar-footer { padding: 16px; border-top: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
        .sidebar-footer a { color: #ef4444; font-size: 13px; text-decoration: none; font-weight: 500; }
        .sidebar-footer .ver { font-size: 11px; color: #475569; }

        .main { margin-left: 250px; flex: 1; padding: 28px; min-height: 100vh; }
        .page-header { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; }
        .page-header h2 { font-size: 20px; font-weight: 600; color: #f1f5f9; }
        .page-header p { font-size: 13px; color: #64748b; margin-top: 4px; }
        .page-header .live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #4ade80; background: rgba(34,197,94,0.1); padding: 4px 10px; border-radius: 20px; }
        .page-header .live .pulse { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.4;} }

        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .card .card-label { font-size: 11px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .card .card-value { font-size: 28px; font-weight: 700; color: #f1f5f9; margin-top: 6px; }
        .card .card-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
        .card-accent { border-left: 3px solid #6366f1; }
        .card-green { border-left: 3px solid #22c55e; }
        .card-amber { border-left: 3px solid #f59e0b; }
        .card-red { border-left: 3px solid #ef4444; }
        .card-cyan { border-left: 3px solid #06b6d4; }

        .table-wrap { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; margin-bottom: 24px; }
        .table-header { padding: 16px 20px; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
        .table-header h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; }
        .table-header span { font-size: 12px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px 20px; text-align: left; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; background: #1e293b; border-bottom: 1px solid #334155; }
        td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid rgba(51,65,85,0.5); color: #cbd5e1; }
        tbody tr { background: #0f172a; }
        tbody tr:hover { background: #1e293b; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .badge-green { background: rgba(34,197,94,0.15); color: #4ade80; }
        .badge-amber { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-red { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-blue { background: rgba(99,102,241,0.15); color: #818cf8; }
        .badge-cyan { background: rgba(6,182,212,0.15); color: #22d3ee; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
        .dot-amber { background: #f59e0b; }
        .dot-red { background: #ef4444; }

        .tool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .tool-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .tool-card h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px; }
        .tool-card p { font-size: 12px; color: #64748b; margin-bottom: 14px; }
        .btn { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #fff; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.85; }
        .btn-indigo { background: #6366f1; }
        .btn-green { background: #22c55e; }
        .btn-red { background: #ef4444; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }

        .health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .health-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .health-card h4 { font-size: 13px; color: #94a3b8; font-weight: 500; margin-bottom: 8px; }
        .health-card .val { font-size: 24px; font-weight: 700; color: #f1f5f9; margin-bottom: 8px; }
        .health-card .sub { font-size: 11px; color: #64748b; }

        .chart-row { display: flex; align-items: flex-end; gap: 2px; height: 60px; margin-top: 10px; }
        .chart-bar { flex: 1; background: #6366f1; border-radius: 2px 2px 0 0; min-width: 3px; transition: height 0.3s; position: relative; }
        .chart-bar:hover { background: #818cf8; }
        .chart-bar[title]:hover::after { content: attr(title); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1e293b; color: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 10px; white-space: nowrap; border: 1px solid #334155; }

        .empty-state { text-align: center; padding: 40px; color: #475569; }
        .empty-state .icon { font-size: 36px; margin-bottom: 10px; }

        .rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .rank-1 { background: rgba(245,158,11,0.2); color: #fbbf24; }
        .rank-2 { background: rgba(148,163,184,0.2); color: #94a3b8; }
        .rank-3 { background: rgba(180,83,9,0.2); color: #d97706; }
        .rank-n { background: rgba(51,65,85,0.3); color: #64748b; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <div class="logo">P</div>
                <div>
                    <h1>Proxy Server</h1>
                    <p>Cache Dashboard</p>
                </div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="section-label">Monitor</div>
            <a href="?action=dashboard&page=overview" class="<?= $page === 'overview' ? 'active' : '' ?>">
                <span class="icon">&#9632;</span> Overview
            </a>
            <a href="?action=dashboard&page=tenants" class="<?= $page === 'tenants' ? 'active' : '' ?>">
                <span class="icon">&#9830;</span> Tenants
            </a>
            <a href="?action=dashboard&page=routers" class="<?= $page === 'routers' ? 'active' : '' ?>">
                <span class="icon">&#8860;</span> Routers
            </a>
            <a href="?action=dashboard&page=users" class="<?= $page === 'users' ? 'active' : '' ?>">
                <span class="icon">&#9679;</span> Recent Users
            </a>
            <a href="?action=dashboard&page=top" class="<?= $page === 'top' ? 'active' : '' ?>">
                <span class="icon">&#9733;</span> Top Pushers
            </a>
            <div class="section-label">System</div>
            <a href="?action=dashboard&page=health" class="<?= $page === 'health' ? 'active' : '' ?>">
                <span class="icon">&#9829;</span> Server Health
            </a>
            <a href="?action=dashboard&page=mysql" class="<?= $page === 'mysql' ? 'active' : '' ?>">
                <span class="icon">&#9707;</span> MySQL
            </a>
            <a href="?action=dashboard&page=tools" class="<?= $page === 'tools' ? 'active' : '' ?>">
                <span class="icon">&#9881;</span> Tools
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="?action=logout">Sign Out</a>
            <span class="ver">v1.0</span>
        </div>
    </div>

    <div class="main">

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($page === 'overview'): ?>
        <div class="page-header">
            <div>
                <h2>Overview</h2>
                <p>Server time: <?= date('Y-m-d H:i:s') ?></p>
            </div>
            <div class="live"><span class="pulse"></span> Live</div>
        </div>

        <div class="cards">
            <div class="card card-accent">
                <div class="card-label">Cached Users</div>
                <div class="card-value"><?= number_format($totalUsers) ?></div>
                <div class="card-sub">Active in proxy</div>
            </div>
            <div class="card card-green">
                <div class="card-label">Tenants</div>
                <div class="card-value"><?= $totalTenants ?></div>
                <div class="card-sub">Pushing data</div>
            </div>
            <div class="card card-amber">
                <div class="card-label">Routers</div>
                <div class="card-value"><?= $totalRouters ?></div>
                <div class="card-sub">Across all tenants</div>
            </div>
            <div class="card card-red">
                <div class="card-label">Last Push</div>
                <div class="card-value" style="font-size:20px;"><?= $recentAgo !== null ? $recentAgo . 's ago' : '--' ?></div>
                <div class="card-sub"><?= $recentPush ?: 'No data yet' ?></div>
            </div>
            <?php if ($latestHealth): ?>
            <div class="card card-cyan">
                <div class="card-label">RAM Usage</div>
                <div class="card-value" style="font-size:20px;"><?= $latestHealth['ram_percent'] ?>%</div>
                <div class="card-sub"><?= $latestHealth['ram_used_mb'] ?> / <?= $latestHealth['ram_total_mb'] ?> MB</div>
                <?= progressBar($latestHealth['ram_percent']) ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>Tenants Summary</h3><span><?= count($tenantStats) ?> tenant(s)</span></div>
            <table>
                <thead><tr>
                    <th><?= sortLink('tenant', 'Tenant', $sort, $dir) ?></th>
                    <th><?= sortLink('users', 'Users', $sort, $dir) ?></th>
                    <th><?= sortLink('routers', 'Routers', $sort, $dir) ?></th>
                    <th><?= sortLink('last_push', 'Last Push', $sort, $dir) ?></th>
                    <th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t):
                    $ago = time() - strtotime($t['last_push']);
                    [$cls, $label] = statusBadge($ago);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td>
                    <td><?= $t['user_count'] ?></td>
                    <td><?= $t['router_count'] ?></td>
                    <td><?= $t['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s)</span></td>
                    <td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tenantStats)): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="icon">&#128230;</div>No tenants pushing data yet</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'tenants'): ?>
        <div class="page-header"><div><h2>Tenants</h2><p>All tenants currently caching data</p></div></div>
        <div class="table-wrap">
            <div class="table-header"><h3>All Tenants</h3><span><?= count($tenantStats) ?> total</span></div>
            <table>
                <thead><tr><th><?= sortLink('tenant', 'Tenant', $sort, $dir) ?></th><th><?= sortLink('users', 'Users', $sort, $dir) ?></th><th><?= sortLink('routers', 'Routers', $sort, $dir) ?></th><th><?= sortLink('last_push', 'Last Push', $sort, $dir) ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t): $ago = time() - strtotime($t['last_push']); [$cls, $label] = statusBadge($ago); ?>
                <tr><td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td><td><?= $t['user_count'] ?></td><td><?= $t['router_count'] ?></td><td><?= $t['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s)</span></td><td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($tenantStats)): ?><tr><td colspan="5"><div class="empty-state">No tenants yet</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'routers'): ?>
        <div class="page-header"><div><h2>Routers</h2><p>All routers across tenants</p></div></div>
        <div class="table-wrap">
            <div class="table-header"><h3>All Routers</h3><span><?= count($routerStats) ?> shown</span></div>
            <table>
                <thead><tr><th><?= sortLink('tenant', 'Tenant', $sort, $dir) ?></th><th><?= sortLink('router', 'Router', $sort, $dir) ?></th><th><?= sortLink('users', 'Users', $sort, $dir) ?></th><th><?= sortLink('last_push', 'Last Push', $sort, $dir) ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($routerStats as $r): $ago = time() - strtotime($r['last_push']); [$cls, $label] = statusBadge($ago); ?>
                <tr><td><span class="badge badge-blue"><?= htmlspecialchars($r['tenant']) ?></span></td><td><strong><?= htmlspecialchars($r['router_name']) ?></strong></td><td><?= $r['user_count'] ?></td><td><?= $r['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s)</span></td><td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($routerStats)): ?><tr><td colspan="5"><div class="empty-state">No routers yet</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'users'): ?>
        <div class="page-header"><div><h2>Recent Users</h2><p>Last 50 users pushed to the cache</p></div></div>
        <div class="table-wrap">
            <div class="table-header"><h3>Recent Activity</h3><span>Last 50 entries</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Router</th><th>Username</th><th>Profile</th><th>Type</th><th>Pushed At</th></tr></thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                <tr><td><span class="badge badge-blue"><?= htmlspecialchars($u['tenant']) ?></span></td><td><?= htmlspecialchars($u['router_name']) ?></td><td><strong><?= htmlspecialchars($u['username']) ?></strong></td><td><?= htmlspecialchars($u['profile_name']) ?></td><td><span class="badge badge-green"><?= htmlspecialchars($u['type']) ?></span></td><td><?= $u['pushed_at'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($recentUsers)): ?><tr><td colspan="6"><div class="empty-state">No users cached yet</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'top'): ?>
        <div class="page-header"><div><h2>Top Pushers</h2><p>Tenants ranked by cached user count</p></div></div>
        <div class="table-wrap">
            <div class="table-header"><h3>Leaderboard</h3><span>Top 20</span></div>
            <table>
                <thead><tr><th>#</th><th>Tenant</th><th>Users</th><th>Routers</th><th>Last Push</th><th>Share</th></tr></thead>
                <tbody>
                <?php $rank = 0; foreach ($topPushers as $t): $rank++;
                    $share = $totalUsers > 0 ? round(($t['total_users'] / $totalUsers) * 100, 1) : 0;
                    $rcls = $rank <= 3 ? "rank-$rank" : 'rank-n';
                ?>
                <tr>
                    <td><span class="rank <?= $rcls ?>"><?= $rank ?></span></td>
                    <td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td>
                    <td><?= number_format($t['total_users']) ?></td>
                    <td><?= $t['routers'] ?></td>
                    <td><?= $t['last_push'] ?></td>
                    <td><?= $share ?>% <?= progressBar($share, '#6366f1') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topPushers)): ?><tr><td colspan="6"><div class="empty-state">No data yet</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'health'): ?>
        <div class="page-header"><div><h2>Server Health</h2><p>System resources over the last hour</p></div></div>

        <?php if (!$hasMetrics || empty($healthData)): ?>
        <div class="alert alert-error">No metrics data yet. Set up the cron job: <code style="background:#334155;padding:2px 8px;border-radius:4px;">* * * * * php /var/www/html/proxyserver/metrics.php</code></div>
        <?php else: ?>

        <div class="health-grid">
            <div class="health-card">
                <h4>CPU Usage</h4>
                <div class="val"><?= $latestHealth['cpu_percent'] ?>%</div>
                <?= progressBar($latestHealth['cpu_percent']) ?>
                <div class="chart-row">
                    <?php $maxCpu = max(array_column($healthData, 'cpu_percent')) ?: 1;
                    foreach ($healthData as $h): $pct = ($h['cpu_percent'] / $maxCpu) * 100; ?>
                    <div class="chart-bar" style="height:<?= max(2, $pct) ?>%;background:<?= $h['cpu_percent'] > 80 ? '#ef4444' : ($h['cpu_percent'] > 50 ? '#f59e0b' : '#6366f1') ?>;" title="<?= $h['recorded_at'] ?>: <?= $h['cpu_percent'] ?>%"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub" style="margin-top:6px;">Load: <?= $latestHealth['load_avg_1'] ?> / <?= $latestHealth['load_avg_5'] ?> / <?= $latestHealth['load_avg_15'] ?></div>
            </div>

            <div class="health-card">
                <h4>RAM Usage</h4>
                <div class="val"><?= $latestHealth['ram_used_mb'] ?> MB <span style="font-size:14px;color:#64748b;">/ <?= $latestHealth['ram_total_mb'] ?> MB</span></div>
                <?= progressBar($latestHealth['ram_percent']) ?>
                <div class="chart-row">
                    <?php foreach ($healthData as $h): $pct = $h['ram_percent']; ?>
                    <div class="chart-bar" style="height:<?= max(2, $pct) ?>%;background:<?= $pct > 80 ? '#ef4444' : ($pct > 60 ? '#f59e0b' : '#22c55e') ?>;" title="<?= $h['recorded_at'] ?>: <?= $h['ram_percent'] ?>%"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub" style="margin-top:6px;"><?= $latestHealth['ram_percent'] ?>% used</div>
            </div>

            <div class="health-card">
                <h4>Disk Usage</h4>
                <div class="val"><?= $latestHealth['disk_used_gb'] ?> GB <span style="font-size:14px;color:#64748b;">/ <?= $latestHealth['disk_total_gb'] ?> GB</span></div>
                <?= progressBar($latestHealth['disk_percent']) ?>
                <div class="sub" style="margin-top:6px;"><?= $latestHealth['disk_percent'] ?>% used</div>
            </div>

            <div class="health-card">
                <h4>Apache/PHP Workers</h4>
                <div class="val"><?= $latestHealth['apache_workers'] ?></div>
                <div class="chart-row">
                    <?php $maxW = max(array_column($healthData, 'apache_workers')) ?: 1;
                    foreach ($healthData as $h): $pct = ($h['apache_workers'] / $maxW) * 100; ?>
                    <div class="chart-bar" style="height:<?= max(2, $pct) ?>%;background:#06b6d4;" title="<?= $h['recorded_at'] ?>: <?= $h['apache_workers'] ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub" style="margin-top:6px;">Active processes</div>
            </div>

            <div class="health-card">
                <h4>MySQL Connections</h4>
                <div class="val"><?= $latestHealth['mysql_connections'] ?></div>
                <div class="chart-row">
                    <?php $maxC = max(array_column($healthData, 'mysql_connections')) ?: 1;
                    foreach ($healthData as $h): $pct = ($h['mysql_connections'] / $maxC) * 100; ?>
                    <div class="chart-bar" style="height:<?= max(2, $pct) ?>%;background:#8b5cf6;" title="<?= $h['recorded_at'] ?>: <?= $h['mysql_connections'] ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub" style="margin-top:6px;">Active threads</div>
            </div>

            <div class="health-card">
                <h4>Cached Users</h4>
                <div class="val"><?= number_format($latestHealth['cached_users_count']) ?></div>
                <div class="chart-row">
                    <?php $maxU = max(array_column($healthData, 'cached_users_count')) ?: 1;
                    foreach ($healthData as $h): $pct = ($h['cached_users_count'] / $maxU) * 100; ?>
                    <div class="chart-bar" style="height:<?= max(2, $pct) ?>%;background:#f59e0b;" title="<?= $h['recorded_at'] ?>: <?= $h['cached_users_count'] ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub" style="margin-top:6px;">Over the last hour</div>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($page === 'mysql'): ?>
        <div class="page-header"><div><h2>MySQL Status</h2><p>Database connection and performance details</p></div></div>

        <div class="cards">
            <div class="card card-accent">
                <div class="card-label">Connections</div>
                <div class="card-value"><?= $mysqlStatus['Threads_connected'] ?? '--' ?></div>
                <div class="card-sub">Max used: <?= $mysqlStatus['Max_used_connections'] ?? '--' ?></div>
            </div>
            <div class="card card-green">
                <div class="card-label">Max Allowed</div>
                <div class="card-value"><?= $mysqlStatus['max_connections'] ?? '--' ?></div>
                <div class="card-sub">max_connections setting</div>
            </div>
            <div class="card card-amber">
                <div class="card-label">Total Queries</div>
                <div class="card-value" style="font-size:20px;"><?= isset($mysqlStatus['Queries']) ? number_format($mysqlStatus['Queries']) : '--' ?></div>
                <div class="card-sub">Since server start</div>
            </div>
            <div class="card card-red">
                <div class="card-label">Uptime</div>
                <div class="card-value" style="font-size:20px;"><?= isset($mysqlStatus['Uptime']) ? formatUptime($mysqlStatus['Uptime']) : '--' ?></div>
                <div class="card-sub">MySQL server</div>
            </div>
        </div>

        <?php if (isset($mysqlStatus['Threads_connected'], $mysqlStatus['max_connections'])):
            $connPercent = round(($mysqlStatus['Threads_connected'] / $mysqlStatus['max_connections']) * 100, 1);
            $maxUsedPercent = isset($mysqlStatus['Max_used_connections']) ? round(($mysqlStatus['Max_used_connections'] / $mysqlStatus['max_connections']) * 100, 1) : 0;
        ?>
        <div class="health-grid">
            <div class="health-card">
                <h4>Connection Usage</h4>
                <div class="val"><?= $connPercent ?>%</div>
                <?= progressBar($connPercent) ?>
                <div class="sub" style="margin-top:6px;"><?= $mysqlStatus['Threads_connected'] ?> of <?= $mysqlStatus['max_connections'] ?> connections</div>
            </div>
            <div class="health-card">
                <h4>Peak Connection Usage</h4>
                <div class="val"><?= $maxUsedPercent ?>%</div>
                <?= progressBar($maxUsedPercent) ?>
                <div class="sub" style="margin-top:6px;">Max used: <?= $mysqlStatus['Max_used_connections'] ?> of <?= $mysqlStatus['max_connections'] ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="table-wrap">
            <div class="table-header"><h3>MySQL Variables & Status</h3></div>
            <table>
                <thead><tr><th>Variable</th><th>Value</th></tr></thead>
                <tbody style="background:#1e293b;">
                <?php foreach ($mysqlStatus as $k => $v): ?>
                <tr>
                    <td style="color:#94a3b8;font-family:monospace;font-size:12px;"><?= htmlspecialchars($k) ?></td>
                    <td><?php
                        if (in_array($k, ['innodb_buffer_pool_size', 'max_allowed_packet', 'query_cache_size', 'Bytes_received', 'Bytes_sent'])) echo formatBytes((int)$v);
                        elseif ($k === 'Uptime') echo formatUptime((int)$v);
                        else echo htmlspecialchars(number_format((float)$v));
                    ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'tools'): ?>
        <div class="page-header"><div><h2>Tools</h2><p>System maintenance and utilities</p></div></div>

        <div class="tool-grid">
            <div class="tool-card">
                <h3>Initialize Database</h3>
                <p>Creates all tables (cached_users + server_metrics). Safe to run anytime.</p>
                <form method="POST"><button type="submit" name="init_db" value="1" class="btn btn-indigo">Create / Verify Tables</button></form>
            </div>
            <div class="tool-card">
                <h3>Run Cleanup Now</h3>
                <p>Delete expired records older than <?= CLEANUP_MAX_AGE / 60 ?> minutes.</p>
                <form method="POST"><button type="submit" name="run_cleanup" value="1" class="btn btn-green">Run Cleanup</button></form>
            </div>
            <div class="tool-card">
                <h3>Purge All Data</h3>
                <p>Delete ALL cached records. Routers will re-populate on next cycle.</p>
                <form method="POST" onsubmit="return confirm('Delete all cached data?')"><button type="submit" name="purge_all" value="1" class="btn btn-red">Purge All Records</button></form>
            </div>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>System Info</h3></div>
            <table>
                <tbody style="background:#1e293b;">
                <tr><td style="color:#64748b;width:200px;">PHP Version</td><td><?= phpversion() ?></td></tr>
                <tr><td style="color:#64748b;">Server Time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
                <tr><td style="color:#64748b;">Server Software</td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></td></tr>
                <tr><td style="color:#64748b;">Cleanup Interval</td><td><?= CLEANUP_MAX_AGE / 60 ?> minutes</td></tr>
                <tr><td style="color:#64748b;">Total Records</td><td><?= number_format($totalUsers) ?></td></tr>
                <tr><td style="color:#64748b;">MySQL Host</td><td><?= htmlspecialchars($db_host) ?></td></tr>
                <tr><td style="color:#64748b;">Database</td><td><?= htmlspecialchars($db_name) ?></td></tr>
                <tr><td style="color:#64748b;">PHP Memory Limit</td><td><?= ini_get('memory_limit') ?></td></tr>
                <tr><td style="color:#64748b;">Max Execution Time</td><td><?= ini_get('max_execution_time') ?>s</td></tr>
                <tr><td style="color:#64748b;">Upload Max Size</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    </div>
</body>
</html>
