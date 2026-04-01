<?php
// Proxy Cache Server Dashboard

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Session persists for 1 year
$sessDir = __DIR__ . '/data/sessions';
if (!is_dir($sessDir)) mkdir($sessDir, 0755, true);
ini_set('session.save_path', $sessDir);
ini_set('session.gc_maxlifetime', 31536000);
ini_set('session.gc_probability', 0); // disable GC - we don't want sessions deleted
session_set_cookie_params(31536000, '/', '', false, true);
session_start();

// Remember token helpers
function ensureRememberTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash VARCHAR(64) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uq_token (token_hash),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function createRememberToken() {
    ensureRememberTable();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $db = getDB();
    // Clean expired tokens
    $db->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
    $stmt = $db->prepare("INSERT INTO remember_tokens (token_hash, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 YEAR))");
    $stmt->execute([$hash]);
    setcookie('remember_token', $token, time() + 31536000, '/', '', false, true);
}

function validateRememberToken() {
    if (empty($_COOKIE['remember_token'])) return false;
    ensureRememberTable();
    $hash = hash('sha256', $_COOKIE['remember_token']);
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->execute([$hash]);
    return $stmt->fetch() !== false;
}

function clearRememberToken() {
    if (!empty($_COOKIE['remember_token'])) {
        ensureRememberTable();
        $hash = hash('sha256', $_COOKIE['remember_token']);
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
        $stmt->execute([$hash]);
    }
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    clearRememberToken();
    session_destroy();
    header('Location: ?action=dashboard');
    exit;
}

// Auth - check remember token if session is missing
if (!isset($_SESSION['dash_auth'])) {
    if (validateRememberToken()) {
        $_SESSION['dash_auth'] = true;
    }
}

if (!isset($_SESSION['dash_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === DASHBOARD_PASSWORD) {
            $_SESSION['dash_auth'] = true;
            createRememberToken();
        } else {
            $error = 'Invalid password';
        }
    }
    if (!isset($_SESSION['dash_auth'])) {
        ?>
        <!DOCTYPE html>
        <html><head><title>Proxy Server - Login</title>
        <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#0f172a;display:flex;justify-content:center;align-items:center;height:100vh;}
        .login{background:#1e293b;padding:40px;border-radius:12px;width:360px;box-shadow:0 25px 50px rgba(0,0,0,0.3);}
        .login h2{color:#f1f5f9;font-size:20px;margin-bottom:6px;}
        .login p{color:#64748b;font-size:13px;margin-bottom:24px;}
        .login .logo{width:48px;height:48px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:22px;color:#fff;font-weight:700;}
        input{width:100%;padding:12px 14px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:14px;margin-bottom:16px;outline:none;}
        input:focus{border-color:#6366f1;}
        button{width:100%;padding:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;color:#fff;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}
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
            $db->exec("CREATE TABLE IF NOT EXISTS server_metrics (
                id INT AUTO_INCREMENT PRIMARY KEY, recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                cpu_percent FLOAT DEFAULT 0, ram_used_mb FLOAT DEFAULT 0, ram_total_mb FLOAT DEFAULT 0, ram_percent FLOAT DEFAULT 0,
                disk_used_gb FLOAT DEFAULT 0, disk_total_gb FLOAT DEFAULT 0, disk_percent FLOAT DEFAULT 0,
                mysql_connections INT DEFAULT 0, mysql_queries INT DEFAULT 0, apache_workers INT DEFAULT 0,
                load_avg_1 FLOAT DEFAULT 0, load_avg_5 FLOAT DEFAULT 0, load_avg_15 FLOAT DEFAULT 0,
                cached_users_count INT DEFAULT 0, KEY idx_recorded (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $message = 'All tables verified.';
            $messageType = 'success';
        } catch (Throwable $e) { $message = 'Error: ' . $e->getMessage(); $messageType = 'error'; }
    }
    if (isset($_POST['purge_all'])) {
        try { $db = getDB(); $db->exec("DELETE FROM cached_users"); $message = 'All records purged.'; $messageType = 'success'; } catch (Throwable $e) { $message = 'Error: ' . $e->getMessage(); $messageType = 'error'; }
    }
    if (isset($_POST['run_cleanup'])) {
        try { $db = getDB(); $cutoff = date('Y-m-d H:i:s', time() - CLEANUP_MAX_AGE); $stmt = $db->prepare("DELETE FROM cached_users WHERE pushed_at < :cutoff"); $stmt->execute([':cutoff' => $cutoff]); $message = 'Removed ' . $stmt->rowCount() . ' expired records.'; $messageType = 'success'; } catch (Throwable $e) { $message = 'Error: ' . $e->getMessage(); $messageType = 'error'; }
    }
}

$db = getDB();
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'last_push';
$dir  = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'ASC' : 'DESC';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Stats
$totalUsers = $db->query("SELECT COUNT(*) c FROM cached_users")->fetch()['c'];
$totalTenants = $db->query("SELECT COUNT(DISTINCT tenant) c FROM cached_users")->fetch()['c'];
$totalRouters = $db->query("SELECT COUNT(DISTINCT CONCAT(tenant,'|',router_id)) c FROM cached_users")->fetch()['c'];
$recentPush = $db->query("SELECT MAX(pushed_at) m FROM cached_users")->fetch()['m'];
$recentAgo = $recentPush ? (time() - strtotime($recentPush)) : null;

// Pushes per minute (last 5 min)
$fiveMinAgo = date('Y-m-d H:i:s', time() - 300);
$pushRate = $db->prepare("SELECT COUNT(*) c FROM cached_users WHERE pushed_at >= :t");
$pushRate->execute([':t' => $fiveMinAgo]);
$pushesLast5 = (int)$pushRate->fetch()['c'];
$pushesPerMin = $pushesLast5 > 0 ? round($pushesLast5 / 5, 1) : 0;

// Tenant stats
$tenantWhere = '';
$tenantParams = [];
if ($search !== '') {
    $tenantWhere = "WHERE tenant LIKE :q";
    $tenantParams[':q'] = "%$search%";
}

$orderCol = match($sort) {
    'users' => 'user_count',
    'routers' => 'router_count',
    'tenant' => 'tenant',
    default => 'last_push'
};

$tenantStmt = $db->prepare("SELECT tenant, COUNT(*) as user_count,
    COUNT(DISTINCT router_id) as router_count,
    MAX(pushed_at) as last_push
    FROM cached_users $tenantWhere GROUP BY tenant ORDER BY $orderCol $dir");
$tenantStmt->execute($tenantParams);
$tenantStats = $tenantStmt->fetchAll();

$routerWhere = $search !== '' ? "WHERE tenant LIKE :q OR CAST(router_id AS CHAR) LIKE :q2" : '';
$routerParams = $search !== '' ? [':q' => "%$search%", ':q2' => "%$search%"] : [];
$routerStmt = $db->prepare("SELECT tenant, router_id, COUNT(*) as user_count,
    MAX(pushed_at) as last_push
    FROM cached_users $routerWhere GROUP BY tenant, router_id ORDER BY $orderCol $dir LIMIT 200");
$routerStmt->execute($routerParams);
$routerStats = $routerStmt->fetchAll();

$userWhere = $search !== '' ? "WHERE tenant LIKE :q OR username LIKE :q2 OR profile_name LIKE :q3" : '';
$userParams = $search !== '' ? [':q' => "%$search%", ':q2' => "%$search%", ':q3' => "%$search%"] : [];
$userStmt = $db->prepare("SELECT tenant, router_id, username, profile_name, type, pushed_at
    FROM cached_users $userWhere ORDER BY pushed_at DESC LIMIT 100");
$userStmt->execute($userParams);
$recentUsers = $userStmt->fetchAll();

// Top pushers
$topPushers = $db->query("SELECT tenant, COUNT(*) as total_users, COUNT(DISTINCT router_id) as routers,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant ORDER BY total_users DESC LIMIT 20")->fetchAll();

// Health metrics
$healthData = [];
$hasMetrics = false;
$latestHealth = null;
try {
    $db->query("SELECT 1 FROM server_metrics LIMIT 1");
    $hasMetrics = true;
    $healthData = $db->query("SELECT * FROM server_metrics ORDER BY recorded_at DESC LIMIT 60")->fetchAll();
    $healthData = array_reverse($healthData);
    $latestHealth = !empty($healthData) ? end($healthData) : null;
} catch (Throwable $e) {}

// MikroTik data
$mikrotikRouters = [];
$mikrotikUsers = [];
$totalMikrotikUsers = 0;
$totalMikrotikRouters = 0;
try {
    $db->query("SELECT 1 FROM router_heartbeats LIMIT 1");
    $mikrotikRouters = $db->query("SELECT * FROM router_heartbeats ORDER BY last_heartbeat DESC")->fetchAll();
    $totalMikrotikRouters = count($mikrotikRouters);
    $mikrotikSearch = $search !== '' ? "WHERE tenant LIKE :q OR username LIKE :q2" : '';
    $mikrotikSearchParams = $search !== '' ? [':q' => "%$search%", ':q2' => "%$search%"] : [];
    $stmt = $db->prepare("SELECT * FROM mikrotik_active_users $mikrotikSearch ORDER BY last_seen DESC LIMIT 200");
    $stmt->execute($mikrotikSearchParams);
    $mikrotikUsers = $stmt->fetchAll();
    $totalMikrotikUsers = $db->query("SELECT COUNT(*) c FROM mikrotik_active_users")->fetch()['c'];
} catch (Throwable $e) {}

// MySQL status
$mysqlStatus = [];
try {
    foreach (['Threads_connected','Max_used_connections','Queries','Uptime','Slow_queries','Open_tables'] as $var) {
        $r = $db->query("SHOW STATUS LIKE '$var'")->fetch();
        if ($r) $mysqlStatus[$var] = $r['Value'];
    }
    foreach (['max_connections','innodb_buffer_pool_size','max_allowed_packet'] as $var) {
        $r = $db->query("SHOW VARIABLES LIKE '$var'")->fetch();
        if ($r) $mysqlStatus[$var] = $r['Value'];
    }
} catch (Throwable $e) {}

function sortLink($field, $label) {
    global $sort, $dir, $page, $search;
    $arrow = '';
    $nd = 'desc';
    if ($sort === $field) {
        $arrow = $dir === 'DESC' ? ' &#9660;' : ' &#9650;';
        $nd = $dir === 'DESC' ? 'asc' : 'desc';
    }
    $q = $search !== '' ? "&q=" . urlencode($search) : '';
    return "<a href='?action=dashboard&page=$page&sort=$field&dir=$nd$q' style='color:#64748b;text-decoration:none;'>$label$arrow</a>";
}
function formatBytes($b) { if ($b >= 1073741824) return round($b/1073741824,1).' GB'; if ($b >= 1048576) return round($b/1048576,1).' MB'; return round($b/1024,1).' KB'; }
function formatUptime($s) { $d=floor($s/86400); $h=floor(($s%86400)/3600); $m=floor(($s%3600)/60); return ($d>0?"{$d}d ":'')."{$h}h {$m}m"; }
function statusBadge($ago) { if ($ago<60) return ['green','Active']; if ($ago<300) return ['amber','Idle']; return ['red','Stale']; }
function progressBar($pct,$color='#6366f1') { $w=min(100,max(0,$pct)); if($pct>80)$color='#ef4444'; elseif($pct>60)$color='#f59e0b'; return "<div style='background:rgba(255,255,255,0.05);border-radius:4px;height:8px;width:100%;overflow:hidden;margin-top:4px;'><div style='background:$color;height:100%;width:{$w}%;border-radius:4px;'></div></div>"; }
function timeAgo($ts) { $a=time()-strtotime($ts); if($a<60) return $a.'s ago'; if($a<3600) return floor($a/60).'m ago'; if($a<86400) return floor($a/3600).'h ago'; return floor($a/86400).'d ago'; }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Proxy Cache Server</title>
    <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
    <meta http-equiv="refresh" content="15">
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
        .sidebar-nav .icon { width: 18px; text-align: center; font-style: normal; }
        .sidebar-nav .lbl { font-size: 10px; color: #475569; text-transform: uppercase; letter-spacing: 1px; padding: 16px 12px 6px; font-weight: 600; }
        .sidebar-nav .count { margin-left: auto; background: #334155; padding: 1px 7px; border-radius: 10px; font-size: 11px; color: #94a3b8; }
        .sidebar-footer { padding: 16px; border-top: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
        .sidebar-footer a { color: #ef4444; font-size: 13px; text-decoration: none; font-weight: 500; }
        .sidebar-footer .ver { font-size: 11px; color: #475569; }

        .main { margin-left: 250px; flex: 1; padding: 28px; min-height: 100vh; }
        .page-header { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 20px; font-weight: 600; color: #f1f5f9; }
        .page-header p { font-size: 13px; color: #64748b; margin-top: 4px; }
        .live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #4ade80; background: rgba(34,197,94,0.1); padding: 4px 10px; border-radius: 20px; }
        .live .pulse { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.4;} }

        .search-bar { display: flex; gap: 8px; }
        .search-bar input { padding: 8px 14px; border: 1px solid #334155; border-radius: 8px; background: #1e293b; color: #e2e8f0; font-size: 13px; width: 220px; outline: none; }
        .search-bar input:focus { border-color: #6366f1; }
        .search-bar button { padding: 8px 14px; background: #6366f1; border: none; color: #fff; border-radius: 8px; font-size: 13px; cursor: pointer; font-weight: 500; }

        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 18px; }
        .card .card-label { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .card .card-value { font-size: 26px; font-weight: 700; color: #f1f5f9; margin-top: 4px; }
        .card .card-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
        .card-accent { border-left: 3px solid #6366f1; }
        .card-green { border-left: 3px solid #22c55e; }
        .card-amber { border-left: 3px solid #f59e0b; }
        .card-red { border-left: 3px solid #ef4444; }
        .card-cyan { border-left: 3px solid #06b6d4; }
        .card-purple { border-left: 3px solid #a855f7; }

        .table-wrap { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; margin-bottom: 24px; }
        .table-header { padding: 14px 20px; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
        .table-header h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; }
        .table-header span { font-size: 12px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 8px 20px; text-align: left; font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; background: #1e293b; border-bottom: 1px solid #334155; }
        td { padding: 10px 20px; font-size: 13px; border-bottom: 1px solid rgba(51,65,85,0.4); color: #cbd5e1; }
        tbody tr { background: #0f172a; }
        tbody tr:hover { background: #1e293b; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .badge-green { background: rgba(34,197,94,0.15); color: #4ade80; }
        .badge-amber { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-red { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-blue { background: rgba(99,102,241,0.15); color: #818cf8; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
        .dot-amber { background: #f59e0b; }
        .dot-red { background: #ef4444; }

        .tool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .tool-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 18px; }
        .tool-card h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px; }
        .tool-card p { font-size: 12px; color: #64748b; margin-bottom: 12px; }
        .btn { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 600; color: #fff; }
        .btn:hover { opacity: 0.85; }
        .btn-indigo { background: #6366f1; }
        .btn-green { background: #22c55e; }
        .btn-red { background: #ef4444; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }

        .health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .health-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 18px; }
        .health-card h4 { font-size: 12px; color: #94a3b8; font-weight: 500; margin-bottom: 6px; }
        .health-card .val { font-size: 22px; font-weight: 700; color: #f1f5f9; margin-bottom: 6px; }
        .health-card .sub { font-size: 11px; color: #64748b; }
        .chart-row { display: flex; align-items: flex-end; gap: 2px; height: 50px; margin-top: 8px; }
        .chart-bar { flex: 1; border-radius: 2px 2px 0 0; min-width: 3px; transition: height 0.3s; }

        .empty-state { text-align: center; padding: 40px; color: #475569; font-size: 13px; }
        .rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .rank-1 { background: rgba(245,158,11,0.2); color: #fbbf24; }
        .rank-2 { background: rgba(148,163,184,0.2); color: #94a3b8; }
        .rank-3 { background: rgba(180,83,9,0.2); color: #d97706; }
        .rank-n { background: rgba(51,65,85,0.3); color: #64748b; }
        .truncate { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <div class="logo">P</div>
                <div><h1>Proxy Server</h1><p>Cache Dashboard</p></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="lbl">Monitor</div>
            <a href="?action=dashboard&page=overview" class="<?= $page === 'overview' ? 'active' : '' ?>">
                <span class="icon">&#9632;</span> Overview <span class="count"><?= number_format($totalUsers) ?></span>
            </a>
            <a href="?action=dashboard&page=tenants" class="<?= $page === 'tenants' ? 'active' : '' ?>">
                <span class="icon">&#9830;</span> Tenants <span class="count"><?= $totalTenants ?></span>
            </a>
            <a href="?action=dashboard&page=routers" class="<?= $page === 'routers' ? 'active' : '' ?>">
                <span class="icon">&#8860;</span> Routers <span class="count"><?= $totalRouters ?></span>
            </a>
            <a href="?action=dashboard&page=users" class="<?= $page === 'users' ? 'active' : '' ?>">
                <span class="icon">&#9679;</span> Recent Users
            </a>
            <a href="?action=dashboard&page=top" class="<?= $page === 'top' ? 'active' : '' ?>">
                <span class="icon">&#9733;</span> Top Pushers
            </a>
            <div class="lbl">MikroTik</div>
            <a href="?action=dashboard&page=mikrotik" class="<?= $page === 'mikrotik' ? 'active' : '' ?>">
                <span class="icon">&#9016;</span> Active Users <span class="count"><?= number_format($totalMikrotikUsers) ?></span>
            </a>
            <div class="lbl">System</div>
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
            <span class="ver">v2.0</span>
        </div>
    </div>

    <div class="main">
    <?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if ($page === 'overview'): ?>
        <div class="page-header">
            <div><h2>Overview</h2><p>Server time: <?= date('Y-m-d H:i:s') ?></p></div>
            <div class="live"><span class="pulse"></span> Live &middot; refreshes 15s</div>
        </div>
        <div class="cards">
            <div class="card card-accent"><div class="card-label">Cached Users</div><div class="card-value"><?= number_format($totalUsers) ?></div><div class="card-sub">Active in proxy</div></div>
            <div class="card card-green"><div class="card-label">Tenants</div><div class="card-value"><?= $totalTenants ?></div><div class="card-sub">Pushing data</div></div>
            <div class="card card-amber"><div class="card-label">Routers</div><div class="card-value"><?= $totalRouters ?></div><div class="card-sub">All tenants</div></div>
            <div class="card card-red"><div class="card-label">Last Push</div><div class="card-value" style="font-size:18px;"><?= $recentAgo !== null ? $recentAgo . 's ago' : '--' ?></div><div class="card-sub"><?= $recentPush ?: 'No data' ?></div></div>
            <div class="card card-purple"><div class="card-label">Push Rate</div><div class="card-value"><?= $pushesPerMin ?></div><div class="card-sub">per minute (5m avg)</div></div>
            <?php if ($totalMikrotikRouters > 0): ?>
            <div class="card card-cyan"><div class="card-label">MikroTik</div><div class="card-value"><?= number_format($totalMikrotikUsers) ?></div><div class="card-sub"><?= $totalMikrotikRouters ?> router(s) reporting</div></div>
            <?php endif; ?>
            <?php if ($latestHealth): ?>
            <div class="card card-cyan"><div class="card-label">RAM</div><div class="card-value" style="font-size:18px;"><?= $latestHealth['ram_percent'] ?>%</div><div class="card-sub"><?= $latestHealth['ram_used_mb'] ?> / <?= $latestHealth['ram_total_mb'] ?> MB</div><?= progressBar($latestHealth['ram_percent']) ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <div class="table-header">
                <h3>Tenants</h3>
                <form method="GET" class="search-bar"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="page" value="overview"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search tenants..."><button type="submit">Search</button></form>
            </div>
            <table>
                <thead><tr><th><?= sortLink('tenant','Tenant') ?></th><th><?= sortLink('users','Users') ?></th><th><?= sortLink('routers','Routers') ?></th><th><?= sortLink('last_push','Last Push') ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t): $ago=time()-strtotime($t['last_push']); [$cls,$label]=statusBadge($ago); ?>
                <tr><td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td><td><?= $t['user_count'] ?></td><td><?= $t['router_count'] ?></td><td><?= timeAgo($t['last_push']) ?></td><td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($tenantStats)): ?><tr><td colspan="5"><div class="empty-state">No tenants<?= $search ? " matching \"$search\"" : '' ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'tenants'): ?>
        <div class="page-header">
            <div><h2>Tenants</h2><p><?= count($tenantStats) ?> tenant(s) active</p></div>
            <form method="GET" class="search-bar"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="page" value="tenants"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search..."><button type="submit">Search</button></form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= sortLink('tenant','Tenant') ?></th><th><?= sortLink('users','Users') ?></th><th><?= sortLink('routers','Routers') ?></th><th><?= sortLink('last_push','Last Push') ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t): $ago=time()-strtotime($t['last_push']); [$cls,$label]=statusBadge($ago); ?>
                <tr><td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td><td><?= $t['user_count'] ?></td><td><?= $t['router_count'] ?></td><td><?= $t['last_push'] ?> <span style="color:#475569;">(<?= timeAgo($t['last_push']) ?>)</span></td><td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($tenantStats)): ?><tr><td colspan="5"><div class="empty-state">No results</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'routers'): ?>
        <div class="page-header">
            <div><h2>Routers</h2><p><?= count($routerStats) ?> router(s)</p></div>
            <form method="GET" class="search-bar"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="page" value="routers"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search tenant or router ID..."><button type="submit">Search</button></form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= sortLink('tenant','Tenant') ?></th><th>Router ID</th><th><?= sortLink('users','Users') ?></th><th><?= sortLink('last_push','Last Push') ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($routerStats as $r): $ago=time()-strtotime($r['last_push']); [$cls,$label]=statusBadge($ago); ?>
                <tr><td><span class="badge badge-blue"><?= htmlspecialchars($r['tenant']) ?></span></td><td><strong>#<?= $r['router_id'] ?></strong></td><td><?= $r['user_count'] ?></td><td><?= timeAgo($r['last_push']) ?></td><td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (empty($routerStats)): ?><tr><td colspan="5"><div class="empty-state">No results</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'users'): ?>
        <div class="page-header">
            <div><h2>Recent Users</h2><p>Last 100 cached entries</p></div>
            <form method="GET" class="search-bar"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="page" value="users"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search username, tenant, profile..."><button type="submit">Search</button></form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tenant</th><th>Router</th><th>Username</th><th>Profile</th><th>Type</th><th>Pushed</th></tr></thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                <tr><td><span class="badge badge-blue"><?= htmlspecialchars($u['tenant']) ?></span></td><td>#<?= $u['router_id'] ?></td><td><strong class="truncate"><?= htmlspecialchars($u['username']) ?></strong></td><td><?= htmlspecialchars($u['profile_name']) ?></td><td><span class="badge badge-green"><?= $u['type'] ?></span></td><td><?= timeAgo($u['pushed_at']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($recentUsers)): ?><tr><td colspan="6"><div class="empty-state">No results</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'top'): ?>
        <div class="page-header"><div><h2>Top Pushers</h2><p>Tenants ranked by cached users</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Tenant</th><th>Users</th><th>Routers</th><th>Last Push</th><th>Share</th></tr></thead>
                <tbody>
                <?php $rank=0; foreach ($topPushers as $t): $rank++; $share=$totalUsers>0?round(($t['total_users']/$totalUsers)*100,1):0; $rcls=$rank<=3?"rank-$rank":'rank-n'; ?>
                <tr><td><span class="rank <?= $rcls ?>"><?= $rank ?></span></td><td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td><td><?= number_format($t['total_users']) ?></td><td><?= $t['routers'] ?></td><td><?= timeAgo($t['last_push']) ?></td><td><?= $share ?>%<?= progressBar($share,'#6366f1') ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($topPushers)): ?><tr><td colspan="6"><div class="empty-state">No data</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'health'): ?>
        <div class="page-header"><div><h2>Server Health</h2><p>System resources (last hour)</p></div></div>
        <?php if (!$hasMetrics || empty($healthData)): ?>
        <div class="alert alert-error">No metrics yet. Add cron: <code style="background:#334155;padding:2px 6px;border-radius:4px;">* * * * * php /var/www/html/proxyserver/metrics.php</code></div>
        <?php else: ?>
        <div class="health-grid">
            <?php
            $metrics = [
                ['CPU','cpu_percent','%','#6366f1'],
                ['RAM','ram_percent','% ('.$latestHealth['ram_used_mb'].'/'.$latestHealth['ram_total_mb'].' MB)','#22c55e'],
                ['Disk','disk_percent','% ('.$latestHealth['disk_used_gb'].'/'.$latestHealth['disk_total_gb'].' GB)','#f59e0b'],
                ['Workers','apache_workers',' processes','#06b6d4'],
                ['MySQL Conn','mysql_connections',' threads','#8b5cf6'],
                ['Cached Users','cached_users_count','','#f59e0b'],
            ];
            foreach ($metrics as [$title,$key,$suffix,$barColor]):
                $vals = array_column($healthData, $key);
                $maxV = max($vals) ?: 1;
            ?>
            <div class="health-card">
                <h4><?= $title ?></h4>
                <div class="val"><?= $latestHealth[$key] ?><span style="font-size:13px;color:#64748b;"><?= $suffix ?></span></div>
                <?php if (in_array($key,['cpu_percent','ram_percent','disk_percent'])) echo progressBar($latestHealth[$key]); ?>
                <div class="chart-row">
                    <?php foreach ($healthData as $h): $pct=($h[$key]/$maxV)*100; ?>
                    <div class="chart-bar" style="height:<?= max(2,$pct) ?>%;background:<?= $barColor ?>;opacity:0.7;" title="<?= $h['recorded_at'] ?>: <?= $h[$key] ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="sub">Load: <?= $latestHealth['load_avg_1'] ?> / <?= $latestHealth['load_avg_5'] ?> / <?= $latestHealth['load_avg_15'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif ($page === 'mysql'): ?>
        <div class="page-header"><div><h2>MySQL Status</h2><p>Database performance</p></div></div>
        <div class="cards">
            <div class="card card-accent"><div class="card-label">Connections</div><div class="card-value"><?= $mysqlStatus['Threads_connected'] ?? '--' ?></div><div class="card-sub">Max used: <?= $mysqlStatus['Max_used_connections'] ?? '--' ?></div></div>
            <div class="card card-green"><div class="card-label">Max Allowed</div><div class="card-value"><?= $mysqlStatus['max_connections'] ?? '--' ?></div></div>
            <div class="card card-amber"><div class="card-label">Queries</div><div class="card-value" style="font-size:18px;"><?= isset($mysqlStatus['Queries']) ? number_format($mysqlStatus['Queries']) : '--' ?></div></div>
            <div class="card card-red"><div class="card-label">Uptime</div><div class="card-value" style="font-size:18px;"><?= isset($mysqlStatus['Uptime']) ? formatUptime($mysqlStatus['Uptime']) : '--' ?></div></div>
        </div>
        <?php if (isset($mysqlStatus['Threads_connected'],$mysqlStatus['max_connections'])): $cp=round(($mysqlStatus['Threads_connected']/$mysqlStatus['max_connections'])*100,1); $mp=isset($mysqlStatus['Max_used_connections'])?round(($mysqlStatus['Max_used_connections']/$mysqlStatus['max_connections'])*100,1):0; ?>
        <div class="health-grid">
            <div class="health-card"><h4>Connection Usage</h4><div class="val"><?= $cp ?>%</div><?= progressBar($cp) ?><div class="sub"><?= $mysqlStatus['Threads_connected'] ?> of <?= $mysqlStatus['max_connections'] ?></div></div>
            <div class="health-card"><h4>Peak Usage</h4><div class="val"><?= $mp ?>%</div><?= progressBar($mp) ?><div class="sub">Max: <?= $mysqlStatus['Max_used_connections'] ?> of <?= $mysqlStatus['max_connections'] ?></div></div>
        </div>
        <?php endif; ?>
        <div class="table-wrap"><div class="table-header"><h3>Variables</h3></div>
            <table><thead><tr><th>Variable</th><th>Value</th></tr></thead>
            <tbody style="background:#1e293b;">
            <?php foreach ($mysqlStatus as $k=>$v): ?>
            <tr><td style="color:#94a3b8;font-family:monospace;font-size:12px;"><?= $k ?></td><td><?php if(in_array($k,['innodb_buffer_pool_size','max_allowed_packet'])) echo formatBytes((int)$v); elseif($k==='Uptime') echo formatUptime((int)$v); else echo number_format((float)$v); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>

    <?php elseif ($page === 'mikrotik'): ?>
        <div class="page-header">
            <div><h2>MikroTik Active Users</h2><p><?= number_format($totalMikrotikUsers) ?> active across <?= $totalMikrotikRouters ?> router(s)</p></div>
            <form method="GET" class="search-bar"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="page" value="mikrotik"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search tenant or username..."><button type="submit">Search</button></form>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>Router Heartbeats</h3><span><?= $totalMikrotikRouters ?> router(s)</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Router ID</th><th>Hotspot Users</th><th>PPPoE Users</th><th>Last Heartbeat</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($mikrotikRouters as $r):
                    $ago = time() - strtotime($r['last_heartbeat']);
                    if ($ago < 120) { $cls = 'green'; $label = 'Online'; }
                    elseif ($ago < 600) { $cls = 'amber'; $label = 'Idle'; }
                    else { $cls = 'red'; $label = 'Offline'; }
                ?>
                <tr>
                    <td><span class="badge badge-blue"><?= htmlspecialchars($r['tenant']) ?></span></td>
                    <td><strong>#<?= $r['router_id'] ?></strong></td>
                    <td><?= $r['hotspot_count'] ?></td>
                    <td><?= $r['pppoe_count'] ?></td>
                    <td><?= timeAgo($r['last_heartbeat']) ?></td>
                    <td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($mikrotikRouters)): ?><tr><td colspan="6"><div class="empty-state">No routers reporting yet. Deploy the MikroTik script to start receiving heartbeats.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>Active Users</h3><span><?= count($mikrotikUsers) ?> shown (max 200)</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Router</th><th>Username</th><th>Type</th><th>Last Seen</th></tr></thead>
                <tbody>
                <?php foreach ($mikrotikUsers as $u): ?>
                <tr>
                    <td><span class="badge badge-blue"><?= htmlspecialchars($u['tenant']) ?></span></td>
                    <td>#<?= $u['router_id'] ?></td>
                    <td><strong class="truncate"><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><span class="badge badge-<?= $u['type'] === 'hotspot' ? 'green' : 'amber' ?>"><?= ucfirst($u['type']) ?></span></td>
                    <td><?= timeAgo($u['last_seen']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($mikrotikUsers)): ?><tr><td colspan="5"><div class="empty-state">No active users<?= $search ? " matching \"$search\"" : '' ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'tools'): ?>
        <div class="page-header"><div><h2>Tools</h2><p>Maintenance</p></div></div>
        <div class="tool-grid">
            <div class="tool-card"><h3>Initialize Database</h3><p>Create/verify all tables.</p><form method="POST"><button type="submit" name="init_db" value="1" class="btn btn-indigo">Verify Tables</button></form></div>
            <div class="tool-card"><h3>Run Cleanup</h3><p>Delete records older than <?= CLEANUP_MAX_AGE/60 ?>min.</p><form method="POST"><button type="submit" name="run_cleanup" value="1" class="btn btn-green">Run Now</button></form></div>
            <div class="tool-card"><h3>Purge All</h3><p>Delete all cached records.</p><form method="POST" onsubmit="return confirm('Delete all?')"><button type="submit" name="purge_all" value="1" class="btn btn-red">Purge</button></form></div>
        </div>
        <div class="table-wrap"><div class="table-header"><h3>System Info</h3></div>
            <table><tbody style="background:#1e293b;">
            <tr><td style="color:#64748b;width:180px;">PHP</td><td><?= phpversion() ?></td></tr>
            <tr><td style="color:#64748b;">Server</td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></td></tr>
            <tr><td style="color:#64748b;">Time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
            <tr><td style="color:#64748b;">Cleanup</td><td><?= CLEANUP_MAX_AGE/60 ?> minutes</td></tr>
            <tr><td style="color:#64748b;">Records</td><td><?= number_format($totalUsers) ?></td></tr>
            <tr><td style="color:#64748b;">DB Host</td><td><?= htmlspecialchars($db_host) ?></td></tr>
            <tr><td style="color:#64748b;">Database</td><td><?= htmlspecialchars($db_name) ?></td></tr>
            <tr><td style="color:#64748b;">Memory Limit</td><td><?= ini_get('memory_limit') ?></td></tr>
            <tr><td style="color:#64748b;">Max Execution</td><td><?= ini_get('max_execution_time') ?>s</td></tr>
            </tbody></table>
        </div>
    <?php endif; ?>
    </div>
</body>
</html>
