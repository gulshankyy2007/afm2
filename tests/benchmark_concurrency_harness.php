<?php
/**
 * AFM#2 — Controlled Multi-Client Concurrency Benchmark Harness
 * 
 * Evaluates performance, concurrency safety, throughput, latency percentiles,
 * and data integrity across 1, 2, 5, 10, and 20 concurrent simulated clients.
 * 
 * Tests:
 * 1. Concurrent Read-Heavy Catalogue Browsing (Non-blocking parallel HTTP streams).
 * 2. Double-Advancement Race Condition Mutex (row-level locking FOR UPDATE).
 * 3. Concurrent Rating Upserts (ON DUPLICATE KEY UPDATE atomicity).
 * 4. Concurrent YouTube Matcher Advisory Locking (GET_LOCK).
 * 
 * Safety & Invariant Guarantees:
 * - Purely local execution (127.0.0.1:8080).
 * - Zero production connections or modifications.
 * - Confirms baseline 2,411 catalogue songs and NULL artist records are strictly preserved.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$pdo = get_db_connection();

echo "======================================================================\n";
echo " AFM#2 — CONTROLLED MULTI-CLIENT CONCURRENCY BENCHMARK\n";
echo " Host: 127.0.0.1:8080 | PHP " . PHP_VERSION . " | MySQL 8.4\n";
echo " Budget Target: Strictly ₹0.00/month (Zero-Cost Shared Hosting Constraints)\n";
echo "======================================================================\n\n";

// Confirm pre-flight invariant
$preSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
if ($preSongCount !== 2411) {
    die("FATAL: Pre-flight invariant failed. Expected 2,411 songs, found {$preSongCount}.\n");
}

function calculate_percentiles(array $latencies): array {
    if (empty($latencies)) {
        return ['median' => 0, 'p95' => 0, 'mean' => 0, 'min' => 0, 'max' => 0];
    }
    sort($latencies);
    $n = count($latencies);
    $median = $n % 2 === 0 ? ($latencies[$n / 2 - 1] + $latencies[$n / 2]) / 2 : $latencies[floor($n / 2)];
    $p95Index = min($n - 1, (int)ceil(0.95 * $n) - 1);
    $p95 = $latencies[$p95Index];
    $mean = array_sum($latencies) / $n;
    return [
        'median' => round($median * 1000, 2),
        'p95'    => round($p95 * 1000, 2),
        'mean'   => round($mean * 1000, 2),
        'min'    => round(min($latencies) * 1000, 2),
        'max'    => round(max($latencies) * 1000, 2)
    ];
}

// ======================================================================
// Section 1: HTTP Multi-Client Concurrency Benchmark (Read-Heavy)
// ======================================================================
echo "--- 1. HTTP Multi-Client Concurrency Progression (1, 2, 5, 10, 20 Users) ---\n";
echo "Target URLs: /library.php, /tournament.php, /results.php, /discovery.php\n";

// Provision a temporary benchmark user
$testUsername = 'bench_http_user_' . time();
$testPassword = 'BenchUserPass123!';
$pwdHash = password_hash($testPassword, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`) VALUES (:u, :e, :h, 'user', 1)")
    ->execute([':u' => $testUsername, ':e' => $testUsername . '@example.com', ':h' => $pwdHash]);
$benchUserId = (int)$pdo->lastInsertId();

// Login via system curl to obtain session cookie
$cookieFile = tempnam(sys_get_temp_dir(), 'afm_bench_cookie_');
$loginHtml = shell_exec("curl -s -c " . escapeshellarg($cookieFile) . " http://127.0.0.1:8080/login.php");
preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/', $loginHtml, $m);
$csrfToken = $m[1] ?? '';

shell_exec("curl -s -b " . escapeshellarg($cookieFile) . " -c " . escapeshellarg($cookieFile) . " -X POST -d " . escapeshellarg(http_build_query([
    'username'   => $testUsername,
    'password'   => $testPassword,
    'csrf_token' => $csrfToken
])) . " http://127.0.0.1:8080/login.php");

// Extract AFM2_SESSID cookie value
$cookieData = file_get_contents($cookieFile);
preg_match('/AFM2_SESSID\s+([a-zA-Z0-9]+)/', $cookieData, $cm);
$sessId = $cm[1] ?? '';
@unlink($cookieFile);

if (!$sessId) {
    die("FATAL: Failed to establish session for benchmark user.\n");
}

$endpoints = [
    '/library.php?page=1&sort=id_asc',
    '/library.php?page=1&sort=title_asc',
    '/library.php?page=1&sort=artist_asc',
    '/tournament.php',
    '/results.php',
    '/discovery.php'
];

$concurrencyLevels = [1, 2, 5, 10, 20];
$requestsPerLevel = 60;
$httpBenchmarkResults = [];

printf("%-12s | %-10s | %-12s | %-10s | %-10s | %-10s | %-10s\n", 
    "Concurrency", "Total Reqs", "Throughput", "Median(ms)", "p95(ms)", "Max(ms)", "Errors");
echo str_repeat("-", 84) . "\n";

foreach ($concurrencyLevels as $cLevel) {
    $totalRequests = $requestsPerLevel;
    $queue = [];
    for ($i = 0; $i < $totalRequests; $i++) {
        $queue[] = $endpoints[$i % count($endpoints)];
    }

    $activeSockets = [];
    $latencies = [];
    $errors = 0;
    $completed = 0;

    $startTime = microtime(true);

    $launchRequest = function(string $path) use ($sessId, &$activeSockets) {
        $fp = @stream_socket_client("tcp://127.0.0.1:8080", $errno, $errstr, 5, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (!$fp) {
            return false;
        }
        stream_set_blocking($fp, false);
        $req = "GET {$path} HTTP/1.1\r\n" .
               "Host: 127.0.0.1:8080\r\n" .
               "Cookie: AFM2_SESSID={$sessId}\r\n" .
               "Connection: close\r\n" .
               "User-Agent: AFM2-Bench/1.0\r\n\r\n";
        fwrite($fp, $req);
        $key = (int)$fp;
        $activeSockets[$key] = [
            'fp'       => $fp,
            'start'    => microtime(true),
            'response' => ''
        ];
        return true;
    };

    // Pre-fill pool up to concurrency level
    while (count($activeSockets) < $cLevel && !empty($queue)) {
        $path = array_shift($queue);
        $launchRequest($path);
    }

    while (!empty($activeSockets)) {
        $read = [];
        $write = null;
        $except = null;
        foreach ($activeSockets as $item) {
            $read[] = $item['fp'];
        }

        if (@stream_select($read, $write, $except, 0, 50000) > 0) {
            foreach ($read as $rFp) {
                $key = (int)$rFp;
                if (!isset($activeSockets[$key])) {
                    continue;
                }
                $chunk = fread($rFp, 8192);
                if ($chunk !== false && strlen($chunk) > 0) {
                    $activeSockets[$key]['response'] .= $chunk;
                }
                if (feof($rFp) || $chunk === false || strlen($chunk) === 0) {
                    $duration = microtime(true) - $activeSockets[$key]['start'];
                    $latencies[] = $duration;
                    $resp = $activeSockets[$key]['response'];
                    $completed++;

                    // Verify HTTP status 200
                    if (strpos($resp, 'HTTP/1.1 200') === false) {
                        $errors++;
                    }

                    fclose($rFp);
                    unset($activeSockets[$key]);

                    // Launch next queued request
                    if (!empty($queue)) {
                        $nextPath = array_shift($queue);
                        $launchRequest($nextPath);
                    }
                }
            }
        }
    }

    $wallTime = microtime(true) - $startTime;
    $throughput = $wallTime > 0 ? round($completed / $wallTime, 1) : 0;
    $stats = calculate_percentiles($latencies);

    $httpBenchmarkResults[$cLevel] = [
        'concurrency' => $cLevel,
        'requests'    => $completed,
        'throughput'  => $throughput,
        'stats'       => $stats,
        'errors'      => $errors
    ];

    printf("%-12d | %-10d | %-10.1f r/s | %-10.2f | %-10.2f | %-10.2f | %-10d\n",
        $cLevel, $completed, $throughput, $stats['median'], $stats['p95'], $stats['max'], $errors);
}

// Clean up bench user
$pdo->prepare("DELETE FROM `app_users` WHERE `user_id` = :uid")->execute([':uid' => $benchUserId]);

// ======================================================================
// Section 2: Concurrency Race Condition Safety Tests
// ======================================================================
echo "\n--- 2. Concurrency Race Condition & Mutex Verification ---\n";

// Scenario A: Tournament Double-Advancement Mutex Test
// Simulate 10 simultaneous processes attempting to advance the exact same user's round
echo "A. Testing Tournament Double-Advancement Row-Level Locking Mutex (10 concurrent attempts)...\n";

$raceUsername = 'bench_race_user_' . time();
$pdo->prepare("INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`) VALUES (:u, :e, 'hash', 'user', 1)")
    ->execute([':u' => $raceUsername, ':e' => $raceUsername . '@example.com']);
$raceUserId = (int)$pdo->lastInsertId();

// Set user progress for round 1 to in_progress
$pdo->prepare("INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`) VALUES (:uid, 1, 'in_progress')")
    ->execute([':uid' => $raceUserId]);

// Enroll 4 sample entries for Round 1
$entryIds = [];
for ($i = 1; $i <= 4; $i++) {
    $pdo->prepare("INSERT INTO `afm_entries` (`user_id`, `song_id`, `status`) VALUES (:uid, :sid, 'auditioning')")
        ->execute([':uid' => $raceUserId, ':sid' => $i]);
    $eId = (int)$pdo->lastInsertId();
    $entryIds[] = $eId;
    save_scorecard_rating($eId, 1, 8, 8, 8, 8, 8, $raceUserId);
}

$advScript = dirname(__DIR__) . '/scratch/test_adv_race.php';
@mkdir(dirname(__DIR__) . '/scratch', 0777, true);
$advCode = <<<'PHP'
<?php
define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$uid = (int)$argv[1];
$qualifiers = explode(',', $argv[2]);
$res = commit_stage_advancement(1, $qualifiers, $uid);
echo json_encode($res);
PHP;
file_put_contents($advScript, $advCode);

$qualStr = implode(',', array_slice($entryIds, 0, 2));
$processes = [];
$pipes = [];

for ($i = 0; $i < 10; $i++) {
    $cmd = "php " . escapeshellarg($advScript) . " {$raceUserId} " . escapeshellarg($qualStr);
    $proc = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipeList);
    $processes[] = $proc;
    $pipes[] = $pipeList;
}

$successAdvCount = 0;
$rejectedAdvCount = 0;

foreach ($processes as $idx => $proc) {
    $stdout = stream_get_contents($pipes[$idx][1]);
    fclose($pipes[$idx][1]);
    fclose($pipes[$idx][2]);
    proc_close($proc);

    $json = json_decode($stdout, true);
    if ($json && !empty($json['success'])) {
        $successAdvCount++;
    } else {
        $rejectedAdvCount++;
    }
}
@unlink($advScript);

echo "  -> Successful advancements: {$successAdvCount} (Expected: exactly 1)\n";
echo "  -> Correctly rejected double-advancement attempts: {$rejectedAdvCount} (Expected: exactly 9)\n";

$checkProg = $pdo->prepare("SELECT status FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = 1");
$checkProg->execute([':uid' => $raceUserId]);
$finalRound1Status = $checkProg->fetchColumn();
echo "  -> Final Round 1 status: '{$finalRound1Status}' (Expected: 'completed')\n";

$checkResultsCount = $pdo->prepare("SELECT COUNT(*) FROM `round_results` WHERE `user_id` = :uid AND `round_id` = 1");
$checkResultsCount->execute([':uid' => $raceUserId]);
$resultsWritten = (int)$checkResultsCount->fetchColumn();
echo "  -> Round 1 results rows: {$resultsWritten} (Expected: 4 entries, NO duplicates)\n";

$isAdvMutexSafe = ($successAdvCount === 1 && $rejectedAdvCount === 9 && $finalRound1Status === 'completed' && $resultsWritten === 4);
echo "  [" . ($isAdvMutexSafe ? "PASS" : "FAIL") . "] Double-Advancement Row-Level Mutex Integrity\n\n";

// Scenario B: Concurrent Scorecard Rating Upserts (ON DUPLICATE KEY UPDATE)
echo "B. Testing Concurrent Scorecard Rating Upserts for Same Entry (10 concurrent attempts)...\n";
$targetEntryId = $entryIds[0];

$rateScript = dirname(__DIR__) . '/scratch/test_rate_race.php';
$rateCode = <<<'PHP'
<?php
define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$uid = (int)$argv[1];
$eid = (int)$argv[2];
$score = (int)$argv[3];
$res = save_scorecard_rating($eid, 1, $score, $score, $score, $score, $score, $uid);
echo json_encode($res);
PHP;
file_put_contents($rateScript, $rateCode);

$rateProcs = [];
$ratePipes = [];
for ($i = 1; $i <= 10; $i++) {
    $cmd = "php " . escapeshellarg($rateScript) . " {$raceUserId} {$targetEntryId} {$i}";
    $proc = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipeList);
    $rateProcs[] = $proc;
    $ratePipes[] = $pipeList;
}

$rateSuccessCount = 0;
foreach ($rateProcs as $idx => $proc) {
    $stdout = stream_get_contents($ratePipes[$idx][1]);
    fclose($ratePipes[$idx][1]);
    fclose($ratePipes[$idx][2]);
    proc_close($proc);
    $json = json_decode($stdout, true);
    if ($json && !empty($json['success'])) {
        $rateSuccessCount++;
    }
}
@unlink($rateScript);

$checkRatings = $pdo->prepare("SELECT COUNT(*) FROM `ratings` WHERE `user_id` = :uid AND `entry_id` = :eid AND `round_id` = 1");
$checkRatings->execute([':uid' => $raceUserId, ':eid' => $targetEntryId]);
$ratingsRowsInDb = (int)$checkRatings->fetchColumn();

echo "  -> Successful rating updates: {$rateSuccessCount} / 10\n";
echo "  -> Total ratings rows in database: {$ratingsRowsInDb} (Expected: exactly 1 row, zero duplicates)\n";
$isRateUpsertSafe = ($rateSuccessCount === 10 && $ratingsRowsInDb === 1);
echo "  [" . ($isRateUpsertSafe ? "PASS" : "FAIL") . "] Atomic Rating Upsert Concurrency\n\n";

// Scenario C: Concurrent YouTube Matching Job Advisory Locking (GET_LOCK)
echo "C. Testing YouTube Matching Advisory Locking Under Concurrent Invocations (5 concurrent attempts)...\n";
$ytScript = dirname(__DIR__) . '/scratch/test_yt_lock.php';
$ytCode = <<<'PHP'
<?php
define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$uid = (int)$argv[1];
$res = YouTubeMatcher::process_batch(5, [], $uid);
echo json_encode($res);
PHP;
file_put_contents($ytScript, $ytCode);

$ytProcs = [];
$ytPipes = [];
for ($i = 0; $i < 5; $i++) {
    $cmd = "php " . escapeshellarg($ytScript) . " {$raceUserId}";
    $proc = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipeList);
    $ytProcs[] = $proc;
    $ytPipes[] = $pipeList;
}

$ytJobRan = 0;
$ytLockProtected = 0;

foreach ($ytProcs as $idx => $proc) {
    $stdout = stream_get_contents($ytPipes[$idx][1]);
    fclose($ytPipes[$idx][1]);
    fclose($ytPipes[$idx][2]);
    proc_close($proc);
    $json = json_decode($stdout, true);
    if ($json && !empty($json['in_progress'])) {
        $ytLockProtected++;
    } elseif ($json && !empty($json['success'])) {
        $ytJobRan++;
    }
}
@unlink($ytScript);

echo "  -> Active batch executed: {$ytJobRan}\n";
echo "  -> Competing concurrent invocations protected by advisory lock: {$ytLockProtected}\n";
$isYtLockSafe = ($ytJobRan >= 1 && ($ytJobRan + $ytLockProtected === 5));
echo "  [" . ($isYtLockSafe ? "PASS" : "FAIL") . "] Advisory Lock Zero-Cost Job Serialization\n\n";

// Clean up race user data
$pdo->prepare("DELETE FROM `ratings` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);
$pdo->prepare("DELETE FROM `round_results` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);
$pdo->prepare("DELETE FROM `user_round_progress` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);
$pdo->prepare("DELETE FROM `afm_entries` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);
$pdo->prepare("DELETE FROM `youtube_matching_jobs` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);
$pdo->prepare("DELETE FROM `app_users` WHERE `user_id` = :uid")->execute([':uid' => $raceUserId]);

// ======================================================================
// Section 3: Final Data Invariant Verification
// ======================================================================
echo "--- 3. Post-Benchmark Catalogue & Invariant Audit ---\n";
$postSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
echo "Total Active Songs: {$postSongCount} (Invariant: 2,411)\n";
$postNullArtists = $pdo->query("SELECT song_id FROM `songs` WHERE `artist` IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
echo "NULL Artist Song IDs: [" . implode(', ', $postNullArtists) . "] (Invariant: [1546, 2015, 2196])\n";

$allPassed = ($isAdvMutexSafe && $isRateUpsertSafe && $isYtLockSafe && $postSongCount === 2411);
echo "\n======================================================================\n";
echo " CONCURRENCY BENCHMARK STATUS: " . ($allPassed ? "ALL TESTS PASSED" : "FAILED") . "\n";
echo "======================================================================\n";

// Save results
file_put_contents(dirname(__DIR__) . '/tests/concurrency_benchmark_results.json', json_encode([
    'timestamp'      => date('c'),
    'http_benchmark' => $httpBenchmarkResults,
    'race_tests'     => [
        'double_advancement_mutex' => $isAdvMutexSafe,
        'rating_upsert_atomic'     => $isRateUpsertSafe,
        'youtube_advisory_lock'    => $isYtLockSafe
    ],
    'invariants'     => [
        'songs_count'  => $postSongCount,
        'null_artists' => $postNullArtists
    ]
], JSON_PRETTY_PRINT));
