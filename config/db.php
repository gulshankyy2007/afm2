<?php
/**
 * AFM#2 - Database Connection Provider (PDO)
 */

if (!defined('AFM_APP')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Returns a shared PDO connection to the MySQL database.
 * Uses prepared statements and safe error handling.
 */
function get_db_connection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host    = DB_HOST;
    $port    = DB_PORT;
    $dbname  = DB_NAME;
    $user    = DB_USER;
    $pass    = DB_PASS;
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    } catch (PDOException $e) {
        // Log detailed error internally
        error_log("AFM2 Database Connection Error: " . $e->getMessage());
        // Show safe sanitized error to client
        throw new RuntimeException("Database connection error. Please contact the administrator or check configuration.");
    }

    return $pdo;
}
