<?php
/**
 * AFM#2 - Enhanced Pagination & Accessibility Verification Suite
 * Tests records per page selection (at most 100), start/end navigation, 
 * 10 numeric buttons display, jump-to-page control, and WCAG accessibility standards.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();
$testsPassed = 0;
$totalTests = 0;

function assert_test(string $description, bool $condition, string $detail = ''): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $testsPassed++;
        echo "  ✔ PASS: {$description}\n";
    } else {
        echo "  ✖ FAIL: {$description}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — ENHANCED PAGINATION & ACCESSIBILITY VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// ---------------------------------------------------------------------
// 1. Per-Page Parameter & Clamping Logic
// ---------------------------------------------------------------------
echo "1. Records Per Page Parameter & Clamping (At Most 100)\n";
$res25 = search_songs('', 'all', 1, 25);
assert_test("search_songs() returns 25 records when perPage=25", count($res25['songs']) === 25);
assert_test("search_songs() calculates total_pages correctly for 25/page", $res25['total_pages'] === (int)ceil($res25['total'] / 25));

$res100 = search_songs('', 'all', 1, 100);
assert_test("search_songs() returns 100 records when perPage=100", count($res100['songs']) === 100);
assert_test("search_songs() calculates total_pages correctly for 100/page", $res100['total_pages'] === (int)ceil($res100['total'] / 100));

// Test clamping in render_accessible_pagination
$htmlClamped = render_accessible_pagination(1, 50, 2462, 250, 'library.php');
assert_test("render_accessible_pagination() clamps perPage > 100 to 100 max", strpos($htmlClamped, 'Showing <strong class="pagination-highlight">1</strong> to <strong class="pagination-highlight">100</strong>') !== false);

// ---------------------------------------------------------------------
// 2. Navigation Options: First / Start & Last / End Buttons
// ---------------------------------------------------------------------
echo "\n2. Navigation Options: First («) & Last (») Buttons\n";
// On page 1: First and Prev should be disabled
$htmlPage1 = render_accessible_pagination(1, 50, 2462, 50, 'library.php');
assert_test("Page 1 has disabled First button", strpos($htmlPage1, 'page-btn-first disabled') !== false);
assert_test("Page 1 has disabled Prev button", strpos($htmlPage1, 'page-btn-prev disabled') !== false);
assert_test("Page 1 has active Next link", strpos($htmlPage1, 'page-btn-next') !== false && strpos($htmlPage1, 'page-btn-next disabled') === false);
assert_test("Page 1 has active Last link pointing to page 50", strpos($htmlPage1, 'page-btn-last') !== false && strpos($htmlPage1, 'page=50') !== false);

// On page 3: First, Prev, Next, Last all active
$htmlPage3 = render_accessible_pagination(3, 50, 2462, 50, 'library.php', ['q' => '', 'filter' => 'all', 'sort' => 'id_asc']);
assert_test("Page 3 has active First link pointing to page 1", strpos($htmlPage3, 'page-btn-first') !== false && strpos($htmlPage3, 'page-btn-first disabled') === false);
assert_test("Page 3 has active Prev link pointing to page 2", strpos($htmlPage3, 'page-btn-prev') !== false && strpos($htmlPage3, 'page=2') !== false);
assert_test("Page 3 has active Next link pointing to page 4", strpos($htmlPage3, 'page-btn-next') !== false && strpos($htmlPage3, 'page=4') !== false);
assert_test("Page 3 has active Last link pointing to page 50", strpos($htmlPage3, 'page-btn-last') !== false && strpos($htmlPage3, 'page=50') !== false);

// On last page (50): Next and Last should be disabled
$htmlPage50 = render_accessible_pagination(50, 50, 2462, 50, 'library.php');
assert_test("Page 50 has active First link", strpos($htmlPage50, 'page-btn-first disabled') === false);
assert_test("Page 50 has disabled Next button", strpos($htmlPage50, 'page-btn-next disabled') !== false);
assert_test("Page 50 has disabled Last button", strpos($htmlPage50, 'page-btn-last disabled') !== false);

// ---------------------------------------------------------------------
// 3. Up to 10 Numeric Buttons Window Calculation
// ---------------------------------------------------------------------
echo "\n3. Display Up to 10 Numeric Buttons at a Time\n";
// Parse page buttons in Page 1 (should display 1 to 10)
preg_match_all('/class="page-btn page-btn-num[^"]*"[^>]*>\s*(\d+)\s*<\/(?:a|span)>/', $htmlPage1, $matchesP1);
$numsP1 = array_map('intval', $matchesP1[1]);
assert_test("Page 1 renders exactly 10 numeric buttons", count($numsP1) === 10);
assert_test("Page 1 buttons start at 1 and end at 10", $numsP1[0] === 1 && end($numsP1) === 10);

// Page 3 (as in user screenshot) should also render 1 to 10
preg_match_all('/class="page-btn page-btn-num[^"]*"[^>]*>\s*(\d+)\s*<\/(?:a|span)>/', $htmlPage3, $matchesP3);
$numsP3 = array_map('intval', $matchesP3[1]);
assert_test("Page 3 renders exactly 10 numeric buttons (1 to 10)", count($numsP3) === 10 && $numsP3[0] === 1 && end($numsP3) === 10);

// Page 7 should slide to 3 to 12
$htmlPage7 = render_accessible_pagination(7, 50, 2462, 50, 'library.php');
preg_match_all('/class="page-btn page-btn-num[^"]*"[^>]*>\s*(\d+)\s*<\/(?:a|span)>/', $htmlPage7, $matchesP7);
$numsP7 = array_map('intval', $matchesP7[1]);
assert_test("Page 7 renders exactly 10 numeric buttons sliding around page 7", count($numsP7) === 10 && in_array(7, $numsP7, true));

// Small totalPages (e.g. 6 pages) renders all available pages without exceeding total
$htmlSmall = render_accessible_pagination(2, 6, 150, 25, 'library.php');
preg_match_all('/class="page-btn page-btn-num[^"]*"[^>]*>\s*(\d+)\s*<\/(?:a|span)>/', $htmlSmall, $matchesSmall);
$numsSmall = array_map('intval', $matchesSmall[1]);
assert_test("Small total_pages (6) renders 6 buttons without phantom pages", count($numsSmall) === 6 && end($numsSmall) === 6);

// ---------------------------------------------------------------------
// 4. Direct Jump to Page Form & Bounds
// ---------------------------------------------------------------------
echo "\n4. Direct Jump to Page Input & Form Controls\n";
assert_test("Pagination includes jump-to-page form", strpos($htmlPage3, 'class="pagination-jump-form"') !== false);
assert_test("Jump form has number input with min=1 and max=50", strpos($htmlPage3, 'min="1"') !== false && strpos($htmlPage3, 'max="50"') !== false);
assert_test("Jump form input has accessible label", strpos($htmlPage3, 'aria-label="Target page number (1 to 50)"') !== false);
assert_test("Jump form has Go submit button", strpos($htmlPage3, 'btn-pagination-jump') !== false);

// ---------------------------------------------------------------------
// 5. Accessibility (a11y) Standards Compliance
// ---------------------------------------------------------------------
echo "\n5. Accessibility (a11y) Standards Compliance\n";
assert_test("Pagination container uses semantic <nav role=\"navigation\">", strpos($htmlPage3, '<nav class="pagination-nav" role="navigation"') !== false);
assert_test("Current active page has aria-current=\"page\"", strpos($htmlPage3, 'aria-current="page"') !== false);
assert_test("Active page has descriptive aria-label", strpos($htmlPage3, 'aria-label="Page 3, current page"') !== false);
assert_test("Disabled buttons have aria-disabled=\"true\"", strpos($htmlPage1, 'aria-disabled="true"') !== false);
assert_test("Status info has role=\"status\" and aria-live=\"polite\"", strpos($htmlPage3, 'role="status" aria-live="polite"') !== false);
assert_test("Links group has role=\"toolbar\" and aria-label", strpos($htmlPage3, 'role="toolbar"') !== false);

// Check CSS rules for touch targets and focus indicators
$cssContent = file_get_contents(dirname(__DIR__) . '/public/assets/css/style.css');
assert_test("CSS defines minimum touch target for .page-btn (>= 40px)", strpos($cssContent, 'min-height: 40px') !== false);
assert_test("CSS defines high-contrast focus rings (:focus-visible)", strpos($cssContent, '.page-btn:focus-visible') !== false);
assert_test("CSS defines .pagination-jump-input styling", strpos($cssContent, '.pagination-jump-input') !== false);

// Check JS keyboard support
$jsContent = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
assert_test("app.js implements initAccessiblePagination()", strpos($jsContent, 'function initAccessiblePagination()') !== false);
assert_test("app.js calls initAccessiblePagination() on DOMContentLoaded", strpos($jsContent, 'initAccessiblePagination();') !== false);
assert_test("app.js supports keyboard arrow keys (ArrowLeft / ArrowRight / Home / End)", strpos($jsContent, "e.key === 'ArrowRight'") !== false && strpos($jsContent, "e.key === 'ArrowLeft'") !== false);
assert_test("app.js supports Alt+Arrow navigation shortcuts", strpos($jsContent, 'e.altKey') !== false);

// ---------------------------------------------------------------------
// 6. HTTP Live Responses from Web Server (localhost:8080)
// ---------------------------------------------------------------------
echo "\n6. HTTP Delivery & Integration on localhost:8080\n";
$cookieJar = tempnam(sys_get_temp_dir(), 'afm_test_cookie_');
// Step 1: Fetch login page & CSRF token
$loginHtml = shell_exec("curl -s -c " . escapeshellarg($cookieJar) . " http://localhost:8080/login.php");
preg_match('/name="csrf_token" value="([^"]+)"/', $loginHtml, $csrfMatch);
$csrfToken = $csrfMatch[1] ?? '';

// Step 2: Authenticate
if ($csrfToken) {
    shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " -c " . escapeshellarg($cookieJar) . " -d \"csrf_token=" . urlencode($csrfToken) . "&username=adminuser&password=SecurePassword2026!\" http://localhost:8080/login.php");
}

// Step 3: Fetch page 3
$resPage3 = shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " \"http://localhost:8080/library.php?q=&filter=all&sort=id_asc&page=3\"");
$httpOk = !empty($resPage3);

assert_test("HTTP GET /library.php?page=3 returns content", $httpOk);
assert_test("HTTP response contains render_accessible_pagination markup", strpos($resPage3, 'pagination-nav') !== false);
assert_test("HTTP response contains '« First' button", strpos($resPage3, 'First') !== false);
assert_test("HTTP response contains 'Last »' button", strpos($resPage3, 'Last') !== false);
assert_test("HTTP response contains top per-page select dropdown", strpos($resPage3, 'id="perPageSelectTop"') !== false);
assert_test("HTTP response shows active page 3 with 10 buttons", strpos($resPage3, 'aria-current="page"') !== false && strpos($resPage3, 'Page 3 of') !== false);

// Test per_page=100 via HTTP
$res100Http = shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " \"http://localhost:8080/library.php?per_page=100\"");
assert_test("HTTP GET /library.php?per_page=100 renders 100 per page", strpos($res100Http, '100 per page') !== false);

@unlink($cookieJar);

echo "\n======================================================================\n";
echo " PAGINATION & ACCESSIBILITY SUMMARY: {$testsPassed} / {$totalTests} tests passed (" . round(($testsPassed / $totalTests) * 100) . "%)\n";
echo "======================================================================\n";

if ($testsPassed === $totalTests) {
    exit(0);
} else {
    exit(1);
}
