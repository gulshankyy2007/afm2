<?php
/**
 * AFM#2 — Phase 3B, Batch D Test Suite: State-Changing Request Hardening
 *
 * Verifies:
 * FINDING-02: Secure logout via authenticated POST with CSRF protection;
 *             rejection of plain GET requests without mutating session state;
 *             session invalidation and cookie clearing.
 * FINDING-07: YouTube candidate discovery hardened to state-changing POST with CSRF;
 *             youtube_get_candidates is strictly read-only (no scraping or DB writes on GET);
 *             safeguards against duplicate discovery and rejection of missing/invalid CSRF tokens;
 *             compatibility with frontend contracts and preservation of read-only GET endpoints.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

$pdo = get_db_connection();

echo "======================================================================\n";
echo " AFM#2 — BATCH D: STATE-CHANGING REQUEST HARDENING TEST SUITE\n";
echo " Targets: FINDING-02 (Secure Logout) & FINDING-07 (YouTube Discovery)\n";
echo "======================================================================\n\n";

$testsTotal = 0;
$testsPassed = 0;

function assert_test(string $name, bool $passed, string $details = ''): void {
    global $testsTotal, $testsPassed;
    $testsTotal++;
    if ($passed) {
        $testsPassed++;
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}" . ($details ? " — {$details}" : "") . "\n";
    }
}

/**
 * Pure PHP stream-based HTTP request helper
 */
function test_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array {
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
            'timeout'         => 8
        ]
    ];

    $ctx = stream_context_create($opts);
    $responseBody = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];

    $statusCode = 0;
    $parsedHeaders = [];
    $cookies = [];

    foreach ($responseHeaders as $h) {
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#i', $h, $m)) {
            $statusCode = (int)$m[1];
        } elseif (strpos($h, ':') !== false) {
            [$name, $val] = explode(':', $h, 2);
            $name = strtolower(trim($name));
            $val = trim($val);
            if ($name === 'set-cookie') {
                $cookies[] = $val;
            }
            $parsedHeaders[$name] = $val;
        }
    }

    return [
        'status'  => $statusCode,
        'headers' => $parsedHeaders,
        'cookies' => $cookies,
        'body'    => (string)$responseBody
    ];
}

/**
 * Helper to log in as adminuser and retrieve session cookie & CSRF token
 */
function login_admin_helper(): array {
    $pdo = get_db_connection();
    $testUser = 'adminuser';
    $testPass = 'SecurePassword2026!';
    $hash = password_hash($testPass, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT `user_id` FROM `app_users` WHERE `username` = :u LIMIT 1");
    $stmt->execute([':u' => $testUser]);
    $existing = $stmt->fetch();
    if ($existing) {
        $pdo->prepare("UPDATE `app_users` SET `password_hash` = :hash WHERE `user_id` = :id")
            ->execute([':hash' => $hash, ':id' => $existing['user_id']]);
    } else {
        $pdo->prepare("INSERT INTO `app_users` (`username`, `password_hash`, `created_at`, `last_login`) VALUES (:u, :hash, NOW(), NOW())")
            ->execute([':u' => $testUser, ':hash' => $hash]);
    }

    // Step 1: GET /login.php to get initial session cookie & login CSRF
    $initRes = test_http("http://127.0.0.1:8080/login.php", 'GET');
    $initCookie = '';
    foreach ($initRes['cookies'] as $c) {
        if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $m)) {
            $initCookie = $m[1];
            break;
        }
    }

    preg_match('/name="csrf_token" value="([^"]+)"/', $initRes['body'], $csrfM);
    $loginCsrf = $csrfM[1] ?? '';

    // Step 2: POST /login.php with credentials
    $loginRes = test_http(
        "http://127.0.0.1:8080/login.php",
        'POST',
        http_build_query([
            'csrf_token' => $loginCsrf,
            'username'   => $testUser,
            'password'   => $testPass
        ]),
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Cookie'       => $initCookie
        ]
    );

    $authedCookie = $initCookie;
    foreach ($loginRes['cookies'] as $c) {
        if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $m)) {
            $authedCookie = $m[1];
            break;
        }
    }

    // Step 3: GET /index.php to fetch the authenticated user's session CSRF token and header HTML
    $indexRes = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $authedCookie]);
    preg_match('/name="csrf-token" content="([^"]+)"/', $indexRes['body'], $csrfM2);
    $activeCsrf = $csrfM2[1] ?? '';

    return [
        'cookie'     => $authedCookie,
        'csrf_token' => $activeCsrf,
        'index_body' => $indexRes['body']
    ];
}

// =====================================================================
// PRE-FLIGHT INVARIANT CHECK
// =====================================================================
echo "--- 0. Pre-Flight Catalogue Invariants ---\n";
$preCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$preNullArtists = $pdo->query("SELECT `song_id` FROM `songs` WHERE `artist` IS NULL ORDER BY `song_id`")->fetchAll(PDO::FETCH_COLUMN);
assert_test("Pre-test Catalogue Baseline Invariant: 2,411 songs", $preCount === 2411, "Count: {$preCount}");
assert_test("Pre-test Missing Artist Invariant: [1546, 2015, 2196]", $preNullArtists === [1546, 2015, 2196], "IDs: " . json_encode($preNullArtists));

// =====================================================================
// SECTION 1: FINDING-02 — SECURE LOGOUT (TESTS 1 to 8)
// =====================================================================
echo "\n--- 1. FINDING-02: Secure Logout Hardening ---\n";

// Test 1: Authenticated POST with valid CSRF token succeeds.
$session1 = login_admin_helper();
$logoutPostRes = test_http(
    "http://127.0.0.1:8080/logout.php",
    'POST',
    http_build_query(['csrf_token' => $session1['csrf_token']]),
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $session1['cookie']
    ]
);
assert_test(
    "1. Authenticated POST with valid CSRF token succeeds (HTTP 302 -> login.php?logged_out=1)",
    $logoutPostRes['status'] === 302 && strpos($logoutPostRes['headers']['location'] ?? '', 'login.php?logged_out=1') !== false,
    "Status: {$logoutPostRes['status']}, Location: " . ($logoutPostRes['headers']['location'] ?? 'none')
);

// Test 2: GET logout does not mutate the session.
$session2 = login_admin_helper();
$logoutGetRes = test_http(
    "http://127.0.0.1:8080/logout.php",
    'GET',
    null,
    ['Cookie' => $session2['cookie']]
);
$session2AfterGet = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $session2['cookie']]);
assert_test(
    "2. GET logout is rejected (HTTP 405 Method Not Allowed with Allow: POST) and does not mutate session",
    $logoutGetRes['status'] === 405 &&
    stripos($logoutGetRes['headers']['allow'] ?? '', 'POST') !== false &&
    $session2AfterGet['status'] === 200,
    "GET Status: {$logoutGetRes['status']}, Post-GET Index Status: {$session2AfterGet['status']}"
);

// Test 3: POST without a CSRF token is rejected.
$logoutNoCsrfRes = test_http(
    "http://127.0.0.1:8080/logout.php",
    'POST',
    '',
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $session2['cookie']
    ]
);
$session2AfterNoCsrf = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $session2['cookie']]);
assert_test(
    "3. POST without a CSRF token is rejected (HTTP 403 Forbidden) and session remains active",
    $logoutNoCsrfRes['status'] === 403 && $session2AfterNoCsrf['status'] === 200,
    "Status: {$logoutNoCsrfRes['status']}, Post-Check Index Status: {$session2AfterNoCsrf['status']}"
);

// Test 4: POST with an invalid token is rejected.
$logoutBadCsrfRes = test_http(
    "http://127.0.0.1:8080/logout.php",
    'POST',
    http_build_query(['csrf_token' => 'invalid_forged_csrf_token_999999']),
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $session2['cookie']
    ]
);
$session2AfterBadCsrf = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $session2['cookie']]);
assert_test(
    "4. POST with an invalid token is rejected (HTTP 403 Forbidden) and session remains active",
    $logoutBadCsrfRes['status'] === 403 && $session2AfterBadCsrf['status'] === 200,
    "Status: {$logoutBadCsrfRes['status']}, Post-Check Index Status: {$session2AfterBadCsrf['status']}"
);

// Test 5: Unauthenticated logout requests behave safely.
$anonGetRes = test_http("http://127.0.0.1:8080/logout.php", 'GET');
$anonPostNoTokenRes = test_http("http://127.0.0.1:8080/logout.php", 'POST');
$anonPostWithTokenRes = test_http(
    "http://127.0.0.1:8080/logout.php",
    'POST',
    http_build_query(['csrf_token' => 'some_token']),
    ['Content-Type' => 'application/x-www-form-urlencoded']
);
$activeSessionUntouched = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $session2['cookie']]);
assert_test(
    "5. Unauthenticated logout requests behave safely without server errors or cross-session impact",
    $anonGetRes['status'] === 405 &&
    $anonPostNoTokenRes['status'] === 403 &&
    $anonPostWithTokenRes['status'] === 403 &&
    $activeSessionUntouched['status'] === 200,
    "Anon GET: {$anonGetRes['status']}, Anon POST No Token: {$anonPostNoTokenRes['status']}, User Session: {$activeSessionUntouched['status']}"
);

// Test 6: Session identity is invalidated after successful logout.
$postLogoutAccessRes = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $session1['cookie']]);
$postLogoutApiRes = test_http("http://127.0.0.1:8080/api.php?action=get_preferences", 'GET', null, ['Cookie' => $session1['cookie']]);
assert_test(
    "6. Session identity is invalidated after successful logout (index redirects to login.php, API returns 401)",
    $postLogoutAccessRes['status'] === 302 &&
    strpos($postLogoutAccessRes['headers']['location'] ?? '', 'login.php') !== false &&
    $postLogoutApiRes['status'] === 401,
    "Index Status: {$postLogoutAccessRes['status']}, API Status: {$postLogoutApiRes['status']}"
);

// Test 7: Session-cookie clearing preserves the correct cookie attributes.
$logoutCookieHeader = '';
foreach ($logoutPostRes['cookies'] as $c) {
    if (stripos($c, 'AFM2_SESSID=') !== false) {
        $logoutCookieHeader = $c;
        break;
    }
}
$cookieExpired = !empty($logoutCookieHeader) && (stripos($logoutCookieHeader, 'Max-Age=0') !== false || stripos($logoutCookieHeader, '1970') !== false || stripos($logoutCookieHeader, 'deleted') !== false);
$cookieHasPath = stripos($logoutCookieHeader, 'path=/') !== false;
$cookieHasSameSite = stripos($logoutCookieHeader, 'SameSite=Lax') !== false;
$cookieHasHttpOnly = stripos($logoutCookieHeader, 'HttpOnly') !== false;
assert_test(
    "7. Session-cookie clearing preserves correct cookie attributes (Expired, Path=/, SameSite=Lax, HttpOnly)",
    $cookieExpired && $cookieHasPath && $cookieHasSameSite && $cookieHasHttpOnly,
    "Set-Cookie Header: {$logoutCookieHeader}"
);

// Test 8: Existing logout UI uses the secure flow.
$uiHasNoGetLogout = (stripos($session2['index_body'], 'href="logout.php"') === false);
$uiHasPostForm = (preg_match('/<form[^>]+action="logout.php"[^>]+method="POST"/i', $session2['index_body']) === 1);
$uiHasCsrfInput = (preg_match('/<form[^>]+action="logout.php"[^>]*>.*?<input[^>]+name="csrf_token"[^>]+value="[^"]+".*?<\/form>/is', $session2['index_body']) === 1);
$uiHasLogoutBtn = (preg_match('/<button[^>]*\bid="btnLogout"[^>]*>Sign Out<\/button>/i', $session2['index_body']) === 1 &&
                   preg_match('/<button[^>]*\bclass="[^"]*btn-logout[^"]*"[^>]*>/i', $session2['index_body']) === 1);
assert_test(
    "8. Existing logout UI in header uses secure POST form with CSRF token and preserves #btnLogout control",
    $uiHasNoGetLogout && $uiHasPostForm && $uiHasCsrfInput && $uiHasLogoutBtn,
    "No GET link: " . ($uiHasNoGetLogout ? 'YES' : 'NO') . ", POST form: " . ($uiHasPostForm ? 'YES' : 'NO') . ", CSRF input: " . ($uiHasCsrfInput ? 'YES' : 'NO') . ", Button: " . ($uiHasLogoutBtn ? 'YES' : 'NO')
);

// =====================================================================
// SECTION 2: FINDING-07 — YOUTUBE CANDIDATE DISCOVERY (TESTS 9 to 15)
// =====================================================================
echo "\n--- 2. FINDING-07: YouTube Candidate Discovery Hardening ---\n";

$testSongId = 1; // Existing catalogue track
// Ensure track 1 has clean test candidate state
$origCandidates = $pdo->prepare("SELECT * FROM `song_youtube_candidates` WHERE `song_id` = :id");
$origCandidates->execute([':id' => $testSongId]);
$backupCandidates = $origCandidates->fetchAll(PDO::FETCH_ASSOC);

$pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` = :id")->execute([':id' => $testSongId]);
$candCountClean = (int)$pdo->query("SELECT COUNT(*) FROM `song_youtube_candidates` WHERE `song_id` = {$testSongId}")->fetchColumn();

// Test 9: GET discovery requests cannot trigger scraping or writes.
$activeSession = login_admin_helper();
$getDiscoveryRes = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_get_candidates&song_id={$testSongId}",
    'GET',
    null,
    ['Cookie' => $activeSession['cookie']]
);
$candCountAfterGet = (int)$pdo->query("SELECT COUNT(*) FROM `song_youtube_candidates` WHERE `song_id` = {$testSongId}")->fetchColumn();
$getData = json_decode($getDiscoveryRes['body'], true);

assert_test(
    "9. GET discovery requests are read-only and cannot trigger scraping or DB writes",
    $getDiscoveryRes['status'] === 200 &&
    is_array($getData) &&
    ($getData['success'] ?? false) === true &&
    isset($getData['candidates']) &&
    is_array($getData['candidates']) &&
    empty($getData['candidates']) &&
    $candCountClean === 0 &&
    $candCountAfterGet === 0,
    "HTTP Status: {$getDiscoveryRes['status']}, DB Count Clean: {$candCountClean}, DB Count Post-GET: {$candCountAfterGet}"
);

// Test 10: POST without a CSRF token is rejected.
$postNoCsrfRes = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_discover_candidates",
    'POST',
    json_encode(['song_id' => $testSongId, 'force_refresh' => true]),
    [
        'Content-Type' => 'application/json',
        'Cookie'       => $activeSession['cookie']
    ]
);
$candCountAfterNoCsrf = (int)$pdo->query("SELECT COUNT(*) FROM `song_youtube_candidates` WHERE `song_id` = {$testSongId}")->fetchColumn();
$postNoCsrfData = json_decode($postNoCsrfRes['body'], true);
assert_test(
    "10. POST discovery without a CSRF token is rejected (HTTP 403 Forbidden) without DB writes",
    $postNoCsrfRes['status'] === 403 &&
    is_array($postNoCsrfData) &&
    ($postNoCsrfData['success'] ?? true) === false &&
    $candCountAfterNoCsrf === 0,
    "Status: {$postNoCsrfRes['status']}, DB Count: {$candCountAfterNoCsrf}"
);

// Test 11: POST with an invalid token is rejected.
$postBadCsrfRes = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_discover_candidates",
    'POST',
    json_encode([
        'song_id'      => $testSongId,
        'force_refresh' => true,
        'csrf_token'   => 'forged_csrf_token_xyz987'
    ]),
    [
        'Content-Type' => 'application/json',
        'Cookie'       => $activeSession['cookie'],
        'X-CSRF-Token' => 'forged_csrf_token_xyz987'
    ]
);
$candCountAfterBadCsrf = (int)$pdo->query("SELECT COUNT(*) FROM `song_youtube_candidates` WHERE `song_id` = {$testSongId}")->fetchColumn();
$postBadCsrfData = json_decode($postBadCsrfRes['body'], true);
assert_test(
    "11. POST discovery with an invalid CSRF token is rejected (HTTP 403 Forbidden) without DB writes",
    $postBadCsrfRes['status'] === 403 &&
    is_array($postBadCsrfData) &&
    ($postBadCsrfData['success'] ?? true) === false &&
    $candCountAfterBadCsrf === 0,
    "Status: {$postBadCsrfRes['status']}, DB Count: {$candCountAfterBadCsrf}"
);

// Test 12: Authenticated POST with a valid token reaches the intended discovery workflow.
$postValidCsrfRes = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_discover_candidates",
    'POST',
    json_encode([
        'song_id'       => $testSongId,
        'force_refresh' => false,
        'csrf_token'    => $activeSession['csrf_token']
    ]),
    [
        'Content-Type' => 'application/json',
        'Cookie'       => $activeSession['cookie'],
        'X-CSRF-Token' => $activeSession['csrf_token']
    ]
);
$postValidData = json_decode($postValidCsrfRes['body'], true);
assert_test(
    "12. Authenticated POST with a valid token reaches the discovery workflow (HTTP 200, success=true)",
    $postValidCsrfRes['status'] === 200 &&
    is_array($postValidData) &&
    ($postValidData['success'] ?? false) === true &&
    isset($postValidData['song_id']) &&
    (int)$postValidData['song_id'] === $testSongId,
    "Status: {$postValidCsrfRes['status']}, Body: " . substr($postValidCsrfRes['body'], 0, 120)
);

// Restore any backed up candidates for song 1
if (!empty($backupCandidates)) {
    foreach ($backupCandidates as $bc) {
        $cols = array_keys($bc);
        $fields = '`' . implode('`, `', $cols) . '`';
        $placeholders = ':' . implode(', :', $cols);
        $insertStmt = $pdo->prepare("INSERT INTO `song_youtube_candidates` ({$fields}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE `updated_at` = NOW()");
        $insertStmt->execute($bc);
    }
}

// Test 13: Response structure and error handling remain compatible with the frontend.
$resStructGet = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_get_candidates&song_id={$testSongId}",
    'GET',
    null,
    ['Cookie' => $activeSession['cookie']]
);
$structData = json_decode($resStructGet['body'], true);
$hasExpectedKeys = is_array($structData) &&
    array_key_exists('success', $structData) &&
    array_key_exists('song_id', $structData) &&
    array_key_exists('song_title', $structData) &&
    array_key_exists('song_artist', $structData) &&
    array_key_exists('candidates', $structData) &&
    array_key_exists('saved_media', $structData);

$resNotFound = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_get_candidates&song_id=99999999",
    'GET',
    null,
    ['Cookie' => $activeSession['cookie']]
);
$notFoundData = json_decode($resNotFound['body'], true);

$resInvalidId = test_http(
    "http://127.0.0.1:8080/api.php?action=youtube_get_candidates&song_id=-5",
    'GET',
    null,
    ['Cookie' => $activeSession['cookie']]
);
$invalidIdData = json_decode($resInvalidId['body'], true);

assert_test(
    "13. Response structure and error handling remain compatible with frontend contracts (Keys, 404, 400)",
    $hasExpectedKeys &&
    $resNotFound['status'] === 404 &&
    ($notFoundData['error'] ?? '') === 'Song not found in catalogue.' &&
    $resInvalidId['status'] === 400 &&
    ($invalidIdData['error'] ?? '') === 'Invalid song ID.',
    "Keys present: " . ($hasExpectedKeys ? 'YES' : 'NO') . ", 404 status: {$resNotFound['status']}, 400 status: {$resInvalidId['status']}"
);

// Test 14: Other read-only GET API actions still work.
$prefRes = test_http("http://127.0.0.1:8080/api.php?action=get_preferences", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$prefData = json_decode($prefRes['body'], true);

$jobStatusRes = test_http("http://127.0.0.1:8080/api.php?action=youtube_job_status", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$jobStatusData = json_decode($jobStatusRes['body'], true);

$findSongRes = test_http("http://127.0.0.1:8080/api.php?action=find_existing_song&title=test", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$findSongData = json_decode($findSongRes['body'], true);

$auditRes = test_http("http://127.0.0.1:8080/api.php?action=get_consolidation_audit", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$auditData = json_decode($auditRes['body'], true);

assert_test(
    "14. Other read-only GET API actions remain functional (get_preferences, youtube_job_status, find_existing_song, get_consolidation_audit)",
    $prefRes['status'] === 200 && ($prefData['success'] ?? false) === true &&
    $jobStatusRes['status'] === 200 && ($jobStatusData['success'] ?? false) === true &&
    $findSongRes['status'] === 200 && ($findSongData['success'] ?? false) === true &&
    $auditRes['status'] === 200 && ($auditData['success'] ?? false) === true,
    "Prefs: {$prefRes['status']}, JobStatus: {$jobStatusRes['status']}, FindSong: {$findSongRes['status']}, Audit: {$auditRes['status']}"
);

// Test 15: No alternate GET route triggers the same side effect.
$alt1 = test_http("http://127.0.0.1:8080/api.php?action=youtube_discover_candidates&song_id=1", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$alt2 = test_http("http://127.0.0.1:8080/api.php?action=youtube_batch_process", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$alt3 = test_http("http://127.0.0.1:8080/api.php?action=youtube_select_candidate", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$alt4 = test_http("http://127.0.0.1:8080/api.php?action=youtube_manual_save", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$alt5 = test_http("http://127.0.0.1:8080/api.php?action=youtube_job_reset", 'GET', null, ['Cookie' => $activeSession['cookie']]);
$alt6 = test_http("http://127.0.0.1:8080/api.php?action=update_discovery&song_id=1", 'GET', null, ['Cookie' => $activeSession['cookie']]);

assert_test(
    "15. No alternate GET routes allow triggering state changes (all return HTTP 405 Method Not Allowed)",
    $alt1['status'] === 405 &&
    $alt2['status'] === 405 &&
    $alt3['status'] === 405 &&
    $alt4['status'] === 405 &&
    $alt5['status'] === 405 &&
    $alt6['status'] === 405,
    "Alt1: {$alt1['status']}, Alt2: {$alt2['status']}, Alt3: {$alt3['status']}, Alt4: {$alt4['status']}, Alt5: {$alt5['status']}, Alt6: {$alt6['status']}"
);

// =====================================================================
// POST-FLIGHT INVARIANT CHECK
// =====================================================================
echo "\n--- 3. Post-Flight Catalogue Invariants ---\n";
$postCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$postNullArtists = $pdo->query("SELECT `song_id` FROM `songs` WHERE `artist` IS NULL ORDER BY `song_id`")->fetchAll(PDO::FETCH_COLUMN);

assert_test("Post-test Catalogue Preservation Invariant: strictly 2,411 songs", $postCount === 2411, "Count: {$postCount}");
assert_test("Post-test Missing Artist Invariant: strictly [1546, 2015, 2196]", $postNullArtists === [1546, 2015, 2196], "IDs: " . json_encode($postNullArtists));

// =====================================================================
// SUMMARY
// =====================================================================
echo "\n======================================================================\n";
echo " BATCH D TEST EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$testsTotal}\n";
echo "Passed:               {$testsPassed}\n";
echo "Failed:               " . ($testsTotal - $testsPassed) . "\n";

if ($testsTotal === $testsPassed) {
    echo "\n🎉 ALL BATCH D TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n\n";
    exit(0);
} else {
    echo "\n❌ SOME BATCH D TESTS FAILED!\n\n";
    exit(1);
}
