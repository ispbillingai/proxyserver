<?php
// Simple monitoring dashboard

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();

// Handle actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        session_destroy();
        header('Location: ?action=dashboard');
        exit;
    }
}

// Simple auth
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
        <html><head><title>Proxy Dashboard - Login</title>
        <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#1a1a2e;}
        .box{background:#16213e;padding:40px;border-radius:8px;color:#e0e0e0;width:300px;}
        input{width:100%;padding:10px;margin:10px 0;border:1px solid #333;border-radius:4px;background:#0f3460;color:#e0e0e0;box-sizing:border-box;}
        button{width:100%;padding:10px;background:#e94560;border:none;color:#fff;border-radius:4px;cursor:pointer;font-weight:bold;}
        .err{color:#e94560;font-size:13px;}</style></head>
        <body><div class="box"><h3>Proxy Dashboard</h3>
        <?php if (isset($error)) echo "<p class='err'>$error</p>"; ?>
        <form method="POST"><input type="password" name="password" placeholder="Password" autofocus>
        <button type="submit">Login</button></form></div></body></html>
        <?php
        exit;
    }
}

// Handle POST actions (after auth)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Initialize database table
    if (isset($_POST['init_db'])) {
        try {
            $db = getDB();
            $message = 'Database table created/verified successfully.';
        } catch (Throwable $e) {
            $message = 'Database error: ' . $e->getMessage();
        }
    }

}

$db = getDB();

// Stats
$totalUsers = $db->query("SELECT COUNT(*) c FROM cached_users")->fetch()['c'];

$tenantStats = $db->query("SELECT tenant, COUNT(*) as user_count,
    COUNT(DISTINCT router_name) as router_count,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant ORDER BY last_push DESC")->fetchAll();

$routerStats = $db->query("SELECT tenant, router_name, COUNT(*) as user_count,
    MAX(pushed_at) as last_push
    FROM cached_users GROUP BY tenant, router_name ORDER BY last_push DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Proxy Cache Dashboard</title>
    <meta http-equiv="refresh" content="30">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 20px; }
        h1 { color: #e94560; margin-bottom: 5px; font-size: 22px; }
        .subtitle { color: #777; font-size: 13px; margin-bottom: 20px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .topbar a { color: #e94560; font-size: 13px; text-decoration: none; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: #16213e; padding: 18px 24px; border-radius: 8px; min-width: 160px; }
        .stat-card .num { font-size: 28px; font-weight: bold; color: #e94560; }
        .stat-card .label { font-size: 12px; color: #888; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; background: #16213e; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        th { background: #0f3460; padding: 10px 14px; text-align: left; font-size: 12px; color: #aaa; text-transform: uppercase; }
        td { padding: 10px 14px; border-top: 1px solid #1a1a2e; font-size: 13px; }
        tr:hover td { background: #1a2744; }
        .section { margin-bottom: 25px; }
        .section h2 { font-size: 15px; margin-bottom: 10px; color: #ccc; }
        .fresh { color: #4ade80; }
        .stale { color: #fbbf24; }
        .old { color: #ef4444; }
        .tools { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .tool-box { background: #16213e; padding: 18px; border-radius: 8px; flex: 1; min-width: 250px; }
        .tool-box h3 { font-size: 14px; margin-bottom: 10px; color: #ccc; }
        .tool-box input { padding: 8px; border: 1px solid #333; border-radius: 4px; background: #0f3460; color: #e0e0e0; width: 100%; margin-bottom: 8px; box-sizing: border-box; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 13px; color: #fff; }
        .btn-green { background: #22c55e; }
        .btn-blue { background: #3b82f6; }
        .msg { background: #0f3460; padding: 10px 14px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; border-left: 3px solid #e94560; }
    </style>
</head>
<body>
    <div class="topbar">
        <div>
            <h1>Proxy Cache Server</h1>
            <p class="subtitle">Auto-refreshes every 30 seconds. Server time: <?= date('Y-m-d H:i:s') ?></p>
        </div>
        <a href="?action=logout">Logout</a>
    </div>

    <?php if ($message): ?>
    <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <div class="num"><?= $totalUsers ?></div>
            <div class="label">Total Cached Users</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= count($tenantStats) ?></div>
            <div class="label">Active Tenants</div>
        </div>
    </div>

    <div class="tools">
        <div class="tool-box">
            <h3>Initialize Database</h3>
            <p style="font-size:12px;color:#888;margin-bottom:10px;">Creates the cached_users table if it doesn't exist.</p>
            <form method="POST">
                <button type="submit" name="init_db" value="1" class="btn btn-green">Create / Verify Table</button>
            </form>
        </div>
    </div>

    <div class="section">
        <h2>Tenants</h2>
        <table>
            <tr><th>Tenant</th><th>Users</th><th>Routers</th><th>Last Push</th></tr>
            <?php foreach ($tenantStats as $t):
                $ago = time() - strtotime($t['last_push']);
                $cls = $ago < 60 ? 'fresh' : ($ago < 300 ? 'stale' : 'old');
            ?>
            <tr>
                <td><?= htmlspecialchars($t['tenant']) ?></td>
                <td><?= $t['user_count'] ?></td>
                <td><?= $t['router_count'] ?></td>
                <td class="<?= $cls ?>"><?= $t['last_push'] ?> (<?= $ago ?>s ago)</td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tenantStats)): ?>
            <tr><td colspan="4" style="text-align:center;color:#666;">No data yet</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section">
        <h2>Routers (last 50)</h2>
        <table>
            <tr><th>Tenant</th><th>Router</th><th>Users</th><th>Last Push</th></tr>
            <?php foreach ($routerStats as $r):
                $ago = time() - strtotime($r['last_push']);
                $cls = $ago < 60 ? 'fresh' : ($ago < 300 ? 'stale' : 'old');
            ?>
            <tr>
                <td><?= htmlspecialchars($r['tenant']) ?></td>
                <td><?= htmlspecialchars($r['router_name']) ?></td>
                <td><?= $r['user_count'] ?></td>
                <td class="<?= $cls ?>"><?= $r['last_push'] ?> (<?= $ago ?>s ago)</td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($routerStats)): ?>
            <tr><td colspan="4" style="text-align:center;color:#666;">No data yet</td></tr>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>
