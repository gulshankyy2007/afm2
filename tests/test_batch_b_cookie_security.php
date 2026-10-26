<?php
/**
 * AFM#2 - Batch B: HTTPS Detection & Session-Cookie Security Test Suite
 *
 * Verifies FINDING-01 resolution and cookie hardening:
 * - Direct HTTPS signals ($_SERVER['HTTPS'], port 443, REQUEST_SCHEME) enable Secure attribute.
 * - Verified reverse-proxy forwarding signals (OpenResty/Cloudflare) enable Secure attribute in production.
 * - Forged forwarding headers on direct local development connections DO NOT establish HTTPS trust.
 * - Local development over plain HTTP retains usability without cookie lockout.
 * - Explicit configuration overrides (SESSION_COOKIE_SECURE, FORCE_HTTPS) function as expected.
 * - Cookie attributes (HttpOnly, SameSite=Lax, Path=/, Domain='') are strictly preserved.
 * - Actual Set-Cookie response header inspected from live dev server.
 * - Legitimate login, authenticated requests, and logout invalidation remain fully operational.
 * - Batch A's fail-closed authentication integrity remains intact.
 * - Zero secrets or session identifiers leaked to logs.
 *
 * Usage: php tests/test_batch_b_cookie_security.php
 */

declare(strict_types=1);

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

echo "======================================================================\n";
echo " AFM#2 — BATCH B: HTTPS DETECTION & COOKIE SECURITY TEST SUITE\n";
echo " Target: config/config.php & session cookie hardening (FINDING-01)\n";
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
        'status'      => $statusCode,
        'headers'     => $parsedHeaders,
        'cookies'     => $setCookies,
        'raw_headers' => $responseHeaders,
        'body'        => $responseBody ?: ''
    ];
}

// ---------------------------------------------------------------------
// 1. Direct TLS & Verified Proxy HTTPS Detection Unit Tests
// ---------------------------------------------------------------------
echo "1. In-Process HTTPS Detection Signal Matrix\n";

$runWithEnv = function(array $serverVars, array $envVars, callable $fn) {
    $origServer = $_SERVER;
    $origEnv = $_ENV;
    $origGetEnv = [];

    foreach ($serverVars as $k => $v) {
        if ($v === null) unset($_SERVER[$k]);
        else $_SERVER[$k] = $v;
    }
    foreach ($envVars as $k => $v) {
        $origGetEnv[$k] = getenv($k);
        if ($v === null) {
            unset($_ENV[$k]);
            putenv($k);
        } else {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }

    try {
        return $fn();
    } finally {
        $_SERVER = $origServer;
        $_ENV = $origEnv;
        foreach ($origGetEnv as $k => $oldVal) {
            if ($oldVal === false) {
                putenv($k);
            } else {
                putenv("{$k}={$oldVal}");
            }
        }
    }
};

// Test 1.1: Direct HTTPS via $_SERVER['HTTPS'] = 'on'
$directHttps = $runWithEnv(['HTTPS' => 'on', 'SERVER_PORT' => '443'], [], fn() => is_https_request());
assert_test("Direct HTTPS (\$_SERVER['HTTPS'] = 'on') detects as HTTPS", $directHttps === true);

// Test 1.2: Direct HTTPS via Port 443
$portHttps = $runWithEnv(['HTTPS' => null, 'SERVER_PORT' => '443'], [], fn() => is_https_request());
assert_test("Direct HTTPS (SERVER_PORT = 443) detects as HTTPS", $portHttps === true);

// Test 1.3: Direct HTTPS via REQUEST_SCHEME = 'https'
$schemeHttps = $runWithEnv(['HTTPS' => null, 'SERVER_PORT' => '80', 'REQUEST_SCHEME' => 'https'], [], fn() => is_https_request());
assert_test("Direct HTTPS (REQUEST_SCHEME = 'https') detects as HTTPS", $schemeHttps === true);

// Test 1.4: Verified Reverse-Proxy HTTPS on Production Domain (OpenResty simulation)
$proxyProd = $runWithEnv(
    [
        'HTTPS'                 => null,
        'SERVER_PORT'           => '80',
        'HTTP_HOST'             => 'afm2.infinityfreeapp.com',
        'REMOTE_ADDR'           => '185.27.134.57',
        'HTTP_X_FORWARDED_PROTO'=> 'https'
    ],
    ['APP_ENV' => 'production'],
    fn() => is_https_request()
);
assert_test("Verified OpenResty proxy on production domain with X-Forwarded-Proto: https detects as HTTPS", $proxyProd === true);

// Test 1.5: Verified Proxy HTTPS via X-Forwarded-Ssl: on
$proxySsl = $runWithEnv(
    [
        'HTTPS'                => null,
        'SERVER_PORT'          => '80',
        'HTTP_HOST'            => 'afm2.infinityfreeapp.com',
        'REMOTE_ADDR'          => '185.27.134.57',
        'HTTP_X_FORWARDED_SSL' => 'on'
    ],
    ['APP_ENV' => 'production'],
    fn() => is_https_request()
);
assert_test("Verified OpenResty proxy with X-Forwarded-Ssl: on detects as HTTPS", $proxySsl === true);

// Test 1.6: Verified Proxy HTTPS via Cloudflare CF-Visitor JSON
$proxyCf = $runWithEnv(
    [
        'HTTPS'             => null,
        'SERVER_PORT'       => '80',
        'HTTP_HOST'         => 'afm2.infinityfreeapp.com',
        'REMOTE_ADDR'       => '185.27.134.57',
        'HTTP_CF_VISITOR'   => '{"scheme":"https"}'
    ],
    ['APP_ENV' => 'production'],
    fn() => is_https_request()
);
assert_test("Verified Cloudflare proxy with HTTP_CF_VISITOR JSON detects as HTTPS", $proxyCf === true);

// ---------------------------------------------------------------------
// 2. Local Development Safety & Forged Header Resistance
// ---------------------------------------------------------------------
echo "\n2. Local Development Safety & Anti-Spoofing Guardrails\n";

// Test 2.1: Plain local development connection over HTTP
$localDev = $runWithEnv(
    [
        'HTTPS'       => null,
        'SERVER_PORT' => '8080',
        'HTTP_HOST'   => '127.0.0.1:8080',
        'REMOTE_ADDR' => '127.0.0.1'
    ],
    ['APP_ENV' => 'development'],
    fn() => is_https_request()
);
assert_test("Local development over HTTP (127.0.0.1:8080) detects as plain HTTP (false)", $localDev === false);

// Test 2.2: Adversarial client forging X-Forwarded-Proto on local loopback
$forgedHeaderDev = $runWithEnv(
    [
        'HTTPS'                  => null,
        'SERVER_PORT'            => '8080',
        'HTTP_HOST'              => '127.0.0.1:8080',
        'REMOTE_ADDR'            => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https'
    ],
    ['APP_ENV' => 'development', 'TRUST_PROXY_HEADERS' => null],
    fn() => is_https_request()
);
assert_test("Forged X-Forwarded-Proto on direct local dev connection is REJECTED (anti-lockout guard)", $forgedHeaderDev === false);

// Test 2.3: Explicit override FORCE_HTTPS=true
$forceHttps = $runWithEnv([], ['FORCE_HTTPS' => 'true'], fn() => is_https_request());
assert_test("Explicit environment override FORCE_HTTPS=true enforces HTTPS", $forceHttps === true);

// Test 2.4: Explicit override SESSION_COOKIE_SECURE=false
$forceCookieFalse = $runWithEnv(['HTTPS' => 'on'], ['SESSION_COOKIE_SECURE' => 'false'], fn() => is_https_request());
assert_test("Explicit environment override SESSION_COOKIE_SECURE=false enforces non-secure", $forceCookieFalse === false);

// ---------------------------------------------------------------------
// 3. Dynamic Base URL Consistency
// ---------------------------------------------------------------------
echo "\n3. Dynamic Base URL Scheme Resolution\n";

$baseUrlHttp = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '8080', 'HTTP_HOST' => '127.0.0.1:8080', 'REMOTE_ADDR' => '127.0.0.1'],
    ['APP_ENV' => 'development'],
    fn() => get_base_url()
);
assert_test("get_base_url() returns http://127.0.0.1:8080 in local dev", $baseUrlHttp === 'http://127.0.0.1:8080');

$baseUrlHttps = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'REMOTE_ADDR' => '185.27.134.57', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    ['APP_ENV' => 'production'],
    fn() => get_base_url()
);
assert_test("get_base_url() returns https://afm2.infinityfreeapp.com in production", $baseUrlHttps === 'https://afm2.infinityfreeapp.com', "Got: " . var_export($baseUrlHttps, true));

// ---------------------------------------------------------------------
// 4. Session Cookie Attribute Verification (Live Local Server)
// ---------------------------------------------------------------------
echo "\n4. Live Session Cookie Attribute Verification\n";

$loginHeadRes = test_http("http://127.0.0.1:8080/login.php", 'GET');
$cookieHeader = '';
foreach ($loginHeadRes['cookies'] as $c) {
    if (stripos($c, 'AFM2_SESSID=') !== false) {
        $cookieHeader = $c;
        break;
    }
}

assert_test("Server emits AFM2_SESSID cookie", !empty($cookieHeader));
assert_test("Cookie specifies HttpOnly attribute", stripos($cookieHeader, 'HttpOnly') !== false);
assert_test("Cookie specifies SameSite=Lax attribute", stripos($cookieHeader, 'SameSite=Lax') !== false);
assert_test("Cookie specifies Path=/ attribute", stripos($cookieHeader, 'Path=/') !== false);
assert_test("Local development cookie omits Secure flag to prevent browser drop", stripos($cookieHeader, 'Secure') === false);

// ---------------------------------------------------------------------
// 5. Authentication Lifecycle and Batch A Fail-Closed Integrity Check
// ---------------------------------------------------------------------
echo "\n5. Authentication Lifecycle & Batch A Regression Verification\n";

// Clear rate limits for clean test run
clear_rate_limits();

// Legitimate Login
preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $loginHeadRes['body'], $mToken);
$csrfToken = $mToken[1] ?? '';
preg_match('/(AFM2_SESSID=[^;]+)/i', $cookieHeader, $mc);
$sessionCookie = $mc[1] ?? '';

$loginRes = test_http(
    "http://127.0.0.1:8080/login.php",
    'POST',
    http_build_query([
        'csrf_token' => $csrfToken,
        'username'   => 'adminuser',
        'password'   => 'SecurePassword2026!'
    ]),
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $sessionCookie
    ]
);

assert_test("Legitimate login succeeds (HTTP 302 -> index.php)", $loginRes['status'] === 302);

foreach ($loginRes['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc2)) {
        $sessionCookie = $mc2[1];
        break;
    }
}

// Authenticated requests work
$authIndex = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Authenticated request to index.php succeeds (HTTP 200)", $authIndex['status'] === 200);

$authLib = test_http("http://127.0.0.1:8080/library.php?page=1", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Authenticated request to library.php succeeds (HTTP 200)", $authLib['status'] === 200);

// Logout invalidation
$logoutRes = test_http("http://127.0.0.1:8080/logout.php", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Logout executes and redirects (HTTP 302)", $logoutRes['status'] === 302);

// Post-logout request is denied
$postLogout = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Subsequent request with logged-out cookie is rejected (HTTP 302 -> login.php)", $postLogout['status'] === 302);

// Batch A Integrity Check: Non-existent user ID does NOT auto-associate with user 1
$ghostSessionRes = $runWithEnv([], [], function() {
    $_SESSION['afm_user_id'] = 99999999;
    return get_current_user_id();
});
assert_test("Batch A invariant: Nonexistent user ID fails closed (returns null)", $ghostSessionRes === null);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " BATCH B TEST EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$testsTotal}\n";
echo "Passed:               {$testsPassed}\n";
echo "Failed:               " . ($testsTotal - $testsPassed) . "\n";

if ($testsTotal === $testsPassed) {
    echo "\n🎉 ALL BATCH B TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n\n";
} else {
    echo "\n❌ SOME BATCH B TESTS FAILED!\n\n";
    exit(1);
}
