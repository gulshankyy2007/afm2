<?php
/**
 * AFM#2 - Phase 3A: Performance & Resilience Baseline Benchmark
 *
 * Measures HTTP response times (p50, p95), SQL query execution time, memory usage,
 * payload size, error rates, and separate profiling of recordings candidate aggregation.
 *
 * Usage: php tests/benchmark_phase3a_resilience.php
 */

declare(strict_types=1);

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

echo "======================================================================\n";
echo " AFM#2 — PHASE 3A PERFORMANCE & RESILIENCE BASELINE BENCHMARK\n";
echo " Host: Isolated Local Environment (127.0.0.1:8080)\n";
echo " Budget Constraint: Strictly ₹0.00/month (Shared Hosting Target)\n";
echo "======================================================================\n\n";

// Helper for HTTP requests
function benchmark_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array {
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = is_int($k) ? $v : "{$k}: {$v}";
    }

    $opts = [
        'http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headerLines) . "\r\n",
            'content'         => $body ?? '',
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 10
        ]
    ];

    $ctx = stream_context_create($opts);
    $t0 = microtime(true);
    $responseBody = @file_get_contents($url, false, $ctx);
    $durationMs = (microtime(true) - $t0) * 1000.0;

    $responseHeaders = $http_response_header ?? [];
    $statusCode = 0;
    if (!empty($responseHeaders) && preg_match('#HTTP/\S+\s+(\d{3})#i', $responseHeaders[0], $m)) {
        $statusCode = (int)$m[1];
    }

    $setCookies = [];
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'set-cookie:') === 0) {
            $setCookies[] = trim(substr($h, 11));
        }
    }

    return [
        'status'      => $statusCode,
        'duration_ms' => $durationMs,
        'size_bytes'  => strlen($responseBody ?: ''),
        'cookies'     => $setCookies,
        'body'        => $responseBody ?: ''
    ];
}

// 1. Establish authenticated session
$loginRes = benchmark_http("http://127.0.0.1:8080/login.php", 'GET');
preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $loginRes['body'], $mToken);
$csrfToken = $mToken[1] ?? '';
$sessionCookie = '';
foreach ($loginRes['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc)) {
        $sessionCookie = $mc[1];
        break;
    }
}

$postBody = http_build_query([
    'csrf_token' => $csrfToken,
    'username'   => 'adminuser',
    'password'   => 'SecurePassword2026!'
]);

$authRes = benchmark_http(
    "http://127.0.0.1:8080/login.php",
    'POST',
    $postBody,
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $sessionCookie
    ]
);

foreach ($authRes['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc)) {
        $sessionCookie = $mc[1];
        break;
    }
}

$reqHeaders = ['Cookie' => $sessionCookie];

// 2. Measure core routes across iterations
$routes = [
    'Dashboard (index.php)'              => 'http://127.0.0.1:8080/index.php',
    'Library (library.php?page=1&per=50)'=> 'http://127.0.0.1:8080/library.php?page=1&per_page=50',
    'Recordings (recordings.php?page=1)' => 'http://127.0.0.1:8080/recordings.php?page=1&per_page=25',
    'Auditions (auditions.php)'          => 'http://127.0.0.1:8080/auditions.php',
    'Discovery (discovery.php)'          => 'http://127.0.0.1:8080/discovery.php',
    'Tournament (tournament.php)'        => 'http://127.0.0.1:8080/tournament.php',
    'Results (results.php)'              => 'http://127.0.0.1:8080/results.php',
    'API get_preferences'                => 'http://127.0.0.1:8080/api.php?action=get_preferences',
    'Hostile Input: SQLi in search'      => 'http://127.0.0.1:8080/library.php?q=%27+OR+1%3D1+--',
    'Hostile Input: Malicious sort'      => 'http://127.0.0.1:8080/library.php?sort=malicious_injection'
];

$iterations = 7;
$results = [];

echo "Running {$iterations} benchmark iterations per endpoint...\n\n";

foreach ($routes as $label => $url) {
    $durations = [];
    $statuses = [];
    $sizes = [];

    for ($i = 0; $i < $iterations; $i++) {
        $res = benchmark_http($url, 'GET', null, $reqHeaders);
        $durations[] = $res['duration_ms'];
        $statuses[] = $res['status'];
        $sizes[] = $res['size_bytes'];
        usleep(15000); // 15ms pause between requests to model normal user browsing
    }

    sort($durations);
    $p50Index = (int)floor(count($durations) * 0.50);
    $p95Index = (int)min(count($durations) - 1, floor(count($durations) * 0.95));

    $p50 = $durations[$p50Index];
    $p95 = $durations[$p95Index];
    $avg = array_sum($durations) / count($durations);
    $min = $durations[0];
    $max = $durations[count($durations) - 1];
    $sizeAvg = (int)(array_sum($sizes) / count($sizes));
    $errorCount = count(array_filter($statuses, fn($s) => $s >= 400));

    $results[$label] = [
        'p50'   => $p50,
        'p95'   => $p95,
        'avg'   => $avg,
        'min'   => $min,
        'max'   => $max,
        'size'  => $sizeAvg,
        'err'   => $errorCount
    ];

    printf("  %-38s | p50: %6.1f ms | p95: %6.1f ms | Avg: %6.1f ms | Payload: %6.1f KB | Errors: %d/%d\n",
        $label, $p50, $p95, $avg, $sizeAvg / 1024, $errorCount, $iterations);
}

// ---------------------------------------------------------------------
// 3. Isolated Profiling of Recordings Candidate Aggregation Query
// ---------------------------------------------------------------------
echo "\n----------------------------------------------------------------------\n";
echo " ISOLATED PROFILING: Recordings Candidate Aggregation Query\n";
echo "----------------------------------------------------------------------\n";

$pdo = get_db_connection();

// Profile decoupled page query
$pageSql = "
    SELECT 
        s.`song_id`, s.`title`, s.`artist`, sm.`media_id`, sm.`video_id`, sm.`user_url`,
        sm.`version_type`, sm.`match_method`, sm.`match_score`, sm.`confidence` AS media_confidence,
        sm.`verification_status`, sm.`match_decision_notes`, sm.`is_manual`, sm.`embed_status`,
        sa.`thumbnail_url` AS artwork_thumbnail_url, sa.`image_url` AS artwork_image_url
    FROM `songs` s
    LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
    LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
    WHERE s.`is_consolidated` = 0
    ORDER BY s.`song_id` ASC
    LIMIT 25 OFFSET 0
";

$t0 = microtime(true);
$pageStmt = $pdo->query($pageSql);
$pageItems = $pageStmt->fetchAll();
$pageQueryTime = (microtime(true) - $t0) * 1000.0;
$songIds = array_map('intval', array_column($pageItems, 'song_id'));

echo "Page Listing Query (25 rows):  " . sprintf("%.2f ms", $pageQueryTime) . "\n";

// Profile Candidate Hydration Query
$placeholders = implode(',', array_fill(0, count($songIds), '?'));
$winSql = "
    SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url, cand_count
    FROM (
        SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url,
               ROW_NUMBER() OVER (PARTITION BY song_id ORDER BY score_total DESC, video_id ASC) AS rn,
               COUNT(*) OVER (PARTITION BY song_id) AS cand_count
        FROM song_youtube_candidates
        WHERE song_id IN ({$placeholders})
    ) ranked
    WHERE rn = 1
";

$memStart = memory_get_usage(true);
$t0 = microtime(true);
$winStmt = $pdo->prepare($winSql);
$winStmt->execute($songIds);
$candRows = $winStmt->fetchAll();
$candQueryTime = (microtime(true) - $t0) * 1000.0;
$memEnd = memory_get_usage(true);

echo "Candidate Window Query:         " . sprintf("%.2f ms", $candQueryTime) . " (Hydrated " . count($candRows) . " candidate rows)\n";
echo "Total Query Time for Page 1:    " . sprintf("%.2f ms", $pageQueryTime + $candQueryTime) . "\n";
echo "PHP Memory Consumption:         " . sprintf("%.2f KB", ($memEnd - $memStart) / 1024) . "\n";

// ---------------------------------------------------------------------
// 4. Bounded Concurrency & Recovery Check
// ---------------------------------------------------------------------
echo "\n----------------------------------------------------------------------\n";
echo " BOUNDED CONCURRENCY & RECOVERY TEST\n";
echo "----------------------------------------------------------------------\n";

$concurrencyCount = 10;
$t0 = microtime(true);
$burstDurations = [];
for ($i = 0; $i < $concurrencyCount; $i++) {
    $res = benchmark_http("http://127.0.0.1:8080/library.php?page=1&per_page=50", 'GET', null, $reqHeaders);
    $burstDurations[] = $res['duration_ms'];
}
$burstTotal = (microtime(true) - $t0) * 1000.0;
$burstAvg = array_sum($burstDurations) / count($burstDurations);

echo "Rapid sequential burst ({$concurrencyCount} requests): " . sprintf("%.2f ms total, %.2f ms avg per req", $burstTotal, $burstAvg) . "\n";

// Check immediate recovery
$recoveryRes = benchmark_http("http://127.0.0.1:8080/index.php", 'GET', null, $reqHeaders);
echo "Post-burst recovery latency:    " . sprintf("%.2f ms (HTTP %d)", $recoveryRes['duration_ms'], $recoveryRes['status']) . "\n";
echo "System Recovery Status:         " . ($recoveryRes['status'] === 200 ? "CLEAN (100% Responsive)" : "DEGRADED") . "\n";
