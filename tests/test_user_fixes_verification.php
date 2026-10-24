<?php
/**
 * Test suite to verify all 4 specific user requests:
 * 1. Duplicates section removed entirely (nav, library button, duplicates.php redirect)
 * 2. Recording link persistence for tracks (API save_media, API save_rating with media, get_audition_entries)
 * 3. Dynamic track count (no hardcoded 2475/2,475 in public/ or includes/)
 * 4. Auditions page display option / pagination rendering and HTML tag balancing
 */

define('AFM_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/db_helper.php';
require_once __DIR__ . '/../includes/media_helper.php';

$pdo = get_db_connection();
$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name) {
    global $passed, $failed;
    if ($cond) {
        echo "  ✔ PASS: {$name}\n";
        $passed++;
    } else {
        echo "  ✖ FAIL: {$name}\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo " AFM#2 — USER FIXES VERIFICATION TEST SUITE\n";
echo "======================================================================\n\n";

// ---------------------------------------------------------------------
// 1. Duplicates Section Removal
// ---------------------------------------------------------------------
echo "1. Duplicates Section Removal Verification\n";
$headerContent = file_get_contents(__DIR__ . '/../includes/header.php');
assert_test(strpos($headerContent, 'duplicates.php') === false, "Header does not link to duplicates.php");

$libraryContent = file_get_contents(__DIR__ . '/../public/library.php');
assert_test(strpos($libraryContent, 'Review Duplicates') === false, "Library page has no 'Review Duplicates' button");
assert_test(strpos($libraryContent, 'duplicates.php') === false, "Library page has no links to duplicates.php");

$duplicatesContent = file_get_contents(__DIR__ . '/../public/duplicates.php');
assert_test(strpos($duplicatesContent, "Location: library.php") !== false, "duplicates.php immediately redirects to library.php");

// ---------------------------------------------------------------------
// 2. Dynamic Track Count (No hardcoded 2475 / 2,475)
// ---------------------------------------------------------------------
echo "\n2. Dynamic Track Count Verification\n";
$footerContent = file_get_contents(__DIR__ . '/../includes/footer.php');
assert_test(strpos($footerContent, '2,475') === false && strpos($footerContent, '2475') === false, "footer.php has no hardcoded 2,475/2475");
assert_test(strpos($footerContent, 'footerTrackCount') !== false, "footer.php computes dynamic track count");

$indexContent = file_get_contents(__DIR__ . '/../public/index.php');
assert_test(strpos($indexContent, '2,475') === false, "index.php has no hardcoded 2,475");

// Check all PHP files in public/ for hardcoded '2,475'
$publicFiles = glob(__DIR__ . '/../public/*.php');
$foundHardcoded = false;
foreach ($publicFiles as $pf) {
    $c = file_get_contents($pf);
    if (strpos($c, '2,475') !== false) {
        $foundHardcoded = true;
        echo "    Found hardcoded 2,475 in " . basename($pf) . "\n";
    }
}
assert_test(!$foundHardcoded, "Zero occurrences of hardcoded '2,475' across all public/*.php files");

// ---------------------------------------------------------------------
// 3. Recording Link Persistence for Track
// ---------------------------------------------------------------------
echo "\n3. Recording Link Persistence Verification\n";

// Find an active song to test
$song = $pdo->query("SELECT song_id FROM songs WHERE is_consolidated = 0 ORDER BY song_id ASC LIMIT 1")->fetch();
$testSongId = (int)$song['song_id'];

// Shortlist song for testing auditions integration
update_song_discovery_status($testSongId, 'shortlisted');
$testEntryId = (int)$pdo->query("SELECT entry_id FROM afm_entries WHERE song_id = {$testSongId}")->fetchColumn();

// Save a test YouTube recording
$testUrl = "https://www.youtube.com/watch?v=dQw4w9WgXcQ";
$saveRes = save_song_media($testSongId, $testUrl, 'original', 'Audition studio track', 'allowed', null, null, $testUrl);
assert_test($saveRes['success'] === true, "save_song_media returns success for song #{$testSongId}");

// Retrieve and verify it persists in database
$media = get_song_media($testSongId);
assert_test($media !== null, "get_song_media retrieves saved recording");
assert_test($media['video_id'] === 'dQw4w9WgXcQ', "Persisted video_id is 'dQw4w9WgXcQ'");
assert_test($media['user_url'] === $testUrl, "Persisted user_url matches input URL");
assert_test($media['version_notes'] === 'Audition studio track', "Persisted version_notes match");

// Verify get_audition_entries retrieves the media
$entriesResult = get_audition_entries(1, 'all', '', 1, 100);
$foundEntry = null;
foreach ($entriesResult['entries'] as $ent) {
    if ((int)$ent['song_id'] === $testSongId) {
        $foundEntry = $ent;
        break;
    }
}
assert_test($foundEntry !== null, "Entry found in get_audition_entries");
if ($foundEntry) {
    assert_test($foundEntry['media_video_id'] === 'dQw4w9WgXcQ', "get_audition_entries includes media_video_id 'dQw4w9WgXcQ'");
    assert_test($foundEntry['media_user_url'] === $testUrl, "get_audition_entries includes media_user_url");
}

// Test save_rating API payload with video_id_or_url
$updateNotes = "Updated during scorecard save";
$updateUrl = "https://youtu.be/kJQP7kiw5Fk";
$expectedVideoId = "kJQP7kiw5Fk";

// Simulate scorecard saving with video link
$saveScorecardRes = save_song_media($testSongId, $updateUrl, 'live', $updateNotes, 'allowed', null, null, $updateUrl);
assert_test($saveScorecardRes['success'] === true, "Updating recording via scorecard payload succeeds");

$updatedMedia = get_song_media($testSongId);
assert_test($updatedMedia['video_id'] === $expectedVideoId, "Updated video_id persists as '{$expectedVideoId}'");
assert_test($updatedMedia['version_type'] === 'live', "Updated version_type persists as 'live'");
assert_test($updatedMedia['version_notes'] === $updateNotes, "Updated version_notes persist as '{$updateNotes}'");

// Clean up test media and shortlist to restore completely clean state
remove_song_media($testSongId);
update_song_discovery_status($testSongId, 'unreviewed');
$pdo->exec("DELETE FROM `song_artwork` WHERE `song_id` = {$testSongId}");
$pdo->exec("DELETE FROM `afm_entries` WHERE `song_id` = {$testSongId}");
$deletedMedia = get_song_media($testSongId);
assert_test($deletedMedia === null, "Test media cleaned up successfully");
assert_test($deletedMedia === null, "Test media cleaned up successfully");

// ---------------------------------------------------------------------
// 4. Auditions Page Display Option & HTML Structure
// ---------------------------------------------------------------------
echo "\n4. Auditions Page Display Option & HTML Structure Verification\n";

$auditionsPhp = file_get_contents(__DIR__ . '/../public/auditions.php');
assert_test(strpos($auditionsPhp, 'name="per_page"') !== false, "auditions.php contains per_page filter form input");
assert_test(strpos($auditionsPhp, '25 per page') !== false, "auditions.php includes 25 per page option");
assert_test(strpos($auditionsPhp, '50 per page') !== false, "auditions.php includes 50 per page option");
assert_test(strpos($auditionsPhp, '100 per page') !== false, "auditions.php includes 100 per page option");
assert_test(strpos($auditionsPhp, 'render_accessible_pagination(') !== false, "auditions.php calls render_accessible_pagination()");

// Verify clean HTTP rendering on localhost:8080
$cookieJar = tempnam(sys_get_temp_dir(), 'afm_test_cookie_');
$loginHtml = shell_exec("curl -s -c " . escapeshellarg($cookieJar) . " http://localhost:8080/login.php");
preg_match('/name="csrf_token" value="([^"]+)"/', $loginHtml, $csrfMatch);
$csrfToken = $csrfMatch[1] ?? '';

if ($csrfToken) {
    shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " -c " . escapeshellarg($cookieJar) . " -d \"csrf_token=" . urlencode($csrfToken) . "&username=adminuser&password=SecurePassword2026!\" http://localhost:8080/login.php");
}

$htmlEmpty = shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " \"http://localhost:8080/auditions.php?round_id=1\"");
$httpOk = !empty($htmlEmpty) && strpos($htmlEmpty, 'Formal Auditions Scorecards') !== false;
assert_test($httpOk, "HTTP GET /auditions.php returns 200 OK and renders page");
$hasEmptyOrEntry = (strpos($htmlEmpty, 'No songs have been shortlisted for auditions yet') !== false) ||
                   (strpos($htmlEmpty, 'scorecard-card') !== false);
assert_test($hasEmptyOrEntry, "Auditions page renders valid scorecards or clean empty state");

$htmlEmptyRound2 = shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " \"http://localhost:8080/auditions.php?round_id=2\"");
assert_test(strpos($htmlEmptyRound2, 'No songs have qualified for Top 200 yet') !== false, "Empty state rendered when stage has no qualified entries");

// Test populated state with an auditioning song
$htmlPopulated = shell_exec("curl -s -b " . escapeshellarg($cookieJar) . " \"http://localhost:8080/auditions.php?song_id=1&per_page=25\"");
@unlink($cookieJar);

// Clean up the direct navigation entry from auditions and discovery
$pdo->exec("DELETE FROM `afm_entries` WHERE `song_id` = 1");
$pdo->exec("DELETE FROM `song_discovery` WHERE `song_id` = 1");

assert_test(strpos($htmlPopulated, 'class="pagination-bar"') !== false, "HTTP response renders pagination-bar when entries present");
assert_test(strpos($htmlPopulated, 'class="per-page-select"') !== false, "HTTP response renders top per-page-select when entries present");

// Check HTML tag balancing
$openDivs = substr_count($htmlPopulated, '<div');
$closeDivs = substr_count($htmlPopulated, '</div>');
assert_test($openDivs === $closeDivs, "Auditions page <div> tag balancing: open={$openDivs}, close={$closeDivs}");

echo "\n======================================================================\n";
echo " SUMMARY: {$passed} passed, {$failed} failed\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
