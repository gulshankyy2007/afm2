<?php
/**
 * AFM#2 - Phase 2A Regression Test Suite: Dashboard Stats Deduplication & Footer Optimization
 */

declare(strict_types=1);

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

function assert_phase2a(bool $condition, string $description): void {
    if ($condition) {
        echo "  ✔ PASS: {$description}\n";
    } else {
        echo "  ✖ FAIL: {$description}\n";
        exit(1);
    }
}

echo "\n======================================================================\n";
echo " AFM#2 — PHASE 2A STATS DEDUPLICATION & FOOTER OPTIMIZATION TESTS\n";
echo "======================================================================\n\n";

// 1. Initial State
echo "1. Cache State & Helpers\n";
clear_dashboard_stats_cache();
assert_phase2a(get_cached_dashboard_stats() === null, "Cache is initially empty after clear");

// 2. Request-Level Memoization
echo "\n2. Request-Level Memoization\n";
$pdo = get_db_connection();

$questionsBefore = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$stats1 = get_dashboard_stats();
$questionsAfter1 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$queriesRunFirstTime = $questionsAfter1 - $questionsBefore - 1; // subtract SHOW STATUS query

assert_phase2a(is_array($stats1), "get_dashboard_stats() returns array");
assert_phase2a(isset($stats1['total_songs']) && $stats1['total_songs'] >= 2400, "total_songs is populated ({$stats1['total_songs']})");
assert_phase2a($queriesRunFirstTime >= 8, "First call executes database queries (queries: {$queriesRunFirstTime})");

// Second call in same request
$questionsBefore2 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$stats2 = get_dashboard_stats();
$questionsAfter2 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$queriesRunSecondTime = $questionsAfter2 - $questionsBefore2 - 1;

assert_phase2a($queriesRunSecondTime === 0, "Second call within same request executes ZERO database queries");
assert_phase2a($stats1 === $stats2, "Second call returns identical memoized data structure");
assert_phase2a(get_cached_dashboard_stats() !== null, "get_cached_dashboard_stats() reflects populated cache");

// 3. Force-Refresh Parameter
echo "\n3. Force-Refresh Parameter\n";
$questionsBefore3 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$stats3 = get_dashboard_stats(true);
$questionsAfter3 = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$queriesRunForce = $questionsAfter3 - $questionsBefore3 - 1;

assert_phase2a($queriesRunForce >= 8, "get_dashboard_stats(true) bypasses cache and re-queries database (queries: {$queriesRunForce})");

// 4. Lightweight Catalogue Song Count Helper
echo "\n4. Lightweight Song Count Helper\n";
clear_dashboard_stats_cache();
$questionsBeforeCount = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$count = get_catalogue_song_count(true);
$questionsAfterCount = (int)$pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_ASSOC)['Value'];
$queriesRunCount = $questionsAfterCount - $questionsBeforeCount - 1;

assert_phase2a($count >= 2400, "get_catalogue_song_count() returns correct catalogue count ({$count})");
assert_phase2a($queriesRunCount === 1, "get_catalogue_song_count() runs strictly 1 lightweight query (queries: {$queriesRunCount})");

// 5. Footer Rendering Invariant Check
echo "\n5. Footer Presentation & Output Verification\n";
// Case A: Footer rendered when $stats is already present (e.g. index.php, library.php)
$stats = get_dashboard_stats();
ob_start();
require dirname(__DIR__) . '/includes/footer.php';
$footerHtmlWithStats = ob_get_clean();

assert_phase2a(strpos($footerHtmlWithStats, number_format($count) . ' Songs Preserved') !== false, "Footer renders dynamic count with in-scope stats");
assert_phase2a(strpos($footerHtmlWithStats, 'dot-live') !== false, "Footer renders live status indicator");

// Case B: Footer rendered when $stats is NOT present (e.g. auditions.php, tournament.php)
unset($stats);
clear_dashboard_stats_cache();
ob_start();
require dirname(__DIR__) . '/includes/footer.php';
$footerHtmlWithoutStats = ob_get_clean();

assert_phase2a(strpos($footerHtmlWithoutStats, number_format($count) . ' Songs Preserved') !== false, "Footer renders identical dynamic count without in-scope stats");

echo "\n======================================================================\n";
echo " ALL PHASE 2A TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
echo "======================================================================\n\n";
