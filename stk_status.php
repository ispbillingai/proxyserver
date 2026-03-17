<?php
/**
 * STK Status Check Endpoint
 * Checks cached_users table for recent data.
 * If records exist → STK push is flowing (available).
 * If table is empty (all cleaned up) → service unavailable (Safaricom issue).
 *
 * GET ?action=stk_status
 * Response: {"available":true|false,"last_push":"2026-03-18 12:00:00","count":5}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $db = getDB();

    // Quick count + latest pushed_at in one query
    $row = $db->query(
        "SELECT COUNT(*) AS cnt, MAX(pushed_at) AS last_push FROM cached_users"
    )->fetch();

    $count    = (int)$row['cnt'];
    $lastPush = $row['last_push'];

    echo json_encode([
        'available'  => $count > 0,
        'last_push'  => $lastPush,
        'count'      => $count,
        'checked_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    error_log('stk_status error: ' . $e->getMessage());
    echo json_encode([
        'available'  => false,
        'last_push'  => null,
        'count'      => 0,
        'checked_at' => date('Y-m-d H:i:s'),
        'error'      => 'db_error',
    ]);
}
