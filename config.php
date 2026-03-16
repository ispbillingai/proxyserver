<?php
// Proxy Cache Server Configuration

$db_host     = 'localhost';
$db_user     = 'proxyserver';
$db_password = 'proxyserver';
$db_name     = 'proxyserver';

// Records older than this (seconds) get deleted by cleanup
define('CLEANUP_MAX_AGE', 600); // 10 minutes

// Dashboard password
define('DASHBOARD_PASSWORD', 'ispledger2026');
