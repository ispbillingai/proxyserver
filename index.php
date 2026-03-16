<?php
// Proxy Cache Server - Router

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Also support clean URLs via PATH_INFO
if ($action === '' && isset($_SERVER['PATH_INFO'])) {
    $action = trim($_SERVER['PATH_INFO'], '/');
}

switch ($action) {
    case 'receive':
        require __DIR__ . '/receive.php';
        break;
    case 'fetch':
        require __DIR__ . '/fetch.php';
        break;
    case 'dashboard':
        require __DIR__ . '/dashboard.php';
        break;
    default:
        header('Location: ?action=dashboard');
        break;
}
