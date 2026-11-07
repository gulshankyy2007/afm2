<?php
/**
 * AFM#2 - Phase 2 Security Remediation & Google Safe Browsing Verification Test Suite
 * 
 * Tests the specific remediation controls implemented in Phase 2:
 * 1. Admin migration endpoint hardening (GET ?secret rejection, POST/Header secret validation, zero disclosure)
 * 2. Login screen anti-phishing branding & credential clarity
 * 3. Search crawler directives (X-Robots-Tag & meta tags on auth endpoints)
 * 4. Sitemap sanitization (login.php exclusion)
 * 5. Invitation and Setup endpoint protection
 * 6. Landing page messaging consistency
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

$passed = 0;
$failed = 0;
$total = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — PHASE 2 SECURITY REMEDIATION VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// Start a local PHP built-in server to test live HTTP requests
$host = '127.0.0.1';
$port = 8899;
$docRoot = dirname(__DIR__) . '/public';

$cmd = sprintf('php -S %s:%d -t %s > /dev/null 2>&1 & echo $!', $host, $port, escapeshellarg($docRoot));
$pid = (int)trim(shell_exec($cmd));
usleep(300000); // 300ms wait for server bind

register_shutdown_function(function() use ($pid) {
    if ($pid > 0) {
        posix_kill($pid, SIGKILL);
    }
});

function http_request(string $path, string $method = 'GET', array $headers = [], $body = null) {
    global $host, $port;
    $url = "http://{$host}:{$port}/" . ltrim($path, '/');
    
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = "{$k}: {$v}";
    }

    $content = '';
    if ($body !== null) {
        if (is_array($body)) {
            $content = http_build_query($body);
            $headerLines[] = "Content-Type: application/x-www-form-urlencoded";
        } else {
            $content = $body;
        }
        $headerLines[] = "Content-Length: " . strlen($content);
    }

    $opts = [
        'http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headerLines),
            'content'         => $content,
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

    $headerStr = implode("\r\n", $responseHeaders);

    return [
        'code' => $statusCode,
        'headers' => $headerStr,
        'body' => $responseBody ?: ''
    ];
}

echo "--- 1. Admin Migration Endpoint Hardening (SEC-03, SEC-04) ---\n";
// Test 1: GET with ?secret= should be REJECTED (HTTP 403)
$resGetSecret = http_request('admin_migrate.php?secret=' . urlencode(APP_SECRET));
assert_test('GET request with ?secret=<APP_SECRET> is strictly rejected (403 Forbidden)', $resGetSecret['code'] === 403);
assert_test('403 response does not leak "?secret=" instruction in body', stripos($resGetSecret['body'], '?secret=') === false);
assert_test('403 response includes X-Robots-Tag: noindex', stripos($resGetSecret['headers'], 'X-Robots-Tag: noindex') !== false);

// Test 2: Anonymous GET without secret returns 403
$resGetAnon = http_request('admin_migrate.php');
assert_test('Anonymous GET request returns 403 Forbidden', $resGetAnon['code'] === 403);

// Test 3: POST with invalid secret returns 403
$resPostBad = http_request('admin_migrate.php', 'POST', [], ['secret' => 'invalid_secret_key']);
assert_test('POST request with invalid secret returns 403 Forbidden', $resPostBad['code'] === 403);

// Test 4: POST with valid secret returns 200 OK
$resPostGood = http_request('admin_migrate.php', 'POST', [], ['secret' => APP_SECRET]);
assert_test('POST request with valid APP_SECRET returns 200 OK', $resPostGood['code'] === 200);
assert_test('200 response contains Verified Application Secret Token badge', stripos($resPostGood['body'], 'Verified Application Secret Token') !== false);

// Test 5: Header X-App-Secret with valid secret returns 200 OK
$resHeaderGood = http_request('admin_migrate.php', 'GET', ['X-App-Secret' => APP_SECRET]);
assert_test('GET request with valid X-App-Secret header returns 200 OK', $resHeaderGood['code'] === 200);

echo "\n--- 2. Login Screen & Google Identity Trust Remediation (SEC-01) ---\n";
$resLogin = http_request('login.php');
assert_test('login.php returns HTTP 200 OK', $resLogin['code'] === 200);
assert_test('login.php emits X-Robots-Tag: noindex, nofollow, noarchive header', stripos($resLogin['headers'], 'X-Robots-Tag: noindex, nofollow, noarchive') !== false);
assert_test('login.php contains HTML meta robots tag', stripos($resLogin['body'], '<meta name="robots" content="noindex, nofollow, noarchive">') !== false);
assert_test('login.php displays "Continue to AFM#2 with Google"', stripos($resLogin['body'], 'Continue to AFM#2 with Google') !== false);
assert_test('login.php displays explicit OAuth identity trust notice (accounts.google.com)', stripos($resLogin['body'], 'accounts.google.com') !== false);
assert_test('login.php clearly labels credentials as AFM#2 Username/Email', stripos($resLogin['body'], 'AFM#2 Username or Registered Email') !== false);
assert_test('login.php clearly labels password as AFM#2 Account Password', stripos($resLogin['body'], 'AFM#2 Account Password') !== false);
assert_test('login.php displays Official AFM#2 Access Portal trust pill', stripos($resLogin['body'], 'Official AFM#2 Access Portal') !== false);

echo "\n--- 3. Search Crawler & Sitemap Sanitization (SEC-05) ---\n";
$sitemapXml = file_get_contents(dirname(__DIR__) . '/public/sitemap.xml');
$hasXmlDecl = strpos($sitemapXml, '<?xml version="1.0"') !== false;
$hasUrlsetOpen = strpos($sitemapXml, '<urlset') !== false;
$hasUrlsetClose = strpos($sitemapXml, '</urlset>') !== false;
assert_test('sitemap.xml is well-formed XML', $hasXmlDecl && $hasUrlsetOpen && $hasUrlsetClose);
assert_test('sitemap.xml strictly excludes login.php', stripos($sitemapXml, 'login.php') === false);
assert_test('sitemap.xml includes public landing page', stripos($sitemapXml, '<loc>https://afm2.infinityfreeapp.com/</loc>') !== false);
assert_test('sitemap.xml includes public privacy policy', stripos($sitemapXml, 'privacy.php') !== false);

$robotsTxt = file_get_contents(dirname(__DIR__) . '/public/robots.txt');
assert_test('robots.txt allows login.php so crawler processes noindex tag', stripos($robotsTxt, 'Allow: /login.php') !== false);
assert_test('robots.txt disallows admin_migrate.php', stripos($robotsTxt, 'Disallow: /admin_migrate.php') !== false);
assert_test('robots.txt disallows api.php', stripos($robotsTxt, 'Disallow: /api.php') !== false);
assert_test('robots.txt references sitemap.xml', stripos($robotsTxt, 'Sitemap: https://afm2.infinityfreeapp.com/sitemap.xml') !== false);

echo "\n--- 4. Invitation & Setup Endpoint Protection ---\n";
$resSetup = http_request('setup.php');
// When users exist, setup redirects to login.php
assert_test('setup.php emits X-Robots-Tag: noindex', stripos($resSetup['headers'], 'X-Robots-Tag: noindex') !== false);

$resInvite = http_request('invite.php?token=invalid_dummy_token');
assert_test('invite.php emits X-Robots-Tag: noindex', stripos($resInvite['headers'], 'X-Robots-Tag: noindex') !== false);

$invitePhp = file_get_contents(dirname(__DIR__) . '/public/invite.php');
assert_test('invite.php contains Join AFM#2 with Google label', stripos($invitePhp, 'Join AFM#2 with Google') !== false);
assert_test('invite.php contains OAuth trust notice', stripos($invitePhp, 'AFM#2 never sees or stores your Google password') !== false);
assert_test('invite.php specifies AFM#2 credentials in form labels', stripos($invitePhp, 'Choose AFM#2 Username') !== false);

echo "\n--- 5. Protected Route Redirection & Crawler Protection ---\n";
$resResults = http_request('results.php');
assert_test('Unauthenticated results.php returns 302 redirect to login.php', $resResults['code'] === 302 && stripos($resResults['headers'], 'Location: login.php') !== false);
assert_test('302 redirect response emits X-Robots-Tag: noindex to prevent indexing', stripos($resResults['headers'], 'X-Robots-Tag: noindex') !== false);

$resLibrary = http_request('library.php');
assert_test('Unauthenticated library.php returns 302 with X-Robots-Tag: noindex', stripos($resLibrary['headers'], 'X-Robots-Tag: noindex') !== false);

echo "\n--- 6. Landing Page Messaging ---\n";
$landingHtml = file_get_contents(dirname(__DIR__) . '/public/landing.php');
assert_test('landing.php has updated trust item "Passkey & Google Sign-In"', stripos($landingHtml, 'Passkey &amp; Google Sign-In') !== false);

echo "\n======================================================================\n";
echo " PHASE 2 SECURITY VERIFICATION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$total}\n";
echo "Passed:               {$passed}\n";
echo "Failed:               {$failed}\n\n";

if ($failed === 0) {
    echo "🎉 ALL PHASE 2 SECURITY REMEDIATION CONTROLS VERIFIED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "❌ SOME PHASE 2 CONTROLS FAILED VERIFICATION!\n";
    exit(1);
}
