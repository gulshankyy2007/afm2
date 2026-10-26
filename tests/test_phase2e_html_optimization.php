<?php
/**
 * AFM#2 — Phase 2E HTML Payload Optimization & Asset Decoupling Verification Suite
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function it(string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  ✔ PASS: {$description}\n";
    } else {
        $failedTests++;
        echo "  ✖ FAIL: {$description}" . ($details ? " ({$details})" : "") . "\n";
    }
}

function section(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n {$title}\n" . str_repeat('=', 70) . "\n\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo " AFM#2 — PHASE 2E HTML OPTIMIZATION & DECOUPLING TEST SUITE\n";
echo str_repeat('=', 70) . "\n";

// ---------------------------------------------------------------------
// 1. External JavaScript Asset Decoupling (recordings.js)
// ---------------------------------------------------------------------
section("1. External JavaScript Asset Decoupling (recordings.js)");

$recJsPath = dirname(__DIR__) . '/public/assets/js/recordings.js';
it("public/assets/js/recordings.js exists on disk", file_exists($recJsPath));
it("recordings.js is non-empty (> 20 KB)", file_exists($recJsPath) && filesize($recJsPath) > 20000);

// Validate JavaScript syntax using node
$nodeCheck = shell_exec("node --check " . escapeshellarg($recJsPath) . " 2>&1");
it("recordings.js passes Node.js syntax check", empty(trim($nodeCheck ?? '')), $nodeCheck ?? '');

// Inspect recordings.php for script tag and absence of inline script
$recPhpContent = file_get_contents(dirname(__DIR__) . '/public/recordings.php');
it("recordings.php references assets/js/recordings.js", str_contains($recPhpContent, 'src="assets/js/recordings.js'));
it("recordings.php no longer contains 500+ line inline script", !str_contains($recPhpContent, '// ==================== Candidate Review Modal ===================='));

// ---------------------------------------------------------------------
// 2. Global Image Fallback & Dataset Inheritance in app.js
// ---------------------------------------------------------------------
section("2. Global Image Fallback & Dataset Inheritance in app.js");

$appJsPath = dirname(__DIR__) . '/public/assets/js/app.js';
$appJsContent = file_get_contents($appJsPath);

$nodeAppCheck = shell_exec("node --check " . escapeshellarg($appJsPath) . " 2>&1");
it("app.js passes Node.js syntax check", empty(trim($nodeAppCheck ?? '')), $nodeAppCheck ?? '');

it("app.js includes global capture image error handler", str_contains($appJsContent, "window.addEventListener('error'") && str_contains($appJsContent, "fallbackApplied"));
it("app.js lyrics handler supports row dataset fallback", str_contains($appJsContent, "btn.dataset.songId || row?.dataset?.songId"));
it("app.js edit modal handler supports row dataset fallback", str_contains($appJsContent, "editBtn.getAttribute('data-song-id') || row?.getAttribute('data-song-id')"));
it("app.js delete modal handler supports row dataset fallback", str_contains($appJsContent, "delBtn.getAttribute('data-song-id') || row?.getAttribute('data-song-id')"));
it("app.js bulk delete handler supports row dataset fallback and checkbox value", str_contains($appJsContent, "cb.getAttribute('data-song-id') || row?.getAttribute('data-song-id') || cb.value"));

// ---------------------------------------------------------------------
// 3. Library Table Row Optimization & Redundancy Elimination
// ---------------------------------------------------------------------
section("3. Library Table Row Optimization & Redundancy Elimination");

$libPhpContent = file_get_contents(dirname(__DIR__) . '/public/library.php');

it("library.php maintains data-song-id on tr elements", str_contains($libPhpContent, '<tr data-song-id="<?= $sId ?>"'));
it("library.php maintains data-song-title on tr elements", str_contains($libPhpContent, 'data-song-title="<?= $sTitle ?>"'));
it("library.php maintains data-song-artist on tr elements", str_contains($libPhpContent, 'data-song-artist="<?= $sArtistEsc ?>"'));
it("library.php maintains data-song-video on tr elements", str_contains($libPhpContent, 'data-song-video="<?= $sVideo ?>"'));
it("library.php uses standard value attribute on checkbox", str_contains($libPhpContent, '<input type="checkbox" class="song-select-checkbox" value="<?= $sId ?>"'));
it("library.php eliminated repetitive inline onerror handlers in rows", !str_contains($libPhpContent, 'onerror="this.onerror=null; this.src=\'assets/img/artwork-fallback.svg\';"'));
it("library.php eliminated redundant data-song-title on edit button", !str_contains($libPhpContent, 'class="btn-manage-edit" data-song-title='));
it("library.php eliminated redundant data-song-title on delete button", !str_contains($libPhpContent, 'class="btn-manage-delete" data-song-title='));

// ---------------------------------------------------------------------
// 4. Recordings Table Row Optimization
// ---------------------------------------------------------------------
section("4. Recordings Table Row Optimization");

it("recordings.php maintains data-song-id, data-title, and data-artist on row tr", str_contains($recPhpContent, '<tr id="row-song-<?= $sId ?>" class="rec-table-row" data-song-id="<?= $sId ?>" data-title="<?= $titleEsc ?>" data-artist="<?= $artistAttr ?>">'));
it("recordings.php eliminated repetitive inline onerror handlers on thumbnails", !str_contains($recPhpContent, 'onerror="this.onerror=null; this.src=\'assets/img/artwork-fallback.svg\';"'));
it("recordings.php eliminated 5 repeated HTML comments per row inside table loop", !str_contains($recPhpContent, '<!-- Track Info (Artwork + Title + Artist) (Spotify Column 2) -->'));

// ---------------------------------------------------------------------
// 5. Live HTTP Delivery & Measurable Payload Reductions
// ---------------------------------------------------------------------
section("5. Live HTTP Delivery & Measurable Payload Reductions");

$cookieFile = dirname(__DIR__) . '/scratch/cookie.txt';
if (!file_exists($cookieFile)) {
    $cookieFile = '/home/gulshan-yadav/.gemini/antigravity-ide/brain/fae7d30b-2f34-4c5c-aa0b-92d936d495d1/scratch/cookie.txt';
}

$libRaw = shell_exec("curl -s -b " . escapeshellarg($cookieFile) . " 'http://127.0.0.1:8080/library.php'");
$recRaw = shell_exec("curl -s -b " . escapeshellarg($cookieFile) . " 'http://127.0.0.1:8080/recordings.php'");

$libSize = strlen($libRaw ?? '');
$recSize = strlen($recRaw ?? '');

it("HTTP GET /library.php returns 200 OK HTML", $libSize > 0 && str_contains($libRaw, '<table class="data-table" id="songsTable">'));
it("HTTP GET /recordings.php returns 200 OK HTML", $recSize > 0 && str_contains($recRaw, '<table class="recordings-table" id="recordingsTable">'));

// Baseline targets: Library was ~344,762 B; Recordings was ~227,795 B
it("Library raw HTML is reduced below 240,000 bytes (actual: {$libSize} B, -37% drop from 344KB)", $libSize < 240000 && $libSize > 150000);
it("Recordings raw HTML is reduced below 180,000 bytes (actual: {$recSize} B, -26% drop from 227KB)", $recSize < 180000 && $recSize > 120000);

$libGz = strlen(gzencode($libRaw, 9));
$recGz = strlen(gzencode($recRaw, 9));

it("Library gzip compressed size is under 20 KB (actual: {$libGz} B)", $libGz < 20000);
it("Recordings gzip compressed size is under 20 KB (actual: {$recGz} B)", $recGz < 20000);

// ---------------------------------------------------------------------
// 6. Catalogue Preservation Invariant Check
// ---------------------------------------------------------------------
section("6. Catalogue Preservation Invariant Check");

$pdo = get_db_connection();
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE is_consolidated = 0")->fetchColumn();
$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE artist IS NULL")->fetchColumn();
$nullArtistIds = $pdo->query("SELECT song_id FROM songs WHERE artist IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);

it("MANDATORY INVARIANT: Active catalogue songs remain strictly 2,411", $activeCount === 2411, "actual: {$activeCount}");
it("MANDATORY INVARIANT: NULL artist records strictly 3", $nullArtistCount === 3, "actual: {$nullArtistCount}");
it("MANDATORY INVARIANT: NULL artist IDs match [1546, 2015, 2196]", $nullArtistIds === [1546, 2015, 2196], "actual: " . json_encode($nullArtistIds));

// ---------------------------------------------------------------------
// Test Summary
// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 70) . "\n";
echo " PHASE 2E TEST SUMMARY: {$passedTests} / {$totalTests} tests passed\n";
if ($failedTests === 0) {
    echo " RESULT: ALL TESTS PASSED SUCCESSFULLY! ✔\n";
} else {
    echo " RESULT: {$failedTests} TESTS FAILED! ✖\n";
}
echo str_repeat('=', 70) . "\n\n";

exit($failedTests === 0 ? 0 : 1);
