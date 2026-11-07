<?php
/**
 * AFM#2 - Test Database Isolation & Safety Guard
 * 
 * STRICT DATA SAFETY MANDATE:
 * Under no circumstances may automated tests connect to, modify, truncate, or drop tables
 * in the persistent application database ('afm_db') or any production database.
 * 
 * This guard enforces:
 * 1. Test connections MUST target 'afm_test_db'.
 * 2. Database host must be local (127.0.0.1, localhost, ::1).
 * 3. APP_ENV must not be 'production'.
 * 4. The target database cannot be 'afm_db' or contain 'prod'.
 * 5. Destructive operations fail closed if the target cannot be verified.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

class TestDatabaseSecurityException extends RuntimeException {}

/**
 * Positive identification constant for isolated test database
 */
if (!defined('AFM_ALLOWED_TEST_DB')) {
    define('AFM_ALLOWED_TEST_DB', 'afm_test_db');
}

/**
 * Returns a verified, isolated PDO connection targeting 'afm_test_db'.
 * Will fail closed and throw TestDatabaseSecurityException if isolation criteria are unmet.
 */
function get_test_db_connection(bool $forceNew = false): PDO {
    // Explicitly enforce test DB constant & environment variable
    if (!defined('AFM_TEST_DB_NAME')) {
        define('AFM_TEST_DB_NAME', AFM_ALLOWED_TEST_DB);
    }
    putenv('TEST_DB_NAME=' . AFM_ALLOWED_TEST_DB);
    $_ENV['TEST_DB_NAME'] = AFM_ALLOWED_TEST_DB;

    $targetDb = getenv('TEST_DB_NAME') ?: AFM_ALLOWED_TEST_DB;

    // Connect specifically to the test database
    try {
        $pdo = get_db_connection($forceNew, $targetDb);
    } catch (\Throwable $e) {
        throw new TestDatabaseSecurityException(
            "[TEST DATABASE CONNECTION FAILED] Unable to connect to isolated test database '{$targetDb}'.\n" .
            "Error: " . $e->getMessage() . "\n" .
            "Action required: Ensure MySQL is running and the database exists:\n" .
            "  mysql -u " . DB_USER . " -e \"CREATE DATABASE IF NOT EXISTS " . AFM_ALLOWED_TEST_DB . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;\"\n"
        );
    }

    assert_test_db_isolated($pdo, 'get_test_db_connection');
    return $pdo;
}

/**
 * Asserts that the provided PDO connection is strictly isolated to a disposable test database.
 * Throws TestDatabaseSecurityException on ANY violation.
 */
function assert_test_db_isolated(PDO $pdo, string $context = 'test'): void {
    // 1. Host verification: refuse remote databases
    $dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $localHosts = ['127.0.0.1', 'localhost', '::1'];
    if (!in_array(strtolower($dbHost), $localHosts, true)) {
        throw new TestDatabaseSecurityException(
            "[SECURITY GUARD TRIGGERED in {$context}] Destructive test operations are strictly forbidden on remote database host '{$dbHost}'! " .
            "Tests must execute only against a local loopback database."
        );
    }

    // 2. Production environment check
    $env = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'development');
    if (strtolower($env) === 'production') {
        throw new TestDatabaseSecurityException(
            "[SECURITY GUARD TRIGGERED in {$context}] Test execution is strictly forbidden in production environment (APP_ENV=production)!"
        );
    }

    // 3. Query active database directly from MySQL server
    try {
        $currentDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    } catch (\Throwable $e) {
        throw new TestDatabaseSecurityException(
            "[SECURITY GUARD TRIGGERED in {$context}] Failed to query current database identity: " . $e->getMessage()
        );
    }

    if ($currentDb === '') {
        throw new TestDatabaseSecurityException(
            "[SECURITY GUARD TRIGGERED in {$context}] No active database selected on connection!"
        );
    }

    // 4. Primary application database guard ('afm_db')
    $appDb = defined('DB_NAME') ? DB_NAME : 'afm_db';
    if (strtolower($currentDb) === strtolower($appDb) || strtolower($currentDb) === 'afm_db') {
        throw new TestDatabaseSecurityException(
            "[CRITICAL DATA SAFETY VIOLATION in {$context}] Attempted destructive test operation against primary application database '{$currentDb}'! " .
            "Execution halted immediately to protect real user accounts and catalogue data."
        );
    }

    // 5. Production database name pattern guard
    if (str_contains(strtolower($currentDb), 'prod') || str_contains($currentDb, 'if0_43219637')) {
        throw new TestDatabaseSecurityException(
            "[CRITICAL DATA SAFETY VIOLATION in {$context}] Production database identifier detected ('{$currentDb}')! Refusing all operations."
        );
    }

    // 6. Positive whitelist check: must equal AFM_ALLOWED_TEST_DB or end with _test_db / _test
    $isAllowed = (
        $currentDb === AFM_ALLOWED_TEST_DB || 
        str_ends_with(strtolower($currentDb), '_test_db') || 
        str_ends_with(strtolower($currentDb), '_test')
    );

    if (!$isAllowed) {
        throw new TestDatabaseSecurityException(
            "[SECURITY GUARD TRIGGERED in {$context}] Database '{$currentDb}' is not positively identified as an isolated test database. " .
            "Expected '" . AFM_ALLOWED_TEST_DB . "'."
        );
    }
}
