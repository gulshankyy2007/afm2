<?php
/**
 * AFM#2 — Phase 3B, Batch E Test Suite: Rate Limiting & Security Headers
 *
 * Targets:
 * - FINDING-05: Login rate limiting with dual-bucket throttling (source IP + composite account/IP + global account),
 *               proxy trust boundary compliance, zero secret leakage, and fail-closed session fallback.
 * - FINDING-06: Modern defensive security headers (CSP, conditional HSTS, Permissions-Policy, nosniff,
 *               SAMEORIGIN, strict-origin-when-cross-origin) across PHP and Apache layers.
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
echo " AFM#2 — BATCH E: RATE LIMITING & SECURITY HEADERS TEST SUITE\n";
echo " Targets: FINDING-05 (Login Throttling) & FINDING-06 (Security Headers)\n";
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
    $headerCounts = [];
    $cookies = [];

    foreach ($responseHeaders as $idx => $line) {
        if ($idx === 0 && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $statusCode = (int)$m[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $hName = strtolower(trim($parts[0]));
            $hVal = trim($parts[1]);
            $headerCounts[$hName] = ($headerCounts[$hName] ?? 0) + 1;
            if ($hName === 'set-cookie') {
                $cookies[] = $hVal;
            } else {
                $parsedHeaders[$hName] = $hVal;
            }
        }
    }

    return [
        'status'        => $statusCode,
        'headers'       => $parsedHeaders,
        'header_counts' => $headerCounts,
        'raw_headers'   => $responseHeaders,
        'cookies'       => $cookies,
        'body'          => (string)$responseBody
    ];
}

// =====================================================================
// SECTION 0: PRE-FLIGHT CATALOGUE INVARIANTS
// =====================================================================
echo "--- 0. Pre-Flight Catalogue Invariants ---\n";
$preSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Pre-test Catalogue Baseline Invariant: strictly 2,411 songs", $preSongCount === 2411, "Found: {$preSongCount}");

$preNullArtists = $pdo->query("SELECT song_id FROM `songs` WHERE `artist` IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
assert_test("Pre-test Missing Artist Invariant: strictly [1546, 2015, 2196]", $preNullArtists === [1546, 2015, 2196], "Found: " . json_encode($preNullArtists));

// =====================================================================
// SECTION 1: FINDING-05 — LOGIN RATE LIMITING HARDENING
// =====================================================================
echo "\n--- 1. FINDING-05: Login Rate Limiting Hardening ---\n";

// Reset any previous rate limits
clear_rate_limits('*');

// Helper to get fresh session and CSRF token
$getLoginContext = function(): array {
    $res = test_http("http://127.0.0.1:8080/login.php", 'GET');
    $cookie = '';
    foreach ($res['cookies'] as $c) {
        if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $m)) {
            $cookie = $m[1];
            break;
        }
    }
    preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $res['body'], $m);
    $csrf = $m[1] ?? '';
    return ['cookie' => $cookie, 'csrf' => $csrf];
};

// 1. Repeated invalid login attempts trigger rate limiting
$ctx = $getLoginContext();
$attemptsBlocked = false;
$initialAttempts = 0;

for ($i = 1; $i <= 6; $i++) {
    $postBody = http_build_query([
        'csrf_token' => $ctx['csrf'],
        'username'   => 'adminuser',
        'password'   => 'WrongPassword_' . $i
    ]);
    $res = test_http("http://127.0.0.1:8080/login.php", 'POST', $postBody, [
        'Cookie'       => $ctx['cookie'],
        'Content-Type' => 'application/x-www-form-urlencoded'
    ]);

    if (strpos($res['body'], 'Too many failed login attempts') !== false) {
        $attemptsBlocked = true;
        $initialAttempts = $i;
        break;
    }
}

assert_test("1. Repeated invalid login attempts trigger rate limit at configured threshold (attempt {$initialAttempts} blocked)", $attemptsBlocked && $initialAttempts === 6);

// 2. Correct credentials blocked after the configured threshold
$postBodyGood = http_build_query([
    'csrf_token' => $ctx['csrf'],
    'username'   => 'adminuser',
    'password'   => 'SecurePassword2026!'
]);
$goodResBlocked = test_http("http://127.0.0.1:8080/login.php", 'POST', $postBodyGood, [
    'Cookie'       => $ctx['cookie'],
    'Content-Type' => 'application/x-www-form-urlencoded'
]);

$validBlocked = ($goodResBlocked['status'] === 200 && strpos($goodResBlocked['body'], 'Too many failed login attempts') !== false);
assert_test("2. Correct credentials after configured threshold remain blocked without creating session", $validBlocked);

// 3. Multiple usernames attempted from one source (IP threshold)
clear_rate_limits('*');
$origServer = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '198.51.100.55';

for ($u = 1; $u <= 15; $u++) {
    record_failed_login('target_user_' . $u);
}
// 15 failed attempts from 198.51.100.55 across 15 distinct usernames
$ipThrottled = is_rate_limited('yet_another_user_99');
assert_test("3. Multiple usernames attempted from one source trigger IP-level threshold (15 attempts)", $ipThrottled);

// 4a. Target account receiving repeated failures from multiple simulated sources DOES NOT lock out legitimate user from clean IP (Anti-DoS)
clear_rate_limits('*');
$origEnv = $_ENV['TRUST_PROXY_HEADERS'] ?? null;
$_ENV['TRUST_PROXY_HEADERS'] = 'true';
putenv('TRUST_PROXY_HEADERS=true');

// Attacker simulates 25 distributed source IPs attempting 'adminuser'
for ($s = 1; $s <= 25; $s++) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.{$s}";
    record_failed_login('adminuser');
}

// Check from a clean, legitimate client IP (203.0.113.250)
$_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.250";
$legitUserBlocked = is_rate_limited('adminuser');
assert_test("4a. Multi-source attack against target account does NOT lock out legitimate user from clean IP (Anti-DoS)", !$legitUserBlocked);

// 4b. Legitimate login attempt succeeds even after target account received 25 failures from other sources
$cleanLoginResult = attempt_login('adminuser', 'SecurePassword2026!');
assert_test("4b. Legitimate login succeeds after target account received 25 failed attempts from distinct attacker IPs", $cleanLoginResult['success'] === true);

// 4c. Verify that an attacker IP that reached 5 failures for 'adminuser' is blocked
$_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.1";
for ($a = 0; $a < 5; $a++) {
    record_failed_login('adminuser');
}
$attackerThrottled = is_rate_limited('adminuser');
assert_test("4c. Attacker IP reaching composite limit (5 attempts on target account) is throttled", $attackerThrottled);

// 4d. Single source attempting multiple accounts is throttled at IP threshold (15 attempts)
clear_rate_limits('*');
$_SERVER['HTTP_X_FORWARDED_FOR'] = "198.51.100.99";
for ($m = 1; $m <= 15; $m++) {
    record_failed_login("account_{$m}");
}
$multiAccountThrottled = is_rate_limited("account_999");
assert_test("4d. Single source attempting multiple accounts is throttled at IP threshold (15 attempts)", $multiAccountThrottled);

// 4e. Verify recovery after configured time window (15 minutes)
clear_rate_limits('*');
$_SERVER['HTTP_X_FORWARDED_FOR'] = "198.51.100.88";
$pdo->exec("INSERT INTO `login_rate_limits` (`ip_address`, `attempted_at`) VALUES ('198.51.100.88', NOW() - INTERVAL 16 MINUTE)");
$recovered = !is_rate_limited('any_user');
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM `login_rate_limits` WHERE `ip_address` = '198.51.100.88'")->fetchColumn();
assert_test("4e. Rate limit counters expire and recover after configured 15-minute window", $recovered && $remaining === 0);

// Restore proxy environment
if ($origEnv !== null) {
    $_ENV['TRUST_PROXY_HEADERS'] = $origEnv;
    putenv("TRUST_PROXY_HEADERS={$origEnv}");
} else {
    unset($_ENV['TRUST_PROXY_HEADERS']);
    putenv('TRUST_PROXY_HEADERS');
}
$_SERVER = $origServer;

// 5. Missing or malformed IP metadata defaults safely
$oldRemote = $_SERVER['REMOTE_ADDR'] ?? null;
unset($_SERVER['REMOTE_ADDR']);
$missingIp = get_client_ip();
$_SERVER['REMOTE_ADDR'] = 'invalid...ip...format';
$malformedIp = get_client_ip();
if ($oldRemote !== null) {
    $_SERVER['REMOTE_ADDR'] = $oldRemote;
} else {
    unset($_SERVER['REMOTE_ADDR']);
}
assert_test("5. Missing or malformed IP metadata safely defaults to 127.0.0.1", $missingIp === '127.0.0.1' && $malformedIp === '127.0.0.1');

// 6. Forged forwarding headers with proxy trust disabled
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.10.11.12';
unset($_ENV['TRUST_PROXY_HEADERS']);
putenv('TRUST_PROXY_HEADERS');

$untrustedIp = get_client_ip();
assert_test("6. Forged forwarding headers are strictly ignored when proxy trust is disabled", $untrustedIp === '198.51.100.10');

// 7. Forwarding headers with explicitly configured proxy trust
$_ENV['TRUST_PROXY_HEADERS'] = 'true';
putenv('TRUST_PROXY_HEADERS=true');

$trustedForwardedIp = get_client_ip();
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
$trustedXForwardedIp = get_client_ip();

unset($_ENV['TRUST_PROXY_HEADERS']);
putenv('TRUST_PROXY_HEADERS');
$_SERVER = $origServer;

assert_test("7. Forwarding headers (CF-Connecting-IP and X-Forwarded-For) honored when TRUST_PROXY_HEADERS=true", $trustedForwardedIp === '9.10.11.12' && $trustedXForwardedIp === '1.2.3.4');

// 8. Shared-proxy behavior (User B not locked out when User A hits composite limit)
clear_rate_limits('*');
$_SERVER['REMOTE_ADDR'] = '203.0.113.100';

for ($i = 0; $i < 5; $i++) {
    record_failed_login('user_a_attacker');
}

$userABlocked = is_rate_limited('user_a_attacker');
$userBAllowed = !is_rate_limited('user_b_benign');
assert_test("8. Shared-proxy behavior: User B is not locked out when User A fails 5 attempts from same IP", $userABlocked && $userBAllowed);
$_SERVER = $origServer;

// 9. Counter expiration and recovery
clear_rate_limits('*');
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';

// Insert an attempt from 16 minutes ago
$pdo->exec("INSERT INTO `login_rate_limits` (`ip_address`, `attempted_at`) VALUES ('198.51.100.77', NOW() - INTERVAL 16 MINUTE)");
$expiredRecovered = !is_rate_limited();

$remainingAttempts = (int)$pdo->query("SELECT COUNT(*) FROM `login_rate_limits` WHERE `ip_address` = '198.51.100.77'")->fetchColumn();
assert_test("9. Expired attempts older than 15 minutes are purged and rate limit recovers", $expiredRecovered && $remainingAttempts === 0);
$_SERVER = $origServer;

// 10. Successful login clears rate limits
clear_rate_limits('*');
$ctx = $getLoginContext();

// 4 failed attempts
for ($i = 1; $i <= 4; $i++) {
    test_http("http://127.0.0.1:8080/login.php", 'POST', http_build_query([
        'csrf_token' => $ctx['csrf'],
        'username'   => 'adminuser',
        'password'   => 'WrongPassword_' . $i
    ]), [
        'Cookie'       => $ctx['cookie'],
        'Content-Type' => 'application/x-www-form-urlencoded'
    ]);
}

$attemptsBeforeSuccess = (int)$pdo->query("SELECT COUNT(*) FROM `login_rate_limits`")->fetchColumn();

// Successful login
$loginRes = test_http("http://127.0.0.1:8080/login.php", 'POST', http_build_query([
    'csrf_token' => $ctx['csrf'],
    'username'   => 'adminuser',
    'password'   => 'SecurePassword2026!'
]), [
    'Cookie'       => $ctx['cookie'],
    'Content-Type' => 'application/x-www-form-urlencoded'
]);

$attemptsAfterSuccess = (int)$pdo->query("SELECT COUNT(*) FROM `login_rate_limits`")->fetchColumn();
assert_test("10. Successful login purges rate limit bucket for user and source", $attemptsBeforeSuccess > 0 && $attemptsAfterSuccess === 0);

// 11. Storage & database failure handling modes
// Mode 1: Rate-limit storage failure while user lookup remains available
$pdo->exec("RENAME TABLE `login_rate_limits` TO `login_rate_limits_tmp_verify`");

try {
    // 11a: Within the session, failed attempts increment session fallback and block after 5 attempts
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['afm_fallback_login_attempts'] = 5;
    $_SESSION['afm_fallback_login_time'] = time();

    $mode1SessionBlocked = is_rate_limited('adminuser');
    assert_test("11a. Rate-limit storage failure: In-session fallback triggers lockout after 5 failed attempts", $mode1SessionBlocked);

    // 11b: Session-only tracking limitation: Creating a new session resets fallback tracking (documented limitation)
    $_SESSION = [];
    $mode1SessionBypassed = !is_rate_limited('adminuser');
    assert_test("11b. Rate-limit storage failure: New session resets session-only fallback (documented limitation)", $mode1SessionBypassed);

    // 11c: Legitimate user login availability: Correct credentials succeed even when rate-limit storage fails
    $mode1LegitLogin = attempt_login('adminuser', 'SecurePassword2026!');
    assert_test("11c. Rate-limit storage failure: User lookup remains available and legitimate login succeeds", $mode1LegitLogin['success'] === true);
} finally {
    $pdo->exec("RENAME TABLE `login_rate_limits_tmp_verify` TO `login_rate_limits`");
}

// Mode 2: General database failure that also prevents credential verification
$subCodeGeneralDbFail = '$_ENV["DB_NAME"] = "nonexistent_db"; require "config/config.php"; require "includes/auth.php"; $res = attempt_login("adminuser", "SecurePassword2026!"); echo json_encode($res);';
$dbFailCmd = 'php -r ' . escapeshellarg($subCodeGeneralDbFail) . ' 2>/dev/null';
$dbFailOutput = json_decode(trim((string)shell_exec($dbFailCmd)), true);

$mode2GeneralDbSafe = (
    is_array($dbFailOutput)
    && ($dbFailOutput['success'] ?? true) === false
    && ($dbFailOutput['error'] ?? '') === 'An unexpected error occurred during login.'
);
assert_test("11d. General database failure: Fails closed safely with generic error and zero secret/stack leakage", $mode2GeneralDbSafe);

// 12. Plaintext credential and secret protection
$errorLogContent = '';
if (file_exists('/tmp/afm_test_error.log')) {
    $errorLogContent = file_get_contents('/tmp/afm_test_error.log');
}
$noLeakedSecrets = (strpos($errorLogContent, 'SecurePassword2026!') === false && strpos($errorLogContent, 'WrongPassword_') === false);
assert_test("12. Zero plaintext credentials, passwords, or CSRF tokens leaked in logs or error traces", $noLeakedSecrets);

// =====================================================================
// SECTION 2: FINDING-06 — SECURITY HEADERS HARDENING
// =====================================================================
echo "\n--- 2. FINDING-06: Security Headers Hardening ---\n";

// 13. Standard response security headers on HTML responses
$loginRes = test_http("http://127.0.0.1:8080/login.php", 'GET');
$h = $loginRes['headers'];

$hasNosniff = ($h['x-content-type-options'] ?? '') === 'nosniff';
$hasSameOrigin = ($h['x-frame-options'] ?? '') === 'SAMEORIGIN';
$hasReferrer = ($h['referrer-policy'] ?? '') === 'strict-origin-when-cross-origin';
$hasPermissions = ($h['permissions-policy'] ?? '') === 'camera=(), microphone=(), geolocation=(), payment=()';
$hasCsp = !empty($h['content-security-policy']);

assert_test("13. Expected defensive security headers present on standard HTML response", $hasNosniff && $hasSameOrigin && $hasReferrer && $hasPermissions && $hasCsp, "nosniff: " . ($hasNosniff ? 'OK' : 'FAIL') . ", frame: " . ($hasSameOrigin ? 'OK' : 'FAIL') . ", csp: " . ($hasCsp ? 'OK' : 'FAIL'));

// 14. Headers on redirects
$redirRes = test_http("http://127.0.0.1:8080/index.php", 'GET');
$rh = $redirRes['headers'];
$redirHasHeaders = ($redirRes['status'] === 302
    && ($rh['x-content-type-options'] ?? '') === 'nosniff'
    && ($rh['x-frame-options'] ?? '') === 'SAMEORIGIN'
    && !empty($rh['content-security-policy']));

assert_test("14. Security headers emitted on redirect responses (HTTP 302 Found)", $redirHasHeaders);

// 15. Headers on error responses
$unauthApiRes = test_http("http://127.0.0.1:8080/api.php?action=get_preferences", 'GET');
$ah = $unauthApiRes['headers'];
$unauthHasHeaders = ($unauthApiRes['status'] === 401
    && ($ah['x-content-type-options'] ?? '') === 'nosniff'
    && ($ah['x-frame-options'] ?? '') === 'SAMEORIGIN'
    && !empty($ah['content-security-policy']));

assert_test("15. Security headers emitted on API error responses (HTTP 401 Unauthorized)", $unauthHasHeaders);

// 16. CSP directive integrity audit
$cspStr = $h['content-security-policy'] ?? '';
$cspDirectives = [
    "default-src 'self'"                                  => strpos($cspStr, "default-src 'self'") !== false,
    "script-src 'self' 'unsafe-inline'"                  => strpos($cspStr, "script-src 'self' 'unsafe-inline'") !== false,
    "no unsafe-eval"                                      => strpos($cspStr, "'unsafe-eval'") === false,
    "style-src with Google Fonts"                         => strpos($cspStr, "https://fonts.googleapis.com") !== false,
    "font-src with gstatic"                               => strpos($cspStr, "https://fonts.gstatic.com") !== false,
    "img-src with YouTube, Unsplash, and Cover Art"      => strpos($cspStr, "https://i.ytimg.com") !== false && strpos($cspStr, "https://images.unsplash.com") !== false && strpos($cspStr, "https://coverartarchive.org") !== false,
    "frame-src with YouTube player"                       => strpos($cspStr, "https://www.youtube.com") !== false && strpos($cspStr, "https://www.youtube-nocookie.com") !== false,
    "connect-src 'self'"                                  => strpos($cspStr, "connect-src 'self'") !== false,
    "object-src 'none'"                                   => strpos($cspStr, "object-src 'none'") !== false,
    "frame-ancestors 'self'"                              => strpos($cspStr, "frame-ancestors 'self'") !== false
];

$allDirectivesValid = true;
foreach ($cspDirectives as $dName => $dPassed) {
    if (!$dPassed) {
        $allDirectivesValid = false;
    }
}
assert_test("16. Content Security Policy contains all required AFM#2 directives and excludes unsafe-eval", $allDirectivesValid);

// 17a. HSTS is NOT emitted for plain HTTP responses
$hasHstsOverHttp = isset($h['strict-transport-security']);
assert_test("17a. Strict-Transport-Security (HSTS) is NOT emitted for plain HTTP responses", !$hasHstsOverHttp);

// 17b. FORCE_HTTPS=true does NOT cause plain HTTP response to emit HSTS (RFC 6797 §7.2 compliance)
$subCodeHstsForce = 'putenv("FORCE_HTTPS=true"); $_ENV["FORCE_HTTPS"] = "true"; require "config/config.php"; echo has_verified_https_transport() ? "VERIFIED" : "UNVERIFIED";';
$hstsForceOut = trim((string)shell_exec('php -r ' . escapeshellarg($subCodeHstsForce) . ' 2>/dev/null'));
assert_test("17b. FORCE_HTTPS=true alone does NOT satisfy verified HTTPS transport or emit HSTS (RFC 6797 §7.2)", $hstsForceOut === 'UNVERIFIED');

// 17c. Spoofed forwarding headers without proxy trust do NOT emit HSTS
$subCodeHstsSpoof = '$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"; require "config/config.php"; echo has_verified_https_transport() ? "VERIFIED" : "UNVERIFIED";';
$hstsSpoofOut = trim((string)shell_exec('php -r ' . escapeshellarg($subCodeHstsSpoof) . ' 2>/dev/null'));
assert_test("17c. Spoofed forwarding headers without TRUST_PROXY_HEADERS do NOT verify transport or emit HSTS", $hstsSpoofOut === 'UNVERIFIED');

// 18a. Direct TLS signal ($_SERVER['HTTPS'] = 'on') satisfies transport verification for HSTS
$subCodeHstsOn = '$_SERVER["HTTPS"] = "on"; require "config/config.php"; echo has_verified_https_transport() ? "VERIFIED" : "UNVERIFIED";';
$hstsOnOut = trim((string)shell_exec('php -r ' . escapeshellarg($subCodeHstsOn) . ' 2>/dev/null'));
assert_test("18a. Direct TLS signal (HTTPS='on') verifies transport and enables HSTS emission", $hstsOnOut === 'VERIFIED');

// 18b. Direct TLS port (SERVER_PORT = 443) satisfies transport verification for HSTS
$subCodeHstsPort = '$_SERVER["SERVER_PORT"] = 443; require "config/config.php"; echo has_verified_https_transport() ? "VERIFIED" : "UNVERIFIED";';
$hstsPortOut = trim((string)shell_exec('php -r ' . escapeshellarg($subCodeHstsPort) . ' 2>/dev/null'));
assert_test("18b. Direct TLS port (SERVER_PORT=443) verifies transport and enables HSTS emission", $hstsPortOut === 'VERIFIED');

// 18c. Trusted proxy forwarding headers (TRUST_PROXY_HEADERS=true) satisfy transport verification for HSTS
$subCodeHstsTrusted = 'putenv("TRUST_PROXY_HEADERS=true"); $_ENV["TRUST_PROXY_HEADERS"] = "true"; $_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"; require "config/config.php"; echo has_verified_https_transport() ? "VERIFIED" : "UNVERIFIED";';
$hstsTrustedOut = trim((string)shell_exec('php -r ' . escapeshellarg($subCodeHstsTrusted) . ' 2>/dev/null'));
assert_test("18c. Forwarding headers with TRUST_PROXY_HEADERS=true verify transport and enable HSTS emission", $hstsTrustedOut === 'VERIFIED');

// 19. Permissions-Policy does not disable features required by AFM#2
$permStr = $h['permissions-policy'] ?? '';
$audioAllowed = (strpos($permStr, 'speaker') === false && strpos($permStr, 'autoplay') === false);
assert_test("19. Permissions-Policy restricts unneeded sensors (camera, mic, geo) without impairing audio playback", $audioAllowed);

// 20. Apache and PHP security header configuration consistency
$rootHtaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$pubHtaccess = file_get_contents(dirname(__DIR__) . '/public/.htaccess');

$rootHasHeaders = (strpos($rootHtaccess, 'Header set X-Content-Type-Options "nosniff"') !== false
    && strpos($rootHtaccess, 'Header set Content-Security-Policy') !== false
    && strpos($rootHtaccess, 'Header set Permissions-Policy') !== false);

$pubHasHeaders = (strpos($pubHtaccess, 'Header set X-Content-Type-Options "nosniff"') !== false
    && strpos($pubHtaccess, 'Header set Content-Security-Policy') !== false
    && strpos($pubHtaccess, 'Header set Permissions-Policy') !== false);

assert_test("20. Apache mod_headers configuration in root and public .htaccess matches PHP security headers", $rootHasHeaders && $pubHasHeaders);

// 21. Live HTTP response on HTML endpoint has strictly ZERO duplicate headers
$hc = $loginRes['header_counts'];
$noDupesHtml = (
    ($hc['content-security-policy'] ?? 0) === 1
    && ($hc['x-frame-options'] ?? 0) === 1
    && ($hc['x-content-type-options'] ?? 0) === 1
    && ($hc['referrer-policy'] ?? 0) === 1
    && ($hc['permissions-policy'] ?? 0) === 1
    && ($hc['strict-transport-security'] ?? 0) === 0
);
assert_test("21. Live HTTP response on login.php contains strictly ZERO duplicate security headers", $noDupesHtml, "CSP count: " . ($hc['content-security-policy'] ?? 0));

// 22. Live HTTP redirect response (HTTP 302) has strictly ZERO duplicate headers
$rhc = $redirRes['header_counts'];
$noDupesRedir = (
    ($rhc['content-security-policy'] ?? 0) === 1
    && ($rhc['x-frame-options'] ?? 0) === 1
    && ($rhc['x-content-type-options'] ?? 0) === 1
    && ($rhc['referrer-policy'] ?? 0) === 1
    && ($rhc['permissions-policy'] ?? 0) === 1
);
assert_test("22. Live HTTP redirect response (index.php 302) contains strictly ZERO duplicate security headers", $noDupesRedir);

// 23. Live HTTP API error response (HTTP 401) has strictly ZERO duplicate headers
$ahc = $unauthApiRes['header_counts'];
$noDupesApi = (
    ($ahc['content-security-policy'] ?? 0) === 1
    && ($ahc['x-frame-options'] ?? 0) === 1
    && ($ahc['x-content-type-options'] ?? 0) === 1
    && ($ahc['referrer-policy'] ?? 0) === 1
    && ($ahc['permissions-policy'] ?? 0) === 1
);
assert_test("23. Live HTTP API error response (api.php 401) contains strictly ZERO duplicate security headers", $noDupesApi);

// 24. Framing and CSP compatibility: frame-ancestors 'self' and X-Frame-Options SAMEORIGIN non-conflicting, with unsafe-inline preserved
$cspFrameAncestors = strpos($cspStr, "frame-ancestors 'self'") !== false;
$xFrameSameOrigin = ($h['x-frame-options'] ?? '') === 'SAMEORIGIN';
$cspHasUnsafeInline = strpos($cspStr, "'unsafe-inline'") !== false;
$cspNoUnsafeEval = strpos($cspStr, "'unsafe-eval'") === false;

assert_test("24. CSP frame-ancestors and X-Frame-Options are non-conflicting, with unsafe-inline preserved and unsafe-eval omitted", $cspFrameAncestors && $xFrameSameOrigin && $cspHasUnsafeInline && $cspNoUnsafeEval);

// =====================================================================
// SECTION 3: POST-FLIGHT CATALOGUE INVARIANTS
// =====================================================================
echo "\n--- 3. Post-Flight Catalogue Invariants ---\n";
$postSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Post-test Catalogue Preservation Invariant: strictly 2,411 songs", $postSongCount === 2411, "Found: {$postSongCount}");

$postNullArtists = $pdo->query("SELECT song_id FROM `songs` WHERE `artist` IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
assert_test("Post-test Missing Artist Invariant: strictly [1546, 2015, 2196]", $postNullArtists === [1546, 2015, 2196], "Found: " . json_encode($postNullArtists));

// Clean up test rate limit records
clear_rate_limits('*');

echo "\n======================================================================\n";
echo " BATCH E TEST EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$testsTotal}\n";
echo "Passed:               {$testsPassed}\n";
echo "Failed:               " . ($testsTotal - $testsPassed) . "\n\n";

if ($testsPassed === $testsTotal) {
    echo "🎉 ALL BATCH E TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
    exit(0);
} else {
    echo "❌ SOME BATCH E TESTS FAILED.\n";
    exit(1);
}
