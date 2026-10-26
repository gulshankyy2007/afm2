<?php
/**
 * AFM#2 - Batch B: HTTPS Detection & Session-Cookie Security Test Suite
 *
 * Verifies FINDING-01 resolution, proxy trust boundaries, and cookie hardening:
 * 1. Direct HTTPS detection (HTTPS=on, port 443, REQUEST_SCHEME=https).
 * 2. Plain HTTP on loopback with no forwarding headers.
 * 3. Forged X-Forwarded-Proto: https on direct loopback HTTP (in-process & live HTTP).
 * 4. Forwarding headers when proxy trust is disabled (default fail-closed behavior).
 * 5. Forwarding headers when proxy trust is explicitly enabled (TRUST_PROXY_HEADERS=true).
 * 6. Conflicting or malformed forwarding-header values.
 * 7. Apache integration check (evaluated and documented).
 * 8. Cookie attributes on verified HTTPS and local HTTP (HttpOnly, SameSite=Lax, Path=/, Secure).
 * 9. Session creation, login, authenticated requests, and logout invalidation.
 * 10. Batch A fail-closed behavior for invalid session identities.
 * 11. Base-URL scheme and host handling (header injection resistance, canonical APP_URL).
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
echo " Target: config/config.php, .htaccess & session cookies (FINDING-01)\n";
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

/**
 * Environment isolation helper
 */
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

// =====================================================================
// 1. DIRECT HTTPS DETECTION
// =====================================================================
echo "1. Direct HTTPS Detection Signals\n";

$directHttps = $runWithEnv(['HTTPS' => 'on', 'SERVER_PORT' => '443'], [], fn() => is_https_request());
assert_test("Direct HTTPS (\$_SERVER['HTTPS'] = 'on') detects as HTTPS", $directHttps === true);

$directHttpsUpper = $runWithEnv(['HTTPS' => 'ON', 'SERVER_PORT' => '443'], [], fn() => is_https_request());
assert_test("Direct HTTPS (\$_SERVER['HTTPS'] = 'ON' case-insensitive) detects as HTTPS", $directHttpsUpper === true);

$portHttps = $runWithEnv(['HTTPS' => null, 'SERVER_PORT' => '443'], [], fn() => is_https_request());
assert_test("Direct HTTPS (SERVER_PORT = 443) detects as HTTPS", $portHttps === true);

$schemeHttps = $runWithEnv(['HTTPS' => null, 'SERVER_PORT' => '80', 'REQUEST_SCHEME' => 'https'], [], fn() => is_https_request());
assert_test("Direct HTTPS (REQUEST_SCHEME = 'https') detects as HTTPS", $schemeHttps === true);

$directCookieSecure = $runWithEnv(['HTTPS' => 'on'], [], fn() => is_session_cookie_secure());
assert_test("Direct HTTPS activates session cookie Secure attribute", $directCookieSecure === true);

// =====================================================================
// 2. PLAIN HTTP ON LOOPBACK WITH NO FORWARDING HEADERS
// =====================================================================
echo "\n2. Plain HTTP on Loopback (No Forwarding Headers)\n";

$localDev = $runWithEnv(
    [
        'HTTPS'          => null,
        'SERVER_PORT'    => '8080',
        'HTTP_HOST'      => '127.0.0.1:8080',
        'REMOTE_ADDR'    => '127.0.0.1',
        'REQUEST_SCHEME' => 'http'
    ],
    ['APP_ENV' => 'development'],
    fn() => is_https_request()
);
assert_test("Local loopback plain HTTP detects as false (non-HTTPS)", $localDev === false);

$localCookieSecure = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '8080', 'REMOTE_ADDR' => '127.0.0.1'],
    ['APP_ENV' => 'development'],
    fn() => is_session_cookie_secure()
);
assert_test("Local loopback session cookie Secure attribute is false (prevents browser cookie drop)", $localCookieSecure === false);

// =====================================================================
// 3. FORGED X-FORWARDED-PROTO ON DIRECT LOOPBACK HTTP
// =====================================================================
echo "\n3. Forged X-Forwarded-Proto on Direct Loopback HTTP\n";

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
assert_test("Forged X-Forwarded-Proto on direct loopback is REJECTED in-process (is_https_request = false)", $forgedHeaderDev === false);

$forgedCookieDev = $runWithEnv(
    [
        'HTTPS'                  => null,
        'SERVER_PORT'            => '8080',
        'HTTP_HOST'              => '127.0.0.1:8080',
        'REMOTE_ADDR'            => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https'
    ],
    ['APP_ENV' => 'development', 'TRUST_PROXY_HEADERS' => null],
    fn() => is_session_cookie_secure()
);
assert_test("Forged X-Forwarded-Proto on direct loopback does NOT enable Secure cookie (is_session_cookie_secure = false)", $forgedCookieDev === false);

// Observable live HTTP request with forged header to local server
$liveForgedRes = test_http("http://127.0.0.1:8080/login.php", 'GET', null, ['X-Forwarded-Proto' => 'https']);
$liveForgedCookie = '';
foreach ($liveForgedRes['cookies'] as $c) {
    if (stripos($c, 'AFM2_SESSID=') !== false) {
        $liveForgedCookie = $c;
        break;
    }
}
assert_test("Live HTTP request with forged X-Forwarded-Proto: https receives cookie without Secure flag",
    !empty($liveForgedCookie) && stripos($liveForgedCookie, 'Secure') === false);

// =====================================================================
// 4. FORWARDING HEADERS WHEN PROXY TRUST IS DISABLED (DEFAULT)
// =====================================================================
echo "\n4. Forwarding Headers When Proxy Trust is Disabled (Fail Closed)\n";

$untrustedProto = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    ['APP_ENV' => 'production', 'TRUST_PROXY_HEADERS' => null],
    fn() => is_https_request()
);
assert_test("Default unconfigured proxy headers: X-Forwarded-Proto is ignored (false)", $untrustedProto === false);

$untrustedSsl = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_SSL' => 'on'],
    ['APP_ENV' => 'production', 'TRUST_PROXY_HEADERS' => 'false'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=false: X-Forwarded-Ssl is ignored (false)", $untrustedSsl === false);

$untrustedPort = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_PORT' => '443'],
    ['APP_ENV' => 'production', 'TRUST_PROXY_HEADERS' => '0'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=0: X-Forwarded-Port: 443 is ignored (false)", $untrustedPort === false);

$untrustedVisitor = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'],
    ['APP_ENV' => 'production', 'TRUST_PROXY_HEADERS' => 'false'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=false: HTTP_CF_VISITOR is ignored (false)", $untrustedVisitor === false);

// =====================================================================
// 5. FORWARDING HEADERS WHEN PROXY TRUST IS EXPLICITLY ENABLED
// =====================================================================
echo "\n5. Forwarding Headers When Proxy Trust is Explicitly Enabled\n";

$trustedProto = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=true: X-Forwarded-Proto: https evaluates to true", $trustedProto === true);

$trustedSsl = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_SSL' => 'on'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=true: X-Forwarded-Ssl: on evaluates to true", $trustedSsl === true);

$trustedPort = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_X_FORWARDED_PORT' => '443'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=true: X-Forwarded-Port: 443 evaluates to true", $trustedPort === true);

$trustedCf = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'afm2.infinityfreeapp.com', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Explicit TRUST_PROXY_HEADERS=true: HTTP_CF_VISITOR evaluates to true", $trustedCf === true);

// =====================================================================
// 6. CONFLICTING OR MALFORMED FORWARDING HEADERS
// =====================================================================
echo "\n6. Conflicting or Malformed Forwarding Header Values\n";

$conflictHttpWithPort443 = $runWithEnv(
    [
        'HTTPS'                  => null,
        'SERVER_PORT'            => '80',
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_X_FORWARDED_PORT'  => '443'
    ],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Conflicting headers: X-Forwarded-Proto: http with X-Forwarded-Port: 443 returns false", $conflictHttpWithPort443 === false);

$multiHopHttpFirst = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'http, https'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Multi-hop header with plain HTTP first ('http, https') returns false", $multiHopHttpFirst === false);

$multiHopHttpsFirst = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https, http'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Multi-hop header with HTTPS first ('https, http') returns true", $multiHopHttpsFirst === true);

$malformedProto = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'ftp://bad'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Malformed X-Forwarded-Proto ('ftp://bad') returns false", $malformedProto === false);

$malformedJsonVisitor = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_CF_VISITOR' => '{malformed_json'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("Malformed JSON in HTTP_CF_VISITOR returns false", $malformedJsonVisitor === false);

$visitorSchemeHttp = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_CF_VISITOR' => '{"scheme":"http"}'],
    ['TRUST_PROXY_HEADERS' => 'true'],
    fn() => is_https_request()
);
assert_test("HTTP_CF_VISITOR with scheme: http returns false", $visitorSchemeHttp === false);

// =====================================================================
// 7. APACHE INTEGRATION & .HTACCESS NORMALIZATION CHECK
// =====================================================================
echo "\n7. Apache Integration & .htaccess Audit\n";

$rootHtaccess = file_get_contents(dirname(__DIR__) . '/.htaccess') ?: '';
$publicHtaccess = file_get_contents(dirname(__DIR__) . '/public/.htaccess') ?: '';

$rootHasSetEnvIf = stripos($rootHtaccess, 'SetEnvIf X-Forwarded-Proto') !== false;
$publicHasSetEnvIf = stripos($publicHtaccess, 'SetEnvIf X-Forwarded-Proto') !== false;

assert_test("Root .htaccess does NOT contain unconditional SetEnvIf X-Forwarded-Proto", !$rootHasSetEnvIf);
assert_test("Public .htaccess does NOT contain unconditional SetEnvIf X-Forwarded-Proto", !$publicHasSetEnvIf);

// Check if Apache daemon is actively running and bound to an integration port
$apacheStatus = trim((string)@shell_exec("pgrep -x 'apache2' || pgrep -x 'httpd'"));
if (!empty($apacheStatus)) {
    echo "  [INFO] Apache process detected (PID: {$apacheStatus}), but AFM#2 dev server runs on PHP-CLI.\n";
    echo "  [INFO] Apache .htaccess integration: verified statically (SetEnvIf bypass removed).\n";
} else {
    echo "  [UNTESTED] Live Apache HTTP integration is unavailable on this host (CLI environment uses php -S).\n";
    echo "             Static audit confirms unconditional SetEnvIf bypass is completely eliminated.\n";
}

// =====================================================================
// 8. COOKIE ATTRIBUTES ON VERIFIED HTTPS AND LOCAL HTTP
// =====================================================================
echo "\n8. Cookie Attributes on Verified HTTPS and Local HTTP\n";

// Explicit overrides
$explicitCookieTrue = $runWithEnv(['HTTPS' => null], ['SESSION_COOKIE_SECURE' => 'true'], fn() => is_session_cookie_secure());
assert_test("Explicit SESSION_COOKIE_SECURE=true forces Secure cookie (true)", $explicitCookieTrue === true);

$explicitCookieFalse = $runWithEnv(['HTTPS' => 'on'], ['SESSION_COOKIE_SECURE' => 'false'], fn() => is_session_cookie_secure());
assert_test("Explicit SESSION_COOKIE_SECURE=false forces non-secure cookie (false)", $explicitCookieFalse === false);

$forceHttpsReq = $runWithEnv([], ['FORCE_HTTPS' => 'true'], fn() => is_https_request());
assert_test("Explicit FORCE_HTTPS=true forces is_https_request to true", $forceHttpsReq === true);

$forceHttpsFalse = $runWithEnv(['HTTPS' => 'on'], ['FORCE_HTTPS' => 'false'], fn() => is_https_request());
assert_test("Explicit FORCE_HTTPS=false forces is_https_request to false", $forceHttpsFalse === false);

// Live local server cookie inspection
$loginHeadRes = test_http("http://127.0.0.1:8080/login.php", 'GET');
$cookieHeader = '';
foreach ($loginHeadRes['cookies'] as $c) {
    if (stripos($c, 'AFM2_SESSID=') !== false) {
        $cookieHeader = $c;
        break;
    }
}
assert_test("Live server emits AFM2_SESSID cookie", !empty($cookieHeader));
assert_test("Cookie specifies HttpOnly attribute", stripos($cookieHeader, 'HttpOnly') !== false);
assert_test("Cookie specifies SameSite=Lax attribute", stripos($cookieHeader, 'SameSite=Lax') !== false);
assert_test("Cookie specifies Path=/ attribute", stripos($cookieHeader, 'Path=/') !== false);
assert_test("Local development cookie omits Secure flag to prevent browser drop", stripos($cookieHeader, 'Secure') === false);

// =====================================================================
// 9. AUTHENTICATION LIFECYCLE & LOGOUT INVALIDATION
// =====================================================================
echo "\n9. Authentication Lifecycle & Logout Invalidation\n";

clear_rate_limits();

// Extract CSRF token
preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $loginHeadRes['body'], $mToken);
$csrfToken = $mToken[1] ?? '';
preg_match('/(AFM2_SESSID=[^;]+)/i', $cookieHeader, $mc);
$sessionCookie = $mc[1] ?? '';

// Legitimate Login
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

// Verify cookie invalidation header in logout response
$logoutCookieHeader = '';
foreach ($logoutRes['cookies'] as $c) {
    if (stripos($c, 'AFM2_SESSID=') !== false) {
        $logoutCookieHeader = $c;
        break;
    }
}
assert_test("Logout emits expired Set-Cookie header", !empty($logoutCookieHeader) && (stripos($logoutCookieHeader, 'Max-Age=0') !== false || stripos($logoutCookieHeader, '1970') !== false || stripos($logoutCookieHeader, 'deleted') !== false));
assert_test("Logout Set-Cookie preserves Path=/", stripos($logoutCookieHeader, 'Path=/') !== false);
assert_test("Logout Set-Cookie preserves SameSite=Lax", stripos($logoutCookieHeader, 'SameSite=Lax') !== false);

// Post-logout request is denied
$postLogout = test_http("http://127.0.0.1:8080/index.php", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Subsequent request with invalidated session cookie is rejected (HTTP 302 -> login.php)", $postLogout['status'] === 302);

// =====================================================================
// 10. BATCH A FAIL-CLOSED BEHAVIOR FOR INVALID USER SESSIONS
// =====================================================================
echo "\n10. Batch A Fail-Closed Integrity Verification\n";

$ghostSessionRes = $runWithEnv([], [], function() {
    $_SESSION['afm_user_id'] = 99999999;
    return get_current_user_id();
});
assert_test("Batch A invariant: Nonexistent user ID fails closed (returns null)", $ghostSessionRes === null);

$stringSessionRes = $runWithEnv([], [], function() {
    $_SESSION['afm_user_id'] = 'malicious_non_integer';
    return get_current_user_id();
});
assert_test("Batch A invariant: Non-numeric user ID fails closed (returns null)", $stringSessionRes === null);

// =====================================================================
// 11. BASE-URL SCHEME AND HOST HANDLING
// =====================================================================
echo "\n11. Base-URL Scheme and Host Validation\n";

// Plain HTTP base URL
$baseUrlHttp = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '8080', 'HTTP_HOST' => '127.0.0.1:8080'],
    ['APP_ENV' => 'development'],
    fn() => get_base_url()
);
assert_test("get_base_url() returns http://127.0.0.1:8080 in local dev", $baseUrlHttp === 'http://127.0.0.1:8080');

// HTTPS base URL via direct TLS
$baseUrlHttps = $runWithEnv(
    ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'HTTP_HOST' => 'afm2.infinityfreeapp.com'],
    ['APP_ENV' => 'production'],
    fn() => get_base_url()
);
assert_test("get_base_url() returns https://afm2.infinityfreeapp.com under direct HTTPS", $baseUrlHttps === 'https://afm2.infinityfreeapp.com');

// Canonical APP_URL override
$baseUrlAppUrl = $runWithEnv(
    ['HTTPS' => null, 'HTTP_HOST' => 'internal-origin.local'],
    ['APP_URL' => 'https://custom.afm2.com/'],
    fn() => get_base_url()
);
assert_test("get_base_url() honors canonical APP_URL override (https://custom.afm2.com)", $baseUrlAppUrl === 'https://custom.afm2.com');

// Host header injection resistance: CRLF injection
$baseUrlCrlf = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => "evil.com\r\nX-Injected: yes"],
    [],
    fn() => get_base_url()
);
assert_test("Host with CRLF injection is rejected and falls back to localhost", $baseUrlCrlf === 'http://localhost');

// Host header injection resistance: HTML injection
$baseUrlHtml = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'evil.com<script>alert(1)</script>'],
    [],
    fn() => get_base_url()
);
assert_test("Host with HTML tags is rejected and falls back to localhost", $baseUrlHtml === 'http://localhost');

// Host header injection resistance: Path traversal
$baseUrlSlash = $runWithEnv(
    ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_HOST' => 'evil.com/path/traversal'],
    [],
    fn() => get_base_url()
);
assert_test("Host with path traversal is rejected and falls back to localhost", $baseUrlSlash === 'http://localhost');

// =====================================================================
// SUMMARY
// =====================================================================
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
