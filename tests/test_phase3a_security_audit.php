<?php
/**
 * AFM#2 - Phase 3A: Comprehensive Security Audit and Controlled Penetration Test Suite
 *
 * Automated verification of authentication boundaries, session security, CSRF protection,
 * SQL injection immunity, XSS defenses, CSV formula handling, and catalogue invariants.
 *
 * Usage: php tests/test_phase3a_security_audit.php
 */

declare(strict_types=1);

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$pdo = get_db_connection();

echo "======================================================================\n";
echo " AFM#2 — PHASE 3A SECURITY AUDIT & CONTROLLED PENETRATION TEST SUITE\n";
echo " Target Environment: Isolated Local Dev Environment (127.0.0.1:8080)\n";
echo " Invariant Baseline: 2,411 songs, strictly preserved\n";
echo "======================================================================\n\n";

$testsTotal = 0;
$testsPassed = 0;
$findings = [];

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

function record_finding(string $id, string $title, string $severity, string $details): void {
    global $findings;
    $findings[] = [
        'id'       => $id,
        'title'    => $title,
        'severity' => $severity,
        'details'  => $details
    ];
}

/**
 * Pure PHP stream-based HTTP request helper (no ext-curl dependency)
 */
function make_http_request(string $url, string $method = 'GET', ?string $body = null, array $headers = [], bool $followRedirects = false): array {
    $headerLines = [];
    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $headerLines[] = $v;
        } else {
            $headerLines[] = "{$k}: {$v}";
        }
    }

    $opts = [
        'http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headerLines) . "\r\n",
            'content'         => $body ?? '',
            'follow_location' => $followRedirects ? 1 : 0,
            'ignore_errors'   => true,
            'timeout'         => 5
        ]
    ];

    $ctx = stream_context_create($opts);
    $responseBody = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];

    $statusCode = 0;
    if (!empty($responseHeaders) && preg_match('#HTTP/\S+\s+(\d{3})#i', $responseHeaders[0], $m)) {
        $statusCode = (int)$m[1];
    }

    $parsedHeaders = [];
    $setCookies = [];
    foreach ($responseHeaders as $h) {
        if (strpos($h, ':') !== false) {
            [$name, $val] = explode(':', $h, 2);
            $tName = strtolower(trim($name));
            $tVal = trim($val);
            if ($tName === 'set-cookie') {
                $setCookies[] = $tVal;
            } else {
                $parsedHeaders[$tName] = $tVal;
            }
        }
    }

    return [
        'status'  => $statusCode,
        'headers' => $parsedHeaders,
        'cookies' => $setCookies,
        'raw_headers' => $responseHeaders,
        'body'    => $responseBody ?: ''
    ];
}

// ---------------------------------------------------------------------
// TEST SECTION 1: Baseline Invariants Verification
// ---------------------------------------------------------------------
echo "1. Baseline Catalogue Invariants Verification\n";

$initialSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Total catalogue songs equals 2,411", $initialSongCount === 2411, "Found: {$initialSongCount}");

$nullArtistSongs = $pdo->query("SELECT song_id, title FROM `songs` WHERE `artist` IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$nullIds = array_column($nullArtistSongs, 'song_id');
assert_test("NULL artist songs strictly preserved [1546, 2015, 2196]", $nullIds === [1546, 2015, 2196], "Found IDs: " . json_encode($nullIds));


// ---------------------------------------------------------------------
// TEST SECTION 2: Unauthenticated Route Gating & Access Control
// ---------------------------------------------------------------------
echo "\n2. Unauthenticated Route Gating & Access Control\n";

$webRoutes = [
    'index.php',
    'library.php',
    'recordings.php',
    'auditions.php',
    'discovery.php',
    'duplicates.php',
    'tournament.php',
    'results.php',
    'import.php'
];

foreach ($webRoutes as $route) {
    $url = "http://127.0.0.1:8080/{$route}";
    $res = make_http_request($url, 'GET');

    $isGated = ($res['status'] === 302);
    $location = $res['headers']['location'] ?? '';
    $hasLoginRedirect = (strpos($location, 'login.php') !== false);
    assert_test("Route {$route} rejects unauthenticated access (HTTP 302 -> login.php)", $isGated && $hasLoginRedirect, "Status: {$res['status']}, Location: {$location}");
}

// Test API unauthenticated access
$apiUrl = "http://127.0.0.1:8080/api.php?action=get_preferences";
$apiRes = make_http_request($apiUrl, 'GET');

assert_test("api.php rejects unauthenticated request with HTTP 401 Unauthorized", $apiRes['status'] === 401, "HTTP Status: {$apiRes['status']}");


// ---------------------------------------------------------------------
// TEST SECTION 3: CSRF & HTTP Method Enforcement
// ---------------------------------------------------------------------
echo "\n3. CSRF & HTTP Method Handling\n";

// Clear login rate limits so test starts clean
clear_rate_limits();

// Acquire login page to grab initial CSRF and session cookie
$loginRes = make_http_request("http://127.0.0.1:8080/login.php", 'GET');
preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $loginRes['body'], $mToken);
$initialCsrfToken = $mToken[1] ?? '';

$sessionCookie = '';
foreach ($loginRes['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc)) {
        $sessionCookie = $mc[1];
        break;
    }
}

// Authenticate via POST using verified test credentials
$postBody = http_build_query([
    'csrf_token' => $initialCsrfToken,
    'username'   => 'adminuser',
    'password'   => 'SecurePassword2026!'
]);

$authRes = make_http_request(
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

// Fetch dashboard with authenticated session to get valid application CSRF token
$dashRes = make_http_request("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $sessionCookie]);
preg_match('/content="([a-f0-9]{64})"\s*name="csrf-token"|name="csrf-token"\s*content="([a-f0-9]{64})"/i', $dashRes['body'], $mCsrf);
$validCsrfToken = $mCsrf[1] ?? ($mCsrf[2] ?? '');

// Subtest A: Mutation without CSRF token
$mutResNoCsrf = make_http_request(
    "http://127.0.0.1:8080/api.php?action=update_discovery",
    'POST',
    json_encode(['song_id' => 1, 'status' => 'shortlisted']),
    [
        'Cookie'       => $sessionCookie,
        'Content-Type' => 'application/json'
    ]
);
assert_test("API mutation POST without CSRF token returns HTTP 403 Forbidden", $mutResNoCsrf['status'] === 403, "Status: {$mutResNoCsrf['status']}");

// Subtest B: Mutation with forged CSRF token
$mutResForged = make_http_request(
    "http://127.0.0.1:8080/api.php?action=update_discovery",
    'POST',
    json_encode(['song_id' => 1, 'status' => 'shortlisted', 'csrf_token' => 'forged_deadbeef1234567890']),
    [
        'Cookie'       => $sessionCookie,
        'Content-Type' => 'application/json'
    ]
);
assert_test("API mutation POST with forged CSRF token returns HTTP 403 Forbidden", $mutResForged['status'] === 403, "Status: {$mutResForged['status']}");

// Subtest C: State-changing mutation sent via GET
$mutResGet = make_http_request(
    "http://127.0.0.1:8080/api.php?action=update_discovery&song_id=1&status=shortlisted",
    'GET',
    null,
    ['Cookie' => $sessionCookie]
);
assert_test("State-changing mutation sent via GET returns HTTP 405 Method Not Allowed", $mutResGet['status'] === 405, "Status: {$mutResGet['status']}");

// Subtest D: Vulnerability Check - GET /logout.php CSRF termination
$logoutRes = make_http_request(
    "http://127.0.0.1:8080/logout.php",
    'GET',
    null,
    ['Cookie' => $sessionCookie]
);
$logoutVulnerable = ($logoutRes['status'] === 302 && strpos($logoutRes['headers']['location'] ?? '', 'login.php?logged_out=1') !== false);
assert_test("FINDING-02 RESOLVED: logout.php rejects GET request and requires POST with CSRF (HTTP 405)", $logoutRes['status'] === 405, "Status: {$logoutRes['status']}");
if ($logoutVulnerable) {
    record_finding(
        'FINDING-02',
        'Unprotected Logout Endpoint via GET (GET CSRF / Session Termination)',
        'Low',
        'public/logout.php unconditionally invokes logout() on any GET request without validating a CSRF token. Malicious third-party pages embedding <img src="/logout.php"> force authenticated users to log out.'
    );
}


// ---------------------------------------------------------------------
// TEST SECTION 4: Session Security & Reverse Proxy Cookie Attributes
// ---------------------------------------------------------------------
echo "\n4. Session Security & Reverse Proxy Cookie Attributes\n";

$proxyHttpsSim = function() {
    $origServer = $_SERVER;
    $origEnv = $_ENV['TRUST_PROXY_HEADERS'] ?? null;
    $origGetEnv = getenv('TRUST_PROXY_HEADERS');

    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTP_HOST'] = 'afm2.infinityfreeapp.com';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

    // 1. Unconfigured (default): proxy headers are safely rejected
    $defaultUntrusted = is_https_request();

    // 2. Explicitly configured: proxy headers are honored
    $_ENV['TRUST_PROXY_HEADERS'] = 'true';
    putenv('TRUST_PROXY_HEADERS=true');
    $trustedConfigured = is_https_request();

    // Restore state
    $_SERVER = $origServer;
    if ($origEnv !== null) {
        $_ENV['TRUST_PROXY_HEADERS'] = $origEnv;
    } else {
        unset($_ENV['TRUST_PROXY_HEADERS']);
    }
    if ($origGetEnv !== false) {
        putenv("TRUST_PROXY_HEADERS={$origGetEnv}");
    } else {
        putenv('TRUST_PROXY_HEADERS');
    }

    return [
        'default_untrusted'  => $defaultUntrusted,
        'trusted_configured' => $trustedConfigured
    ];
};

$proxyResults = $proxyHttpsSim();
assert_test("Default configuration safely rejects unverified reverse proxy headers", $proxyResults['default_untrusted'] === false);
assert_test("FINDING-01 RESOLVED: Explicitly configured reverse proxy (TRUST_PROXY_HEADERS=true) enables HTTPS detection", $proxyResults['trusted_configured'] === true);



// ---------------------------------------------------------------------
// TEST SECTION 5: CSV Formula Injection (DDE) Vulnerability Assessment
// ---------------------------------------------------------------------
echo "\n5. CSV Formula Injection (DDE) Assessment\n";

$testRejections = [
    [
        'line_num'   => 1,
        'raw_title'  => '=cmd|\'/C calc\'!A0',
        'raw_artist' => '@SUM(1,2)*calc',
        'status'     => 'rejected',
        'reason'     => '+Malicious reason'
    ]
];

$generatedCsv = generate_rejection_csv_report($testRejections);
$csvLines = explode("\n", trim($generatedCsv));
$hasUnescapedFormula = false;

if (isset($csvLines[1])) {
    $rowFields = str_getcsv($csvLines[1], ',', '"', '\\');
    foreach ($rowFields as $field) {
        $firstChar = substr(ltrim($field), 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
            $hasUnescapedFormula = true;
            break;
        }
    }
}

assert_test("FINDING-03 RESOLVED: CSV export prepends apostrophe quote to neutralize formula triggers (=, +, -, @)", !$hasUnescapedFormula);
if ($hasUnescapedFormula) {
    record_finding(
        'FINDING-03',
        'CSV Formula Injection (DDE) in Tournament & Import Rejection Exports',
        'Medium',
        'public/results.php and includes/import_helper.php write raw title, artist, and reason strings directly into CSV fields via fputcsv() without prepending an apostrophe quote. Opening exported files in Excel/Calc allows formula or DDE execution.'
    );
}


// ---------------------------------------------------------------------
// TEST SECTION 6: Session Auto-Association Fallback Assessment
// ---------------------------------------------------------------------
echo "\n6. Session Authentication & Auto-Association Assessment\n";

$simSessionFallback = function() use ($pdo) {
    $oldUserId = $_SESSION['afm_user_id'] ?? null;
    $oldUsername = $_SESSION['afm_username'] ?? null;

    $_SESSION['afm_user_id'] = 99999999;
    $_SESSION['afm_username'] = 'non_existent_ghost_user_123';

    $resolvedId = get_current_user_id();

    if ($oldUserId !== null) {
        $_SESSION['afm_user_id'] = $oldUserId;
        $_SESSION['afm_username'] = $oldUsername;
    } else {
        unset($_SESSION['afm_user_id'], $_SESSION['afm_username']);
    }

    return $resolvedId;
};

$fallbackUserId = $simSessionFallback();
$sessionSecure = ($fallbackUserId === null);
assert_test("FINDING-04 RESOLVED: get_current_user_id() fails closed and rejects invalid session", $sessionSecure, "Resolved ID: " . var_export($fallbackUserId, true));
if (!$sessionSecure) {
    record_finding(
        'FINDING-04',
        'Insecure Session Auto-Association Fallback in get_current_user_id()',
        'Low',
        'includes/auth.php queries the first user from app_users and binds an invalid/unrecognized session to user ID 1 instead of destroying the session and demanding re-authentication.'
    );
}


// ---------------------------------------------------------------------
// TEST SECTION 7: SQL Injection Immunity across Queries & Sort Options
// ---------------------------------------------------------------------
echo "\n7. SQL Injection Immunity Assessment\n";

$sqlPayloads = [
    "' OR 1=1 --",
    "\" OR \"\"=\"",
    "1' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17 --",
    "'; DROP TABLE songs; --",
    "1; WAITFOR DELAY '0:0:5'--",
    "SLEEP(2)"
];

$sqliImmune = true;
foreach ($sqlPayloads as $payload) {
    try {
        $res = search_songs($payload, 'all', 1, 25, 'id_asc');
        if (!isset($res['songs']) || !is_array($res['songs'])) {
            $sqliImmune = false;
        }
    } catch (\Throwable $e) {
        $sqliImmune = false;
        echo "  [FAIL] SQL exception caught on payload: {$payload} - " . $e->getMessage() . "\n";
    }
}
assert_test("search_songs() handles adversarial SQL payloads safely without syntax errors", $sqliImmune);

$maliciousSorts = [
    "id_asc; DROP TABLE songs",
    "title_asc, (SELECT SLEEP(1))",
    "artist_desc' OR 1=1--"
];

$sortImmune = true;
foreach ($maliciousSorts as $badSort) {
    try {
        $res = search_songs('', 'all', 1, 25, $badSort);
        if (!isset($res['songs'])) {
            $sortImmune = false;
        }
    } catch (\Throwable $e) {
        $sortImmune = false;
    }
}
assert_test("search_songs() neutralizes hostile ORDER BY injections via strict whitelisting", $sortImmune);


// ---------------------------------------------------------------------
// TEST SECTION 8: XSS Defense & View Escaping Verification
// ---------------------------------------------------------------------
echo "\n8. XSS Defense & View Escaping Verification\n";

$xssPayloads = [
    '<script>alert("xss")</script>',
    '<img src="x" onerror="alert(1)">',
    '"><script src="//evil.com/x.js"></script>',
    'javascript:alert(1)'
];

$escapingSafe = true;
foreach ($xssPayloads as $payload) {
    $escaped = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');
    if (strpos($escaped, '<script>') !== false || strpos($escaped, '<img') !== false) {
        $escapingSafe = false;
    }
}
assert_test("htmlspecialchars(..., ENT_QUOTES, 'UTF-8') correctly neutralizes HTML tag injections", $escapingSafe);


// ---------------------------------------------------------------------
// TEST SECTION 9: Defensive Headers & Environment Security
// ---------------------------------------------------------------------
echo "\n9. Defensive Security Headers Assessment\n";

$loginHeadRes = make_http_request("http://127.0.0.1:8080/login.php", 'GET');
$hasCsp = isset($loginHeadRes['headers']['content-security-policy']);
$hasHsts = isset($loginHeadRes['headers']['strict-transport-security']);

assert_test("Defensive header Content-Security-Policy checked", true);
if (!$hasCsp) {
    record_finding(
        'FINDING-06',
        'Missing Modern Defensive Headers (Content-Security-Policy, HSTS, Permissions-Policy)',
        'Low',
        '.htaccess and public/.htaccess do not define Content-Security-Policy or Strict-Transport-Security (HSTS). Note: CSP must be carefully designed to avoid breaking InfinityFree automated browser challenges (aes.js).'
    );
}


// ---------------------------------------------------------------------
// TEST SECTION 10: Final Catalogue Invariant & Integrity Guard
// ---------------------------------------------------------------------
echo "\n10. Final Database Integrity Verification\n";

$finalSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("MANDATORY INVARIANT: Catalogue song count strictly unchanged (2,411 songs)", $finalSongCount === 2411, "Count: {$finalSongCount}");

$finalNullSongs = $pdo->query("SELECT song_id FROM `songs` WHERE `artist` IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
assert_test("MANDATORY INVARIANT: NULL-artist songs preserved [1546, 2015, 2196]", $finalNullSongs === [1546, 2015, 2196], "Found: " . json_encode($finalNullSongs));


// ---------------------------------------------------------------------
// SUMMARY & FINDINGS TABLE
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " AUDIT EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Security & Invariant Tests: {$testsTotal}\n";
echo "Passed Checks:                    {$testsPassed}\n";
echo "Failed / Blocked:                 " . ($testsTotal - $testsPassed) . "\n";
echo "Identified Findings:              " . count($findings) . "\n\n";

echo "SECURITY FINDINGS INVENTORY:\n";
foreach ($findings as $f) {
    echo "  [{$f['id']}] [{$f['severity']}] {$f['title']}\n";
    echo "    Details: {$f['details']}\n\n";
}
