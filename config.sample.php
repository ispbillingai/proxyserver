<?php
// Proxy Cache Server Configuration
// Copy this file to config.php and update credentials

$db_host     = 'localhost';
$db_user     = 'your_db_user';
$db_password = 'your_db_password';
$db_name     = 'your_db_name';

// Records older than this (seconds) get deleted by cleanup
define('CLEANUP_MAX_AGE', 600); // 10 minutes

// Dashboard password
define('DASHBOARD_PASSWORD', 'change_this_password');
