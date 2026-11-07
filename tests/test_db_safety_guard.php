<?php
/**
 * AFM#2 - Regression Test for Database Safety Guard
 * 
 * Verifies that:
 * 1. Test database connections target exclusively 'afm_test_db'.
 * 2. Any attempt to run destructive test routines against 'afm_db', production, or remote hosts fails closed.
 * 3. Primary application database ('afm_db') data is completely preserved.
 */

require_once __DIR__ . '/test_db_guard.php';

$passed = 0;
$failed = 0;

function assert_guard(bool $condition, string $testName): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \033[32m✔ PASS:\033[0m {$testName}\n";
    } else {
        $failed++;
        echo "  \033[31m✖ FAIL:\033[0m {$testName}\n";
    }
}

echo "\n\033[1;36m======================================================================\033[0m\n";
echo "\033[1;36m AFM#2 DATABASE SAFETY GUARD REGRESSION TEST SUITE\033[0m\n";
echo "\033[1;36m======================================================================\033[0m\n\n";

// 1. Positive Test: get_test_db_connection() targets afm_test_db
echo "\033[1m1. Positive Isolation Verification\033[0m\n";
try {
    $testPdo = get_test_db_connection();
    $activeDb = (string)$testPdo->query("SELECT DATABASE()")->fetchColumn();
    assert_guard($activeDb === 'afm_test_db', "get_test_db_connection() connects strictly to 'afm_test_db' (got: {$activeDb})");
} catch (\Throwable $e) {
    assert_guard(false, "get_test_db_connection() threw unexpected exception: " . $e->getMessage());
}

// 2. Negative Test: Simulated connection to 'afm_db' MUST be rejected
echo "\n\033[1m2. Rejection of Primary Application Database (afm_db)\033[0m\n";
$rejectedAfmDb = false;
try {
    // Deliberately connect to afm_db
    $rawPdo = get_db_connection(true, 'afm_db');
    // Guard must reject it
    assert_test_db_isolated($rawPdo, 'simulate_unsafe_afm_db');
} catch (TestDatabaseSecurityException $e) {
    $rejectedAfmDb = true;
    echo "    Notice: Caught expected exception: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo "    Notice: Caught other exception: " . $e->getMessage() . "\n";
}
assert_guard($rejectedAfmDb, "assert_test_db_isolated() refuses execution against primary application database 'afm_db'");

// 3. Negative Test: Simulated production database name pattern
echo "\n\033[1m3. Rejection of Production Database Patterns\033[0m\n";
$rejectedProd = false;
try {
    $rawPdo = get_db_connection(true, 'afm_prod_rehearsal_db');
    assert_test_db_isolated($rawPdo, 'simulate_prod_db');
} catch (TestDatabaseSecurityException $e) {
    $rejectedProd = true;
    echo "    Notice: Caught expected exception: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo "    Notice: Caught other exception: " . $e->getMessage() . "\n";
}
assert_guard($rejectedProd, "assert_test_db_isolated() refuses execution against 'afm_prod_rehearsal_db'");

// 4. Negative Test: Simulated arbitrary database (emp_db)
echo "\n\033[1m4. Rejection of Non-Test Databases\033[0m\n";
$rejectedArbitrary = false;
try {
    $rawPdo = get_db_connection(true, 'emp_db');
    assert_test_db_isolated($rawPdo, 'simulate_emp_db');
} catch (TestDatabaseSecurityException $e) {
    $rejectedArbitrary = true;
    echo "    Notice: Caught expected exception: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo "    Notice: Caught other exception: " . $e->getMessage() . "\n";
}
assert_guard($rejectedArbitrary, "assert_test_db_isolated() refuses execution against non-test database 'emp_db'");

// 5. Baseline Data Preservation Check: Verify afm_db was never modified
echo "\n\033[1m5. Primary Application Data Integrity Verification\033[0m\n";
$appPdo = get_db_connection(true, 'afm_db');
$songCount = (int)$appPdo->query("SELECT COUNT(*) FROM songs")->fetchColumn();
$hasMasterStarter = (bool)$appPdo->query("SHOW TABLES LIKE 'master_starter_songs'")->fetchColumn();
if ($hasMasterStarter) {
    $masterCount = (int)$appPdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
    assert_guard($masterCount === 2411, "afm_db master starter songs remains intact at 2,411 (actual: {$masterCount})");
} else {
    assert_guard($songCount === 2411, "afm_db songs catalogue remains intact at 2,411 (actual: {$songCount})");
}
$userCount = (int)$appPdo->query("SELECT COUNT(*) FROM app_users")->fetchColumn();
assert_guard($userCount === 3, "afm_db real user accounts intact at 3 (actual: {$userCount})");

echo "\n\033[1;36m======================================================================\033[0m\n";
echo " Results: \033[32m{$passed} Passed\033[0m, \033[31m{$failed} Failed\033[0m\n";
echo "\033[1;36m======================================================================\033[0m\n\n";

if ($failed > 0) {
    exit(1);
}
