<?php
/**
 * AFM#2 - Timezone Diagnostic Verification Tool
 * Reports PHP, MySQL, UTC and IST timestamps for verification.
 * Protected against direct web exposure; intended for CLI / test runner usage.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    // If accessed over HTTP, require admin session or fail closed
    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    if (!is_admin()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "403 Forbidden: Protected diagnostic tool.\n";
        exit;
    }
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();

$phpTz = date_default_timezone_get();
$phpNow = date('Y-m-d H:i:s T');
$phpUtc = gmdate('Y-m-d H:i:s T');

$dbGlobalTz = (string)$pdo->query("SELECT @@global.time_zone")->fetchColumn();
$dbSessionTz = (string)$pdo->query("SELECT @@session.time_zone")->fetchColumn();
$dbNow = (string)$pdo->query("SELECT NOW()")->fetchColumn();
$dbUtc = (string)$pdo->query("SELECT UTC_TIMESTAMP()")->fetchColumn();
$formattedIst = format_local_timestamp($dbNow, 'D, M j, Y h:i:s A T');

$diagData = [
    'php_timezone'             => $phpTz,
    'php_current_time'         => $phpNow,
    'php_utc_time'             => $phpUtc,
    'mysql_global_timezone'    => $dbGlobalTz,
    'mysql_session_timezone'   => $dbSessionTz,
    'mysql_current_time'       => $dbNow,
    'mysql_utc_timestamp'      => $dbUtc,
    'application_formatted_ist'=> $formattedIst
];

if (php_sapi_name() === 'cli') {
    echo "======================================================================\n";
    echo " AFM#2 — TIMEZONE & IST DIAGNOSTIC REPORT\n";
    echo "======================================================================\n";
    echo "PHP Timezone:               {$phpTz}\n";
    echo "PHP Current Local Time:     {$phpNow}\n";
    echo "PHP UTC Time:               {$phpUtc}\n";
    echo "MySQL Global Timezone:      {$dbGlobalTz}\n";
    echo "MySQL Session Timezone:     {$dbSessionTz}\n";
    echo "MySQL Current Time (NOW):   {$dbNow}\n";
    echo "MySQL UTC_TIMESTAMP():      {$dbUtc}\n";
    echo "Application-Formatted IST:  {$formattedIst}\n";
    echo "======================================================================\n";
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($diagData, JSON_PRETTY_PRINT);
}
