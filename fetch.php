<?php
// GET endpoint: MikroTik routers pull active users from here

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$tenant   = isset($_GET['tenant'])    ? trim($_GET['tenant'])    : '';
$routerId = isset($_GET['router_id']) ? (int)$_GET['router_id'] : 0;
$type     = isset($_GET['type'])      ? trim($_GET['type'])      : '';
$limit    = isset($_GET['limit'])     ? (int)$_GET['limit']      : 20;

if ($tenant === '' || $routerId < 1) {
    echo "ERROR: tenant and router_id required";
    exit;
}

if ($limit < 1 || $limit > 100) {
    $limit = 20;
}

$db = getDB();

$sql = "SELECT username, profile_name, type FROM cached_users
        WHERE tenant = :tenant AND router_id = :router_id";
$params = [':tenant' => $tenant, ':router_id' => $routerId];

if ($type !== '') {
    $sql .= " AND type = :type";
    $params[':type'] = $type;
}

$sql .= " ORDER BY id DESC LIMIT :limit";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$users = $stmt->fetchAll();

if (empty($users)) {
    echo "NO_USERS";
    exit;
}

foreach ($users as $u) {
    echo $u['username'] . ',' . $u['profile_name'] . ',' . $u['type'] . "\n";
}
