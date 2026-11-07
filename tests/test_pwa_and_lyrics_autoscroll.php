<?php
/**
 * AFM#2 - PWA Standalone & Lyrics Auto-Scrolling Verification Suite
 * Tests:
 * 1. PWA Manifest structure, MIME configuration, start_url, and icon assets.
 * 2. Service Worker installation, activation, and passthrough handlers.
 * 3. HTML header metadata and Service Worker registration.
 * 4. Synced lyrics auto-scroll logic, container-relative math, and user interaction handling.
 * 5. CSS overflow-y, flexbox min-height, and container containment rules.
 * 6. Catalogue baseline and missing artist invariants.
 */

declare(strict_types=1);

ob_start();

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — PWA STANDALONE & SYNCED LYRICS AUTO-SCROLL TEST SUITE\n";
echo "======================================================================\n\n";

$projectRoot = dirname(__DIR__);

// --- Group 1: Web App Manifest & PWA Configuration ---
echo "--- Group 1: PWA Web App Manifest & App Metadata ---\n";

$manifestPath = $projectRoot . '/public/manifest.json';
assert_test("public/manifest.json exists", file_exists($manifestPath));

$manifestData = json_decode((string)file_get_contents($manifestPath), true);
assert_test("manifest.json is valid JSON", is_array($manifestData));

assert_test("Manifest specifies standalone display mode", ($manifestData['display'] ?? '') === 'standalone', "Found: " . ($manifestData['display'] ?? 'none'));
assert_test("Manifest includes display_override with standalone", in_array('standalone', $manifestData['display_override'] ?? []), "Found: " . json_encode($manifestData['display_override'] ?? []));
assert_test("Manifest start_url is index.php", ($manifestData['start_url'] ?? '') === 'index.php', "Found: " . ($manifestData['start_url'] ?? 'none'));
assert_test("Manifest scope is ./", ($manifestData['scope'] ?? '') === './', "Found: " . ($manifestData['scope'] ?? 'none'));
assert_test("Manifest has dark background_color (#0c0e14)", ($manifestData['background_color'] ?? '') === '#0c0e14', "Found: " . ($manifestData['background_color'] ?? 'none'));
assert_test("Manifest has dark theme_color (#0c0e14)", ($manifestData['theme_color'] ?? '') === '#0c0e14', "Found: " . ($manifestData['theme_color'] ?? 'none'));

$icons = $manifestData['icons'] ?? [];
$has192 = false;
$has512 = false;
$hasMaskable = false;

foreach ($icons as $icon) {
    if (($icon['sizes'] ?? '') === '192x192') $has192 = true;
    if (($icon['sizes'] ?? '') === '512x512') $has512 = true;
    if (str_contains($icon['purpose'] ?? '', 'maskable')) $hasMaskable = true;
    
    // Verify physical file exists
    $iconFile = $projectRoot . '/public/' . ltrim($icon['src'], '/');
    assert_test("Icon asset exists: {$icon['src']}", file_exists($iconFile) && filesize($iconFile) > 1000);
}

assert_test("Manifest includes 192x192 icon", $has192);
assert_test("Manifest includes 512x512 icon", $has512);
assert_test("Manifest includes maskable icon for Android adaptive launcher", $hasMaskable);

// Root mirror
assert_test("Root manifest.json exists", file_exists($projectRoot . '/manifest.json'));
assert_test("Root manifest.webmanifest exists", file_exists($projectRoot . '/manifest.webmanifest'));

echo "\n--- Group 2: Service Worker & Installability ---\n";

$swPath = $projectRoot . '/public/sw.js';
assert_test("public/sw.js exists", file_exists($swPath));

$swContent = (string)file_get_contents($swPath);
assert_test("Service Worker handles 'install' event with skipWaiting", str_contains($swContent, "'install'") && str_contains($swContent, "skipWaiting"));
assert_test("Service Worker handles 'activate' event with clients.claim", str_contains($swContent, "'activate'") && str_contains($swContent, "clients.claim"));
assert_test("Service Worker handles 'fetch' event with network passthrough", str_contains($swContent, "'fetch'") && (str_contains($swContent, "fetch(event.request)") || str_contains($swContent, "fetch(request")));
assert_test("Root sw.js mirror exists", file_exists($projectRoot . '/sw.js'));

echo "\n--- Group 3: HTML Integration & Header Metadata ---\n";

$headerContent = (string)file_get_contents($projectRoot . '/includes/header.php');
assert_test("header.php links manifest.json with crossorigin='use-credentials'", str_contains($headerContent, '<link rel="manifest" href="manifest.json" crossorigin="use-credentials">'));
assert_test("header.php specifies theme-color meta tag", str_contains($headerContent, '<meta name="theme-color" content="#0c0e14">'));
assert_test("header.php specifies mobile-web-app-capable", str_contains($headerContent, '<meta name="mobile-web-app-capable" content="yes">'));
assert_test("header.php specifies apple-mobile-web-app-capable", str_contains($headerContent, '<meta name="apple-mobile-web-app-capable" content="yes">'));

$footerContent = (string)file_get_contents($projectRoot . '/includes/footer.php');
assert_test("footer.php registers sw.js", str_contains($footerContent, "navigator.serviceWorker.register('sw.js'"));

$loginContent = (string)file_get_contents($projectRoot . '/public/login.php');
assert_test("login.php links manifest.json with crossorigin='use-credentials'", str_contains($loginContent, '<link rel="manifest" href="manifest.json" crossorigin="use-credentials">'));
assert_test("login.php registers sw.js", str_contains($loginContent, "navigator.serviceWorker.register('sw.js'"));

echo "\n--- Group 4: Web Server Apache .htaccess PWA Access Controls ---\n";

$htaccessRoot = (string)file_get_contents($projectRoot . '/.htaccess');
assert_test(".htaccess explicitly permits manifest.json", str_contains($htaccessRoot, '<Files "manifest.json">'));
assert_test(".htaccess explicitly permits manifest.webmanifest", str_contains($htaccessRoot, '<Files "manifest.webmanifest">'));
assert_test(".htaccess configures application/manifest+json MIME type", str_contains($htaccessRoot, 'AddType application/manifest+json .webmanifest'));
assert_test(".htaccess sets no-cache on sw.js", str_contains($htaccessRoot, '<Files "sw.js">') && str_contains($htaccessRoot, 'no-cache, no-store, must-revalidate, max-age=0'));

$htaccessPublic = (string)file_get_contents($projectRoot . '/public/.htaccess');
assert_test("public/.htaccess configures application/manifest+json MIME type", str_contains($htaccessPublic, 'AddType application/manifest+json'));
assert_test("public/.htaccess sets no-cache on sw.js", str_contains($htaccessPublic, '<Files "sw.js">') && str_contains($htaccessPublic, 'no-cache, no-store, must-revalidate, max-age=0'));

echo "\n--- Group 5: Synced Lyrics Auto-Scrolling Implementation ---\n";

$appJs = (string)file_get_contents($projectRoot . '/public/assets/js/app.js');
assert_test("app.js contains container-relative offset calculation helper", str_contains($appJs, 'getElementOffsetTopRelativeTo'));
assert_test("app.js attaches non-intrusive manual scroll detection", str_contains($appJs, '_hasManualScrollListener') && str_contains($appJs, '_lastUserScrollTime'));
assert_test("app.js pauses auto-scroll when user manually scrolls (4s window)", str_contains($appJs, 'timeSinceUserScroll < 4000'));
assert_test("app.js centers active line within container using scrollTo", str_contains($appJs, 'body.scrollTo({ top: targetScrollTop'));
assert_test("app.js enforces threshold check to prevent micro-jitter", str_contains($appJs, 'Math.abs(body.scrollTop - targetScrollTop) > 6'));
assert_test("app.js centers line immediately on timestamp seek click", str_contains($appJs, 'body._lastUserScrollTime = 0'));
assert_test("app.js resumes and centers line immediately on auto-scroll toggle ON", str_contains($appJs, 'updateActiveSyncedLyricLine(panel, player.getCurrentTime(), true)'));
assert_test("app.js includes cross-origin YouTube postMessage listener fallback", str_contains($appJs, "event.origin.includes('youtube.com')") && str_contains($appJs, "infoDelivery"));

$auditionsPhp = (string)file_get_contents($projectRoot . '/public/auditions.php');
assert_test("auditions.php sets YouTube embed origin to get_base_url()", str_contains($auditionsPhp, '$appOrigin = get_base_url();'));
assert_test("auditions.php generates embed query parameters with & rather than &amp;", str_contains($auditionsPhp, '?enablejsapi=1&playsinline=1&rel=0&origin='));

echo "\n--- Group 6: CSS Flexbox & Overflow Guarantees ---\n";

$styleCss = (string)file_get_contents($projectRoot . '/public/assets/css/style.css');
assert_test("style.css .lyrics-box enforces overflow: hidden", str_contains($styleCss, "max-height: 380px;\n    overflow: hidden;"));
assert_test("style.css .lyrics-body enforces min-height: 0 for flexbox scrolling", str_contains($styleCss, "min-height: 0;\n    max-height: 100%;\n    overflow-y: auto;"));

$mobileCss = (string)file_get_contents($projectRoot . '/public/assets/css/mobile.css');
assert_test("mobile.css .lyrics-body enforces min-height: 0 and height: auto", str_contains($mobileCss, "height: auto !important;\n        max-height: 100% !important;\n        overflow-y: auto !important;"));

echo "\n--- Group 7: Catalogue & Tournament Invariants ---\n";

require_once $projectRoot . '/config/db.php';
$pdo = get_db_connection();

$hasMasterStarter = (bool)$pdo->query("SHOW TABLES LIKE 'master_starter_songs'")->fetchColumn();
if ($hasMasterStarter) {
    $songCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
    assert_test("Catalogue Baseline Invariant: strictly 2,411 songs", $songCount === 2411, "Found: {$songCount}");

    $nullArtists = $pdo->query("SELECT `source_song_id` FROM `master_starter_songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `source_song_id` ASC")->fetchAll(PDO::FETCH_COLUMN);
    $nullArtists = array_map('intval', $nullArtists);
    assert_test("Missing Artist Invariant: strictly [1546, 2015, 2196]", $nullArtists === [1546, 2015, 2196], "Found: " . json_encode($nullArtists));
} else {
    $songCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
    assert_test("Catalogue Baseline Invariant: strictly 2,411 songs", $songCount === 2411, "Found: {$songCount}");

    $nullArtists = $pdo->query("SELECT song_id FROM `songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $nullArtists = array_map('intval', $nullArtists);
    assert_test("Missing Artist Invariant: strictly [1546, 2015, 2196]", $nullArtists === [1546, 2015, 2196], "Found: " . json_encode($nullArtists));
}

echo "\n--- Group 8: Timezone & IST Consistency Verification ---\n";

require_once $projectRoot . '/includes/db_helper.php';

$phpTimezone = date_default_timezone_get();
assert_test("PHP default timezone is centrally set to Asia/Kolkata", $phpTimezone === 'Asia/Kolkata', "Got: {$phpTimezone}");

$sessionTz = (string)$pdo->query("SELECT @@session.time_zone")->fetchColumn();
assert_test("MySQL session timezone is initialized to +05:30", $sessionTz === '+05:30', "Got: {$sessionTz}");

$mysqlNow = (string)$pdo->query("SELECT NOW()")->fetchColumn();
$phpNow = date('Y-m-d H:i:s');
// Verify MySQL NOW() and PHP date('Y-m-d H:i:s') differ by at most 2 seconds
$diffSeconds = abs(strtotime($mysqlNow) - strtotime($phpNow));
assert_test("MySQL NOW() and PHP date() are synchronized in IST within 2s", $diffSeconds <= 2, "Diff: {$diffSeconds}s (MySQL: {$mysqlNow}, PHP: {$phpNow})");

// Verify format_local_timestamp correctly handles both raw IST strings and ISO UTC strings
$sampleIso = '2026-11-07T21:25:00Z';
$formattedFromIso = format_local_timestamp($sampleIso, 'Y-m-d H:i');
assert_test("format_local_timestamp converts ISO 8601 UTC to IST (+5:30)", $formattedFromIso === '2026-11-08 02:55', "Got: {$formattedFromIso}");

$sampleIstStr = '2026-11-08 02:55:00';
$formattedFromIst = format_local_timestamp($sampleIstStr, 'Y-m-d H:i');
assert_test("format_local_timestamp preserves raw IST timestamp without double-shifting", $formattedFromIst === '2026-11-08 02:55', "Got: {$formattedFromIst}");

assert_test("format_local_timestamp returns 'Never' for empty/null timestamps", format_local_timestamp(null) === 'Never' && format_local_timestamp('') === 'Never');

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passCount} / {$testCount} tests passed.\n";
if ($failCount === 0) {
    echo " RESULT: ALL PWA & LYRICS AUTO-SCROLL TESTS PASSED SUCCESSFULLY! (100%)\n";
} else {
    echo " RESULT: {$failCount} TEST(S) FAILED.\n";
    exit(1);
}
echo "======================================================================\n";
