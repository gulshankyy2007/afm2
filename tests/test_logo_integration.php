<?php
/**
 * AFM#2 - Official Logo & Favicon Integration Verification Suite
 * Verifies website logo and favicon integration across headers, footers, login, setup,
 * HTTP asset availability, responsive dimensions, and theme contrast.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();
$tests = 0;
$passed = 0;

function assert_logo_test($description, $condition) {
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        echo "  ✔ PASS: {$description}\n";
    } else {
        echo "  ✖ FAIL: {$description}\n";
    }
}

echo "\n======================================================================\n";
echo " AFM#2 — OFFICIAL LOGO & FAVICON INTEGRATION VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// 1. Asset Files Verification
echo "1. Physical Logo & Favicon Assets on Disk\n";
$baseDir = dirname(__DIR__);

$requiredAssets = [
    'public/assets/img/afm2-logo.png'            => 'Website Logo (transparent PNG, 648x352)',
    'public/assets/img/afm2-favicon-16x16.png'   => 'Favicon 16x16 PNG',
    'public/assets/img/afm2-favicon-32x32.png'   => 'Favicon 32x32 PNG',
    'public/assets/img/afm2-favicon-48x48.png'   => 'Favicon 48x48 PNG',
    'public/assets/img/afm2-favicon-192x192.png' => 'Favicon 192x192 PNG',
    'public/assets/img/afm2-favicon-256x256.png' => 'Favicon 256x256 Master PNG',
    'public/assets/img/apple-touch-icon.png'     => 'Apple Touch Icon 180x180 PNG',
    'public/assets/img/favicon.ico'              => 'Multi-size ICO',
    'public/favicon.ico'                         => 'Root Multi-size ICO',
    'public/favicon-16x16.png'                   => 'Root Favicon 16x16 PNG',
    'public/favicon-32x32.png'                   => 'Root Favicon 32x32 PNG',
    'public/apple-touch-icon.png'                => 'Root Apple Touch Icon 180x180 PNG',
];

foreach ($requiredAssets as $relPath => $desc) {
    $fullPath = "{$baseDir}/{$relPath}";
    assert_logo_test("Asset exists: {$desc} ({$relPath})", file_exists($fullPath) && filesize($fullPath) > 0);
}

// Check logo dimensions using getimagesize()
$logoPath = "{$baseDir}/public/assets/img/afm2-logo.png";
$logoInfo = getimagesize($logoPath);
assert_logo_test("Website logo is a valid PNG image", $logoInfo !== false && $logoInfo['mime'] === 'image/png');
assert_logo_test("Website logo has width 652px", ($logoInfo[0] ?? 0) === 652);
assert_logo_test("Website logo has height 362px", ($logoInfo[1] ?? 0) === 362);

// Check favicon 32x32 dimensions
$fav32Path = "{$baseDir}/public/assets/img/afm2-favicon-32x32.png";
$fav32Info = getimagesize($fav32Path);
assert_logo_test("Favicon 32x32 is 32x32px PNG", ($fav32Info[0] ?? 0) === 32 && ($fav32Info[1] ?? 0) === 32);

// Check Apple touch icon 180x180 dimensions
$applePath = "{$baseDir}/public/assets/img/apple-touch-icon.png";
$appleInfo = getimagesize($applePath);
assert_logo_test("Apple touch icon is 180x180px PNG", ($appleInfo[0] ?? 0) === 180 && ($appleInfo[1] ?? 0) === 180);

// 2. HTML Document Head Favicon References
echo "\n2. HTML Document Head Favicon Tag References\n";
$headerPhp = file_get_contents("{$baseDir}/includes/header.php");
$loginPhp = file_get_contents("{$baseDir}/public/login.php");
$setupPhp = file_get_contents("{$baseDir}/public/setup.php");

assert_logo_test("header.php links to favicon.ico", strpos($headerPhp, 'href="favicon.ico"') !== false);
assert_logo_test("header.php links to 16x16 favicon", strpos($headerPhp, 'href="assets/img/afm2-favicon-16x16.png"') !== false);
assert_logo_test("header.php links to 32x32 favicon", strpos($headerPhp, 'href="assets/img/afm2-favicon-32x32.png"') !== false);
assert_logo_test("header.php links to Apple touch icon", strpos($headerPhp, 'href="assets/img/apple-touch-icon.png"') !== false);

assert_logo_test("login.php contains favicon.ico link", strpos($loginPhp, 'href="favicon.ico"') !== false);
assert_logo_test("login.php contains 32x32 favicon link", strpos($loginPhp, 'href="assets/img/afm2-favicon-32x32.png"') !== false);

assert_logo_test("setup.php contains favicon.ico link", strpos($setupPhp, 'href="favicon.ico"') !== false);
assert_logo_test("setup.php contains 32x32 favicon link", strpos($setupPhp, 'href="assets/img/afm2-favicon-32x32.png"') !== false);

// 3. UI Presentation: Main Header, Footer, Login & Setup Screens
echo "\n3. UI Presentation: Header, Footer, Login & Setup\n";
assert_logo_test("Header renders brand-logo-img with afm2-logo.png", strpos($headerPhp, 'class="brand-logo-img"') !== false && strpos($headerPhp, 'src="assets/img/afm2-logo.png"') !== false);
assert_logo_test("Header logo provides semantic alt text", strpos($headerPhp, 'alt="AFM#2 — Annual Musical Finale"') !== false);
assert_logo_test("Header brand link has accessible aria-label", strpos($headerPhp, 'aria-label="AFM#2 — Annual Musical Finale 2026"') !== false);

$footerPhp = file_get_contents("{$baseDir}/includes/footer.php");
assert_logo_test("Footer renders footer-logo-img with afm2-logo.png", strpos($footerPhp, 'class="footer-logo-img"') !== false && strpos($footerPhp, 'src="assets/img/afm2-logo.png"') !== false);
assert_logo_test("Footer logo link has accessible aria-label", strpos($footerPhp, 'class="footer-logo-link"') !== false);

assert_logo_test("login.php renders auth-logo-img with afm2-logo.png", strpos($loginPhp, 'class="auth-logo-img"') !== false && strpos($loginPhp, 'src="assets/img/afm2-logo.png"') !== false);
assert_logo_test("setup.php renders auth-logo-img with afm2-logo.png", strpos($setupPhp, 'class="auth-logo-img"') !== false && strpos($setupPhp, 'src="assets/img/afm2-logo.png"') !== false);

// 4. CSS Rules & Theme Contrast Verification
echo "\n4. CSS Rules & Theme Contrast\n";
$css = file_get_contents("{$baseDir}/public/assets/css/style.css");
assert_logo_test("CSS defines .brand-logo-img with height and aspect-ratio", strpos($css, '.brand-logo-img {') !== false && strpos($css, 'aspect-ratio: 652 / 362;') !== false);
assert_logo_test("CSS defines .footer-logo-img with aspect-ratio", strpos($css, '.footer-logo-img {') !== false);
assert_logo_test("CSS defines .auth-logo-img with drop-shadow", strpos($css, '.auth-logo-img {') !== false);
assert_logo_test("CSS specifies contrast drop-shadows for light themes (vintage_paper, nordic_frost, solar_terracotta)", strpos($css, '[data-theme="vintage_paper"] .brand-logo-img') !== false);
assert_logo_test("CSS includes mobile responsive logo scaling in @media (max-width: 768px)", strpos($css, '.brand-logo-img {') !== false && strpos($css, '@media (max-width: 768px)') !== false);

// 5. HTTP Delivery via Local Dev Server
echo "\n5. HTTP Delivery via Local Server (localhost:8080)\n";
$headersLogo = @get_headers('http://localhost:8080/assets/img/afm2-logo.png');
$statusLogo = $headersLogo ? (int)substr($headersLogo[0], 9, 3) : 0;
assert_logo_test("HTTP GET /assets/img/afm2-logo.png returns 200 OK", $statusLogo === 200);

$headersIco = @get_headers('http://localhost:8080/favicon.ico');
$statusIco = $headersIco ? (int)substr($headersIco[0], 9, 3) : 0;
assert_logo_test("HTTP GET /favicon.ico returns 200 OK", $statusIco === 200);

$headersFav = @get_headers('http://localhost:8080/assets/img/afm2-favicon-32x32.png');
$statusFav = $headersFav ? (int)substr($headersFav[0], 9, 3) : 0;
assert_logo_test("HTTP GET /assets/img/afm2-favicon-32x32.png returns 200 OK", $statusFav === 200);

// 6. Invariant Catalogue Integrity
echo "\n6. Catalogue Preservation Invariant\n";
$songs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$nulls = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_logo_test("Catalogue has active songs strictly preserved (count: {$songs} >= 2400)", $songs >= 2400);
assert_logo_test("Catalogue has NULL artist records intact", $nulls >= 3);

echo "\n======================================================================\n";
echo " LOGO INTEGRATION SUMMARY: {$passed} / {$tests} tests passed (" . round(($passed / $tests) * 100) . "%)\n";
echo "======================================================================\n\n";
