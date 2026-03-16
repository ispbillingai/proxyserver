<?php
// Proxy Cache Server Dashboard

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();

// Handle logout
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

// Handle POST actions
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['init_db'])) {
        try {
            $db = getDB();
            $message = 'Database table created and verified successfully.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    if (isset($_POST['purge_all'])) {
        try {
            $db = getDB();
            $db->exec("DELETE FROM cached_users");
            $message = 'All cached records purged.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Purge error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    if (isset($_POST['run_cleanup'])) {
        try {
            $db = getDB();
            $maxAge = CLEANUP_MAX_AGE;
            $stmt = $db->prepare("DELETE FROM cached_users WHERE pushed_at < DATE_SUB(NOW(), INTERVAL :age SECOND)");
            $stmt->execute([':age' => $maxAge]);
            $deleted = $stmt->rowCount();
            $message = "Cleanup complete. Removed $deleted expired records.";
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Cleanup error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$db = getDB();

// Current page
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';

// Stats
$totalUsers = $db->query("SELECT COUNT(*) c FROM cached_users")->fetch()['c'];
$totalTenants = $db->query("SELECT COUNT(DISTINCT tenant) c FROM cached_users")->fetch()['c'];
$totalRouters = $db->query("SELECT COUNT(DISTINCT CONCAT(tenant,'|',router_name)) c FROM cached_users")->fetch()['c'];

$recentPush = $db->query("SELECT MAX(pushed_at) m FROM cached_users")->fetch()['m'];
$recentAgo = $recentPush ? (time() - strtotime($recentPush)) : null;

$tenantStats = $db->query("SELECT tenant, COUNT(*) as user_count,
    COUNT(DISTINCT router_name) as router_count,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant ORDER BY last_push DESC")->fetchAll();

$routerStats = $db->query("SELECT tenant, router_name, COUNT(*) as user_count,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant, router_name ORDER BY last_push DESC LIMIT 100")->fetchAll();

$recentUsers = $db->query("SELECT tenant, router_name, username, profile_name, type, pushed_at
    FROM cached_users ORDER BY pushed_at DESC LIMIT 30")->fetchAll();
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

        /* Sidebar */
        .sidebar { width: 240px; background: #1e293b; border-right: 1px solid #334155; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #334155; }
        .sidebar-header .brand { display: flex; align-items: center; gap: 10px; }
        .sidebar-header .logo { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; font-size: 16px; }
        .sidebar-header h1 { font-size: 15px; color: #f1f5f9; font-weight: 600; }
        .sidebar-header p { font-size: 11px; color: #64748b; margin-top: 2px; }
        .sidebar-nav { padding: 12px; flex: 1; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: #94a3b8; font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all 0.15s; }
        .sidebar-nav a:hover { background: #334155; color: #e2e8f0; }
        .sidebar-nav a.active { background: #6366f1; color: #fff; }
        .sidebar-nav a .icon { width: 18px; text-align: center; font-style: normal; }
        .sidebar-nav .section-label { font-size: 10px; color: #475569; text-transform: uppercase; letter-spacing: 1px; padding: 16px 12px 6px; font-weight: 600; }
        .sidebar-footer { padding: 16px; border-top: 1px solid #334155; }
        .sidebar-footer a { color: #ef4444; font-size: 13px; text-decoration: none; font-weight: 500; }

        /* Main Content */
        .main { margin-left: 240px; flex: 1; padding: 28px; min-height: 100vh; }
        .page-header { margin-bottom: 24px; }
        .page-header h2 { font-size: 20px; font-weight: 600; color: #f1f5f9; }
        .page-header p { font-size: 13px; color: #64748b; margin-top: 4px; }

        /* Cards Grid */
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .card .card-label { font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .card .card-value { font-size: 32px; font-weight: 700; color: #f1f5f9; margin-top: 6px; }
        .card .card-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
        .card-accent { border-left: 3px solid #6366f1; }
        .card-green { border-left: 3px solid #22c55e; }
        .card-amber { border-left: 3px solid #f59e0b; }
        .card-red { border-left: 3px solid #ef4444; }

        /* Tables */
        .table-wrap { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; margin-bottom: 24px; }
        .table-header { padding: 16px 20px; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
        .table-header h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; }
        .table-header span { font-size: 12px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px 20px; text-align: left; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; background: #1e293b; border-bottom: 1px solid #334155; }
        td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid #1e293b; color: #cbd5e1; }
        tbody tr { background: #0f172a; }
        tbody tr:hover { background: #1e293b; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .badge-green { background: rgba(34,197,94,0.15); color: #4ade80; }
        .badge-amber { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-red { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-blue { background: rgba(99,102,241,0.15); color: #818cf8; }

        /* Tools */
        .tool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .tool-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .tool-card h3 { font-size: 14px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px; }
        .tool-card p { font-size: 12px; color: #64748b; margin-bottom: 14px; }
        .btn { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #fff; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.85; }
        .btn-indigo { background: #6366f1; }
        .btn-green { background: #22c55e; }
        .btn-red { background: #ef4444; }

        /* Message */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }

        /* Status dot */
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
        .dot-amber { background: #f59e0b; }
        .dot-red { background: #ef4444; }

        .empty-state { text-align: center; padding: 40px; color: #475569; }
        .empty-state .icon { font-size: 36px; margin-bottom: 10px; }
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
                <span class="icon">&#9881;</span> Routers
            </a>
            <a href="?action=dashboard&page=users" class="<?= $page === 'users' ? 'active' : '' ?>">
                <span class="icon">&#9679;</span> Recent Users
            </a>
            <div class="section-label">System</div>
            <a href="?action=dashboard&page=tools" class="<?= $page === 'tools' ? 'active' : '' ?>">
                <span class="icon">&#9881;</span> Tools
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="?action=logout">Sign Out</a>
        </div>
    </div>

    <div class="main">

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($page === 'overview'): ?>
        <div class="page-header">
            <h2>Overview</h2>
            <p>Server time: <?= date('Y-m-d H:i:s') ?> &middot; Auto-refreshes every 30s</p>
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
                <div class="card-value" style="font-size:20px;"><?= $recentAgo !== null ? $recentAgo . 's' : '--' ?></div>
                <div class="card-sub"><?= $recentPush ?: 'No data yet' ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <div class="table-header">
                <h3>Tenants Summary</h3>
                <span><?= count($tenantStats) ?> tenant(s)</span>
            </div>
            <table>
                <thead><tr><th>Tenant</th><th>Users</th><th>Routers</th><th>Last Push</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t):
                    $ago = time() - strtotime($t['last_push']);
                    if ($ago < 60) { $cls = 'green'; $label = 'Active'; }
                    elseif ($ago < 300) { $cls = 'amber'; $label = 'Idle'; }
                    else { $cls = 'red'; $label = 'Stale'; }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td>
                    <td><?= $t['user_count'] ?></td>
                    <td><?= $t['router_count'] ?></td>
                    <td><?= $t['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s ago)</span></td>
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
        <div class="page-header">
            <h2>Tenants</h2>
            <p>All tenants currently caching data</p>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>All Tenants</h3><span><?= count($tenantStats) ?> total</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Users</th><th>Routers</th><th>Last Push</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tenantStats as $t):
                    $ago = time() - strtotime($t['last_push']);
                    if ($ago < 60) { $cls = 'green'; $label = 'Active'; }
                    elseif ($ago < 300) { $cls = 'amber'; $label = 'Idle'; }
                    else { $cls = 'red'; $label = 'Stale'; }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($t['tenant']) ?></strong></td>
                    <td><?= $t['user_count'] ?></td>
                    <td><?= $t['router_count'] ?></td>
                    <td><?= $t['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s ago)</span></td>
                    <td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tenantStats)): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="icon">&#128230;</div>No tenants yet</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'routers'): ?>
        <div class="page-header">
            <h2>Routers</h2>
            <p>All routers across tenants (last 100)</p>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>All Routers</h3><span><?= count($routerStats) ?> shown</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Router</th><th>Users</th><th>Last Push</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($routerStats as $r):
                    $ago = time() - strtotime($r['last_push']);
                    if ($ago < 60) { $cls = 'green'; $label = 'Active'; }
                    elseif ($ago < 300) { $cls = 'amber'; $label = 'Idle'; }
                    else { $cls = 'red'; $label = 'Stale'; }
                ?>
                <tr>
                    <td><span class="badge badge-blue"><?= htmlspecialchars($r['tenant']) ?></span></td>
                    <td><strong><?= htmlspecialchars($r['router_name']) ?></strong></td>
                    <td><?= $r['user_count'] ?></td>
                    <td><?= $r['last_push'] ?> <span style="color:#64748b;">(<?= $ago ?>s ago)</span></td>
                    <td><span class="dot dot-<?= $cls ?>"></span><span class="badge badge-<?= $cls ?>"><?= $label ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($routerStats)): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="icon">&#128225;</div>No routers yet</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'users'): ?>
        <div class="page-header">
            <h2>Recent Users</h2>
            <p>Last 30 users pushed to the cache</p>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>Recent Activity</h3><span>Last 30 entries</span></div>
            <table>
                <thead><tr><th>Tenant</th><th>Router</th><th>Username</th><th>Profile</th><th>Type</th><th>Pushed At</th></tr></thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td><span class="badge badge-blue"><?= htmlspecialchars($u['tenant']) ?></span></td>
                    <td><?= htmlspecialchars($u['router_name']) ?></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><?= htmlspecialchars($u['profile_name']) ?></td>
                    <td><span class="badge badge-green"><?= htmlspecialchars($u['type']) ?></span></td>
                    <td><?= $u['pushed_at'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentUsers)): ?>
                <tr><td colspan="6"><div class="empty-state"><div class="icon">&#128100;</div>No users cached yet</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'tools'): ?>
        <div class="page-header">
            <h2>Tools</h2>
            <p>System maintenance and utilities</p>
        </div>

        <div class="tool-grid">
            <div class="tool-card">
                <h3>Initialize Database</h3>
                <p>Creates the cached_users table if it doesn't exist. Safe to run anytime.</p>
                <form method="POST">
                    <button type="submit" name="init_db" value="1" class="btn btn-indigo">Create / Verify Table</button>
                </form>
            </div>
            <div class="tool-card">
                <h3>Run Cleanup Now</h3>
                <p>Manually delete expired records (older than <?= CLEANUP_MAX_AGE / 60 ?> minutes).</p>
                <form method="POST">
                    <button type="submit" name="run_cleanup" value="1" class="btn btn-green">Run Cleanup</button>
                </form>
            </div>
            <div class="tool-card">
                <h3>Purge All Data</h3>
                <p>Delete ALL cached records. Routers will re-populate on next cycle.</p>
                <form method="POST" onsubmit="return confirm('Are you sure? This will delete all cached data.')">
                    <button type="submit" name="purge_all" value="1" class="btn btn-red">Purge All Records</button>
                </form>
            </div>
        </div>

        <div class="table-wrap">
            <div class="table-header"><h3>System Info</h3></div>
            <table>
                <tbody style="background:#1e293b;">
                <tr><td style="color:#64748b;width:200px;">PHP Version</td><td><?= phpversion() ?></td></tr>
                <tr><td style="color:#64748b;">Server Time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
                <tr><td style="color:#64748b;">Cleanup Interval</td><td><?= CLEANUP_MAX_AGE / 60 ?> minutes</td></tr>
                <tr><td style="color:#64748b;">Total Records</td><td><?= number_format($totalUsers) ?></td></tr>
                <tr><td style="color:#64748b;">MySQL Host</td><td><?= htmlspecialchars($db_host) ?></td></tr>
                <tr><td style="color:#64748b;">Database</td><td><?= htmlspecialchars($db_name) ?></td></tr>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    </div>
</body>
</html>
