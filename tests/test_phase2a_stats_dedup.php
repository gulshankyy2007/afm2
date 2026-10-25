<?php
/**
 * AFM#2 - Phase 2A Regression Test Suite: Dashboard Stats Deduplication, Cache Consistency & Honest Fallbacks
 */

declare(strict_types=1);

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();

// Fail-safe shutdown function to ensure any open transaction is rolled back on unexpected exit or failure
register_shutdown_function(function() use ($pdo) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});

function assert_phase2a(bool $condition, string $description): void {
    if ($condition) {
        echo "  ✔ PASS: {$description}\n";
    } else {
        echo "  ✖ FAIL: {$description}\n";
        exit(1);
    }
}

echo "\n======================================================================\n";
echo " AFM#2 — PHASE 2A STATS DEDUPLICATION, CACHE & FALLBACK TESTS\n";
echo "======================================================================\n\n";

// ---------------------------------------------------------------------
// 1. Initial State & Unified Cache Container
// ---------------------------------------------------------------------
echo "1. Initial State & Unified Cache Lifecycle\n";
clear_dashboard_stats_cache();
$cacheRef = &get_dashboard_stats_cache_ref();
assert_phase2a($cacheRef['stats'] === null, "Initial cache['stats'] is null after clear");
assert_phase2a($cacheRef['song_count'] === null, "Initial cache['song_count'] is null after clear");
assert_phase2a(get_cached_dashboard_stats() === null, "get_cached_dashboard_stats() returns null when empty");

// ---------------------------------------------------------------------
// 2. Request-Level Memoization of get_dashboard_stats()
// ---------------------------------------------------------------------
echo "\n2. get_dashboard_stats() Memoization\n";
$q0 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$stats1 = get_dashboard_stats();
$q1 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$firstRunQueries = $q1 - $q0 - 1;

assert_phase2a(is_array($stats1), "get_dashboard_stats() returns array");
assert_phase2a(isset($stats1['total_songs']) && $stats1['total_songs'] >= 2400, "total_songs is populated ({$stats1['total_songs']})");
assert_phase2a($firstRunQueries >= 8, "First call executes database queries (queries: {$firstRunQueries})");

// Second call within same request
$q2 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$stats2 = get_dashboard_stats();
$q3 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$secondRunQueries = $q3 - $q2 - 1;

assert_phase2a($secondRunQueries === 0, "Second call within same request executes ZERO queries");
assert_phase2a($stats1 === $stats2, "Second call returns identical memoized data structure");
assert_phase2a(get_cached_dashboard_stats() !== null, "get_cached_dashboard_stats() reflects populated cache");

// ---------------------------------------------------------------------
// 3. Cache Reuse: Cached Count Reused When Appropriate
// ---------------------------------------------------------------------
echo "\n3. Cached Count Reuse\n";
// Since get_dashboard_stats() was called, cache['song_count'] is already populated
$q4 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$countFromStats = get_catalogue_song_count();
$q5 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$countQueries = $q5 - $q4 - 1;

assert_phase2a($countQueries === 0, "get_catalogue_song_count() reuses existing cached stats with ZERO queries");
assert_phase2a($countFromStats === $stats1['total_songs'], "Count matches dashboard stats total_songs ({$countFromStats})");

// Direct get_catalogue_song_count() caching test from clean state
clear_dashboard_stats_cache();
$q6 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$directCount1 = get_catalogue_song_count();
$q7 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$directRunQueries1 = $q7 - $q6 - 1;

assert_phase2a($directRunQueries1 === 1, "Clean get_catalogue_song_count() executes strictly 1 query");

$q8 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$directCount2 = get_catalogue_song_count();
$q9 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$directRunQueries2 = $q9 - $q8 - 1;

assert_phase2a($directRunQueries2 === 0, "Subsequent get_catalogue_song_count() reuses cached count with ZERO queries");
assert_phase2a($directCount1 === $directCount2, "Repeated count calls return identical value ({$directCount1})");

// ---------------------------------------------------------------------
// 4. Cache Invalidation: clear_dashboard_stats_cache() Clears Both
// ---------------------------------------------------------------------
echo "\n4. Cache Invalidation via clear_dashboard_stats_cache()\n";
// Populate both stats and song_count
get_dashboard_stats();
get_catalogue_song_count();
$cacheRef = &get_dashboard_stats_cache_ref();
assert_phase2a($cacheRef['stats'] !== null, "Pre-condition: cache['stats'] is populated");
assert_phase2a($cacheRef['song_count'] !== null, "Pre-condition: cache['song_count'] is populated");

clear_dashboard_stats_cache();
assert_phase2a($cacheRef['stats'] === null, "clear_dashboard_stats_cache() invalidates cache['stats']");
assert_phase2a($cacheRef['song_count'] === null, "clear_dashboard_stats_cache() invalidates cache['song_count']");
assert_phase2a(get_cached_dashboard_stats() === null, "get_cached_dashboard_stats() is null after clear");

// Verify that a subsequent call re-queries because cache was invalidated
$q10 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$reloadedCount = get_catalogue_song_count();
$q11 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$reloadedQueries = $q11 - $q10 - 1;

assert_phase2a($reloadedQueries === 1, "get_catalogue_song_count() re-queries database after cache clear (queries: {$reloadedQueries})");

// ---------------------------------------------------------------------
// 5. Simulated Song Count Change Followed by Refresh
// ---------------------------------------------------------------------
echo "\n5. Simulated Song Count Change & Refresh Verification\n";
clear_dashboard_stats_cache();
$baseCount = get_catalogue_song_count();
assert_phase2a(is_int($baseCount) && $baseCount >= 2400, "Base catalogue count obtained ({$baseCount})");

// Transaction isolation with catch + finally ensures 100% rollback guarantee
$pdo->beginTransaction();
try {
    $pdo->exec("
        INSERT INTO `songs` (`title`, `artist`, `is_consolidated`) 
        VALUES ('__SIMULATED_TEST_SONG_PHASE2A__', 'Test Performer', 0)
    ");

    // Without refresh or clearing, cached count must be returned
    $cachedBeforeRefresh = get_catalogue_song_count();
    assert_phase2a($cachedBeforeRefresh === $baseCount, "Without refresh or clearing, cached count is preserved ({$cachedBeforeRefresh} === {$baseCount})");

    // With forceRefresh = true, the new database count must be fetched
    $refreshedCount = get_catalogue_song_count(true);
    assert_phase2a($refreshedCount === $baseCount + 1, "get_catalogue_song_count(true) returns updated count ({$refreshedCount} === " . ($baseCount + 1) . ")");

    // With clear_dashboard_stats_cache(), subsequent call returns the new count
    clear_dashboard_stats_cache();
    $clearedAndReloadedCount = get_catalogue_song_count();
    assert_phase2a($clearedAndReloadedCount === $baseCount + 1, "After clear_dashboard_stats_cache(), fresh call returns updated count ({$clearedAndReloadedCount})");

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// Confirm rollback and reset cache
clear_dashboard_stats_cache();
$restoredCount = get_catalogue_song_count();
assert_phase2a($restoredCount === $baseCount, "Rollback cleanly restored original count ({$restoredCount} === {$baseCount})");

// ---------------------------------------------------------------------
// 6. Database Failure Handling & No Fabricated Numeric Count
// ---------------------------------------------------------------------
echo "\n6. Database Failure Handling & Honest Fallbacks\n";
// Test get_catalogue_song_count() and footer in an isolated child process
$testFailureCode = <<<'PHP'
<?php
define('AFM_APP', true);
// Force connection failure through the authentic config/db stack by pointing to unreachable port
$_ENV['DB_PORT'] = 59999;
require_once '/mnt/data/GY/Music/AFM#2/config/config.php';
require_once '/mnt/data/GY/Music/AFM#2/includes/db_helper.php';

// Test 1: get_catalogue_song_count returns null on failure (never 2461, never 2475)
clear_dashboard_stats_cache();
$count = get_catalogue_song_count();

// Test 2: Footer renders graceful non-numeric fallback without exposing errors
ob_start();
require '/mnt/data/GY/Music/AFM#2/includes/footer.php';
$footerHtml = ob_get_clean();

echo json_encode([
    'count_result'         => $count,
    'footer_has_2461'      => (strpos($footerHtml, '2461') !== false || strpos($footerHtml, '2,461') !== false),
    'footer_has_2475'      => (strpos($footerHtml, '2475') !== false || strpos($footerHtml, '2,475') !== false),
    'fallback_rendered'    => (strpos($footerHtml, 'Catalogue Preserved') !== false),
    'dot_live_rendered'    => (strpos($footerHtml, 'dot-live') !== false),
    'exception_leaked'     => (strpos($footerHtml, 'Connection refused') !== false || strpos($footerHtml, 'PDOException') !== false)
]);
PHP;

$tmpFailFile = sys_get_temp_dir() . '/afm_test_db_failure_' . uniqid() . '.php';
try {
    file_put_contents($tmpFailFile, $testFailureCode);
    $failOutputJson = shell_exec("php " . escapeshellarg($tmpFailFile) . " 2>/dev/null");
} finally {
    if (file_exists($tmpFailFile)) {
        unlink($tmpFailFile);
    }
}

$failData = json_decode($failOutputJson ?? '', true);
assert_phase2a(is_array($failData), "Simulation of database failure executed in isolated process");
assert_phase2a($failData['count_result'] === null, "get_catalogue_song_count() returns NULL on database failure (never fabricated int)");
assert_phase2a($failData['footer_has_2461'] === false, "Footer output contains ZERO fabricated numbers ('2461' or '2,461') on failure");
assert_phase2a($failData['footer_has_2475'] === false, "Footer output contains ZERO fabricated numbers ('2475' or '2,475') on failure");
assert_phase2a($failData['fallback_rendered'] === true, "Footer gracefully displays 'Catalogue Preserved' on failure");
assert_phase2a($failData['dot_live_rendered'] === true, "Footer preserves styled status pill with dot-live indicator");
assert_phase2a($failData['exception_leaked'] === false, "Zero database exception details leaked to visitor");

// ---------------------------------------------------------------------
// 7. Forced-Refresh Failure Behavior & Stale Cache Invalidation
// ---------------------------------------------------------------------
echo "\n7. Forced-Refresh Failure Behavior & Cache Invalidation\n";
// Test scenario where valid count is cached, forced refresh fails, and subsequent call is made without forceRefresh
$testRefreshFailureCode = <<<'PHP'
<?php
define('AFM_APP', true);
require_once '/mnt/data/GY/Music/AFM#2/config/config.php';
require_once '/mnt/data/GY/Music/AFM#2/config/db.php';
require_once '/mnt/data/GY/Music/AFM#2/includes/db_helper.php';

$pdo = get_db_connection();
// Step 1: Cache valid stats and song count
$stats = get_dashboard_stats();
$c1 = get_catalogue_song_count();

// Step 2: Sabotage the connection thread to simulate sudden mid-request database drop
$threadId = (int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
try {
    $pdo->query("KILL {$threadId}");
} catch (\Throwable $e) {}

// Step 3: Call get_catalogue_song_count(true) to force refresh
$refreshResult = get_catalogue_song_count(true);

// Step 4: Call get_catalogue_song_count() again WITHOUT force refresh
$subsequentResult = get_catalogue_song_count();

$cache = &get_dashboard_stats_cache_ref();

echo json_encode([
    'initial_cached_count' => $c1,
    'refresh_result'       => $refreshResult,
    'subsequent_result'    => $subsequentResult,
    'cache_song_count'     => $cache['song_count'],
    'cache_stats'          => $cache['stats']
]);
PHP;

$tmpRefreshFailFile = sys_get_temp_dir() . '/afm_test_refresh_fail_' . uniqid() . '.php';
try {
    file_put_contents($tmpRefreshFailFile, $testRefreshFailureCode);
    $refreshFailJson = shell_exec("php " . escapeshellarg($tmpRefreshFailFile) . " 2>/dev/null");
} finally {
    if (file_exists($tmpRefreshFailFile)) {
        unlink($tmpRefreshFailFile);
    }
}

$refreshData = json_decode($refreshFailJson ?? '', true);
assert_phase2a(is_array($refreshData), "Forced-refresh failure test executed in isolated process");
assert_phase2a($refreshData['initial_cached_count'] === 2411, "Pre-condition: initial cached count was valid (2411)");
assert_phase2a($refreshData['refresh_result'] === null, "Failed forced refresh returns NULL (never fabricated count)");
assert_phase2a($refreshData['subsequent_result'] === null, "Subsequent call without force refresh returns NULL (stale count was invalidated)");
assert_phase2a($refreshData['cache_song_count'] === null, "Cache['song_count'] is confirmed NULL after failed forced refresh");
assert_phase2a($refreshData['cache_stats'] === null, "Cache['stats'] is confirmed NULL after failed forced refresh");

// ---------------------------------------------------------------------
// 8. Existing Footer Presentation with Valid Count
// ---------------------------------------------------------------------
echo "\n8. Existing Footer Presentation with Valid Count\n";
// Case A: Footer rendered when $stats is already present (e.g. index.php, library.php)
clear_dashboard_stats_cache();
$stats = get_dashboard_stats();
$validCount = $stats['total_songs'];

ob_start();
require dirname(__DIR__) . '/includes/footer.php';
$footerHtmlWithStats = ob_get_clean();

assert_phase2a(strpos($footerHtmlWithStats, number_format($validCount) . ' Songs Preserved') !== false, "Footer renders '" . number_format($validCount) . " Songs Preserved' with in-scope stats");
assert_phase2a(strpos($footerHtmlWithStats, 'dot-live') !== false, "Footer renders live status indicator");
assert_phase2a(strpos($footerHtmlWithStats, '2461') === false && strpos($footerHtmlWithStats, '2,461') === false, "Footer has no fabricated 2461 count");

// Case B: Footer rendered when $stats is NOT present (e.g. auditions.php, tournament.php)
unset($stats);
clear_dashboard_stats_cache();

ob_start();
require dirname(__DIR__) . '/includes/footer.php';
$footerHtmlWithoutStats = ob_get_clean();

assert_phase2a(strpos($footerHtmlWithoutStats, number_format($validCount) . ' Songs Preserved') !== false, "Footer renders '" . number_format($validCount) . " Songs Preserved' without in-scope stats via catalogue helper");
assert_phase2a(strpos($footerHtmlWithoutStats, 'dot-live') !== false, "Footer renders live status indicator");

// ---------------------------------------------------------------------
// 9. Baseline Catalogue Invariant Safeguard
// ---------------------------------------------------------------------
echo "\n9. Catalogue Invariant Safeguard\n";
$finalSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
assert_phase2a($finalSongCount === 2411, "MANDATORY INVARIANT: Active catalogue songs remain strictly intact (count: {$finalSongCount} === 2411)");

$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_phase2a($nullArtistCount === 3, "MANDATORY INVARIANT: Authentic NULL artist records preserved intact (count: {$nullArtistCount} === 3)");

echo "\n======================================================================\n";
echo " ALL PHASE 2A TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
echo "======================================================================\n\n";
