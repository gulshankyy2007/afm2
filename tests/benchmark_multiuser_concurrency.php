<?php
/**
 * AFM#2 — Multiuser Performance & Concurrency Benchmark Harness
 * 
 * Measures:
 * 1. Dashboard statistics calculation (cold vs warm).
 * 2. Catalogue search, sorting (ID, Title, Artist), filtering, pagination.
 * 3. Discovery shortlisting and entry provisioning.
 * 4. Scorecard rating writes & leaderboard calculation.
 * 5. Tournament advancement & historical results.
 * 6. CSV import processing throughput.
 * 7. YouTube matching statistics & candidate retrieval.
 * 8. Authentication & session verification.
 * 
 * Usage: php tests/benchmark_multiuser_concurrency.php [--iterations=50]
 */

declare(strict_types=1);

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';

// Parse CLI options
$options = getopt('', ['iterations::', 'json::']);
$iterations = isset($options['iterations']) ? max(5, (int)$options['iterations']) : 30;

echo "======================================================================\n";
echo " AFM#2 — MULTIUSER PERFORMANCE BENCHMARK HARNESS\n";
echo " Environment: PHP " . PHP_VERSION . " | MySQL 8.4 on Linux (127.0.0.1)\n";
echo " Budget Target: Strictly ₹0.00/month (Shared Hosting Resource Constraints)\n";
echo " Iterations per workload: {$iterations}\n";
echo "======================================================================\n\n";

$pdo = get_db_connection();

// --- Setup dedicated benchmark users ---
$pdo->exec("
    INSERT INTO `app_users` (`user_id`, `username`, `email`, `password_hash`, `role`, `is_active`)
    VALUES 
        (981, 'bench_user_a', 'bench_a@afm2.local', '" . password_hash('BenchPass2026!', PASSWORD_DEFAULT) . "', 'user', 1),
        (982, 'bench_user_b', 'bench_b@afm2.local', '" . password_hash('BenchPass2026!', PASSWORD_DEFAULT) . "', 'user', 1)
    ON DUPLICATE KEY UPDATE `is_active` = 1
");

$uidA = 981;
$uidB = 982;

// Utility functions for statistical calculation
function compute_stats(array $latenciesMs): array {
    if (empty($latenciesMs)) {
        return ['count' => 0, 'median' => 0.0, 'p95' => 0.0, 'min' => 0.0, 'max' => 0.0, 'mean' => 0.0];
    }
    sort($latenciesMs);
    $n = count($latenciesMs);
    $median = ($n % 2 === 0) 
        ? ($latenciesMs[($n / 2) - 1] + $latenciesMs[$n / 2]) / 2.0 
        : $latenciesMs[(int)floor($n / 2)];
    $p95Index = (int)ceil($n * 0.95) - 1;
    $p95 = $latenciesMs[max(0, min($n - 1, $p95Index))];
    $sum = array_sum($latenciesMs);

    return [
        'count'  => $n,
        'min'    => round(min($latenciesMs), 3),
        'max'    => round(max($latenciesMs), 3),
        'mean'   => round($sum / $n, 3),
        'median' => round($median, 3),
        'p95'    => round($p95, 3),
        'rps'    => round($n / max(0.001, $sum / 1000.0), 1)
    ];
}

$results = [];

// -----------------------------------------------------------------------------
// Benchmark 1: Dashboard and Statistics (Cold vs Warm)
// -----------------------------------------------------------------------------
echo "[1/8] Benchmarking Dashboard & Statistics...\n";
$coldLatencies = [];
$warmLatencies = [];

for ($i = 0; $i < $iterations; $i++) {
    // Cold: forceRefresh = true
    $t0 = microtime(true);
    get_dashboard_stats($uidA, true);
    $coldLatencies[] = (microtime(true) - $t0) * 1000.0;

    // Warm: memoized within same execution cycle
    $t1 = microtime(true);
    get_dashboard_stats($uidA, false);
    $warmLatencies[] = (microtime(true) - $t1) * 1000.0;
}
$results['dashboard_cold'] = compute_stats($coldLatencies);
$results['dashboard_warm'] = compute_stats($warmLatencies);

// -----------------------------------------------------------------------------
// Benchmark 2: Catalogue Search, Filtering, Sorting & Pagination
// -----------------------------------------------------------------------------
echo "[2/8] Benchmarking Catalogue Search, Sorting & Pagination...\n";

// 2a: Default ID ASC page 1
$latSearchId = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    search_songs('', 'all', 1, 50, 'id_asc', $uidA);
    $latSearchId[] = (microtime(true) - $t0) * 1000.0;
}
$results['search_id_asc'] = compute_stats($latSearchId);

// 2b: Title ASC sorting (alphabetic filesort test)
$latSearchTitle = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    search_songs('', 'all', 1, 50, 'title_asc', $uidA);
    $latSearchTitle[] = (microtime(true) - $t0) * 1000.0;
}
$results['search_title_asc'] = compute_stats($latSearchTitle);

// 2c: Artist ASC sorting
$latSearchArtist = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    search_songs('', 'all', 1, 50, 'artist_asc', $uidA);
    $latSearchArtist[] = (microtime(true) - $t0) * 1000.0;
}
$results['search_artist_asc'] = compute_stats($latSearchArtist);

// 2d: Text query search (LIKE %love%)
$latSearchQuery = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    search_songs('love', 'all', 1, 50, 'id_asc', $uidA);
    $latSearchQuery[] = (microtime(true) - $t0) * 1000.0;
}
$results['search_query_like'] = compute_stats($latSearchQuery);

// 2e: Song detail retrieval by ID
$latGetSong = [];
for ($i = 0; $i < $iterations; $i++) {
    $songId = ($i % 50) + 1;
    $t0 = microtime(true);
    get_song_by_id($songId, $uidA);
    $latGetSong[] = (microtime(true) - $t0) * 1000.0;
}
$results['get_song_by_id'] = compute_stats($latGetSong);

// -----------------------------------------------------------------------------
// Benchmark 3: Shortlist & Audition Operations
// -----------------------------------------------------------------------------
echo "[3/8] Benchmarking Shortlist & Audition Operations...\n";

$latShortlist = [];
$testSongIds = range(100, 100 + $iterations - 1);

// Ensure test songs have no previous reviews for user A
$pdo->prepare("DELETE FROM `song_discovery` WHERE `user_id` = :uid AND `song_id` BETWEEN 100 AND 200")->execute([':uid' => $uidA]);

foreach ($testSongIds as $sId) {
    $t0 = microtime(true);
    update_song_discovery_status($sId, 'shortlisted', $uidA);
    $latShortlist[] = (microtime(true) - $t0) * 1000.0;
}
$results['shortlist_toggle'] = compute_stats($latShortlist);

// Audition entries retrieval (Round 1)
$latAuditionEntries = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    get_audition_entries(1, 'all', '', 1, 50, $uidA);
    $latAuditionEntries[] = (microtime(true) - $t0) * 1000.0;
}
$results['get_audition_entries'] = compute_stats($latAuditionEntries);

// -----------------------------------------------------------------------------
// Benchmark 4: Saving Ratings & Leaderboard Calculation
// -----------------------------------------------------------------------------
echo "[4/8] Benchmarking Scorecard Ratings & Leaderboard Calculations...\n";

// Fetch entries provisioned for User A
$entStmt = $pdo->prepare("SELECT `entry_id` FROM `afm_entries` WHERE `user_id` = :uid LIMIT :lim");
$entStmt->bindValue(':uid', $uidA, PDO::PARAM_INT);
$entStmt->bindValue(':lim', $iterations, PDO::PARAM_INT);
$entStmt->execute();
$entryIds = $entStmt->fetchAll(PDO::FETCH_COLUMN);

$latSaveRating = [];
foreach ($entryIds as $idx => $eid) {
    $m = ($idx % 5) + 6;
    $t0 = microtime(true);
    save_scorecard_rating((int)$eid, 1, $m, 8, 8, 9, 8, $uidA);
    $latSaveRating[] = (microtime(true) - $t0) * 1000.0;
}
$results['save_scorecard_rating'] = compute_stats($latSaveRating);

// Round rankings & leaderboard calculation
$latRankings = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    get_round_rankings(1, $uidA);
    $latRankings[] = (microtime(true) - $t0) * 1000.0;
}
$results['get_round_rankings'] = compute_stats($latRankings);

// -----------------------------------------------------------------------------
// Benchmark 5: Tournament Advancement & Historical Results
// -----------------------------------------------------------------------------
echo "[5/8] Benchmarking Tournament Advancement & Historical Results...\n";

$latPreviewAdvance = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    preview_stage_advancement(1, $uidA);
    $latPreviewAdvance[] = (microtime(true) - $t0) * 1000.0;
}
$results['preview_advancement'] = compute_stats($latPreviewAdvance);

// Historical results aggregation
$latHistory = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    get_historical_results($uidA);
    $latHistory[] = (microtime(true) - $t0) * 1000.0;
}
$results['get_historical_results'] = compute_stats($latHistory);

// -----------------------------------------------------------------------------
// Benchmark 6: CSV Import Processing Throughput
// -----------------------------------------------------------------------------
echo "[6/8] Benchmarking CSV Import Processing...\n";

// Generate in-memory CSV rows and write to temp file
$latImport = [];
for ($i = 0; $i < 5; $i++) {
    $tmpCsv = tempnam(sys_get_temp_dir(), 'bench_csv_');
    $freshRows = [];
    $freshRows[] = "Track Title,Artist Name,YouTube ID,Status";
    for ($j = 1; $j <= 30; $j++) {
        $freshRows[] = "\"Bench Batch {$i} Song {$j} " . bin2hex(random_bytes(4)) . "\",\"Bench Artist {$i}\",\"\",\"unreviewed\"";
    }
    file_put_contents($tmpCsv, implode("\n", $freshRows));
    $p = parse_and_validate_csv($tmpCsv);
    @unlink($tmpCsv);
    $approved = array_filter($p['rows'] ?? [], fn($r) => !empty($r['default_approved']));
    $t0 = microtime(true);
    execute_csv_import($approved, 'unreviewed', $uidA);
    $latImport[] = (microtime(true) - $t0) * 1000.0;
}
$results['execute_csv_import_30'] = compute_stats($latImport);

// Clean up benchmark imported songs
$pdo->prepare("DELETE FROM `songs` WHERE `title` LIKE 'Bench%' AND `user_id` = :uid")->execute([':uid' => $uidA]);

// -----------------------------------------------------------------------------
// Benchmark 7: YouTube Matching Statistics & Candidate Retrieval
// -----------------------------------------------------------------------------
echo "[7/8] Benchmarking YouTube Matcher Statistics & Candidates...\n";

$latMatchStatsCold = [];
$latMatchStatsWarm = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    YouTubeMatcher::get_matching_stats(true, $uidA);
    $latMatchStatsCold[] = (microtime(true) - $t0) * 1000.0;

    $t1 = microtime(true);
    YouTubeMatcher::get_matching_stats(false, $uidA);
    $latMatchStatsWarm[] = (microtime(true) - $t1) * 1000.0;
}
$results['matching_stats_cold'] = compute_stats($latMatchStatsCold);
$results['matching_stats_warm'] = compute_stats($latMatchStatsWarm);

$latCandidates = [];
for ($i = 0; $i < $iterations; $i++) {
    $songId = ($i % 30) + 1;
    $t0 = microtime(true);
    YouTubeMatcher::get_candidates_for_song($songId);
    $latCandidates[] = (microtime(true) - $t0) * 1000.0;
}
$results['get_candidates_for_song'] = compute_stats($latCandidates);

// -----------------------------------------------------------------------------
// Benchmark 8: Authentication & Session Verification
// -----------------------------------------------------------------------------
echo "[8/8] Benchmarking Authentication & Password Verification...\n";

$latAuth = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = microtime(true);
    attempt_login('bench_user_a', 'BenchPass2026!');
    $latAuth[] = (microtime(true) - $t0) * 1000.0;
}
$results['attempt_login'] = compute_stats($latAuth);

// Clean rate limits
clear_rate_limits();

// --- Cleanup benchmark user data ---
$pdo->prepare("DELETE FROM `ratings` WHERE `user_id` IN (:u1, :u2)")->execute([':u1' => $uidA, ':u2' => $uidB]);
$pdo->prepare("DELETE FROM `afm_entries` WHERE `user_id` IN (:u1, :u2)")->execute([':u1' => $uidA, ':u2' => $uidB]);
$pdo->prepare("DELETE FROM `song_discovery` WHERE `user_id` IN (:u1, :u2)")->execute([':u1' => $uidA, ':u2' => $uidB]);
$pdo->prepare("DELETE FROM `songs` WHERE `user_id` IN (:u1, :u2)")->execute([':u1' => $uidA, ':u2' => $uidB]);
$pdo->prepare("DELETE FROM `app_users` WHERE `user_id` IN (:u1, :u2)")->execute([':u1' => $uidA, ':u2' => $uidB]);

// Output formatted results table
echo "\n========================================================================================\n";
echo " BENCHMARK EXECUTION SUMMARY (BASELINE PROFILE)\n";
echo "========================================================================================\n";
printf("%-32s | %-8s | %-10s | %-10s | %-10s | %-10s\n", "Operation / Workload", "Samples", "Median(ms)", "p95(ms)", "Mean(ms)", "Est. RPS");
echo "----------------------------------------------------------------------------------------\n";
foreach ($results as $op => $st) {
    printf("%-32s | %-8d | %-10.2f | %-10.2f | %-10.2f | %-10.1f\n", 
        $op, 
        $st['count'], 
        $st['median'], 
        $st['p95'], 
        $st['mean'], 
        $st['rps']
    );
}
echo "========================================================================================\n\n";

// Save JSON artifact
$jsonFile = __DIR__ . '/optimized_benchmark_results.json';
file_put_contents($jsonFile, json_encode($results, JSON_PRETTY_PRINT));
echo "Saved raw benchmark results to: {$jsonFile}\n";
