<?php
// Simple monitoring dashboard

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();

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
    </style>
</head>
<body>
    <h1>Proxy Cache Server</h1>
    <p class="subtitle">Auto-refreshes every 30 seconds. Server time: <?= date('Y-m-d H:i:s') ?></p>

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
