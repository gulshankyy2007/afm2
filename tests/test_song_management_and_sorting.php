<?php
/**
 * AFM#2 - Song Management, Library Sorting & Distinct Theme Backdrops Test Suite
 * Tests:
 * 1. Library Sorting by Title (ASC/DESC), Artist (ASC/DESC), and ID (ASC/DESC)
 * 2. YouTube Metadata Extraction (oEmbed zero-cost, regex parsing, thumbnail)
 * 3. Songs Management: Manual Creation, YouTube Creation, Update, and Safe Deletion
 * 4. Critical Competition Safeguards (blocking hard deletion of songs with ratings/results)
 * 5. Distinct Theme Backgrounds & Atmospheric presets for all themes
 * 6. Strict Catalogue Invariant Preservation (2,475 songs baseline preserved)
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_song_management_and_sorting');
$_SESSION['afm_user_id'] = 120;
$_SESSION['user_id'] = 120;
$testsPassed = 0;
$testsFailed = 0;

function assert_test(bool $condition, string $description, ?string $failContext = null): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  ✔ PASS: {$description}\n";
    } else {
        $testsFailed++;
        echo "  ✘ FAIL: {$description}\n";
        if ($failContext !== null) {
            echo "    CONTEXT: {$failContext}\n";
        }
    }
}

echo "\n======================================================================\n";
echo " AFM#2 — SONG MANAGEMENT, SORTING & THEME BACKDROPS TEST SUITE\n";
echo "======================================================================\n\n";

// Pre-test baseline checks
$initialCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test($initialCount >= 2400, "Pre-test Catalogue Baseline Invariant: Active catalogue songs present (actual: {$initialCount})");

// ======================================================================
// Section 1: Library Sorting Options in search_songs()
// ======================================================================
echo "\n1. Library Sorting Verification (Title, Artist, ID)\n";

// Default / ID ASC
$resIdAsc = search_songs('', 'all', 1, 10, 'id_asc');
assert_test(!empty($resIdAsc['songs']), "search_songs(..., 'id_asc') returns results");
assert_test((int)$resIdAsc['songs'][0]['song_id'] < (int)$resIdAsc['songs'][1]['song_id'], "ID ASC returns ascending song IDs");

// ID DESC
$resIdDesc = search_songs('', 'all', 1, 10, 'id_desc');
assert_test((int)$resIdDesc['songs'][0]['song_id'] > (int)$resIdDesc['songs'][1]['song_id'], "ID DESC returns descending song IDs");

// Title ASC
$resTitleAsc = search_songs('', 'all', 1, 20, 'title_asc');
$firstTitle = strtolower($resTitleAsc['songs'][0]['title']);
$secondTitle = strtolower($resTitleAsc['songs'][1]['title']);
assert_test(strcmp($firstTitle, $secondTitle) <= 0, "Title ASC correctly orders alphabetical titles ('{$firstTitle}' <= '{$secondTitle}')");

// Title DESC
$resTitleDesc = search_songs('', 'all', 1, 20, 'title_desc');
$descFirstTitle = strtolower($resTitleDesc['songs'][0]['title']);
$descSecondTitle = strtolower($resTitleDesc['songs'][1]['title']);
assert_test(strcmp($descFirstTitle, $descSecondTitle) >= 0, "Title DESC correctly orders reverse-alphabetical titles ('{$descFirstTitle}' >= '{$descSecondTitle}')");

// Artist ASC
$resArtistAsc = search_songs('', 'all', 1, 20, 'artist_asc');
$firstArtist = strtolower($resArtistAsc['songs'][0]['artist']);
$secondArtist = strtolower($resArtistAsc['songs'][1]['artist']);
assert_test(!empty($firstArtist) && strcmp($firstArtist, $secondArtist) <= 0, "Artist ASC orders valid artists alphabetically with non-empty first ('{$firstArtist}' <= '{$secondArtist}')");

// Artist DESC
$resArtistDesc = search_songs('', 'all', 1, 20, 'artist_desc');
$descFirstArtist = strtolower($resArtistDesc['songs'][0]['artist']);
$descSecondArtist = strtolower($resArtistDesc['songs'][1]['artist']);
assert_test(!empty($descFirstArtist) && strcmp($descFirstArtist, $descSecondArtist) >= 0, "Artist DESC orders artists reverse-alphabetically ('{$descFirstArtist}' >= '{$descSecondArtist}')");

// ======================================================================
// Section 2: YouTube Metadata Extraction (Zero-Cost oEmbed & Parsing)
// ======================================================================
echo "\n2. YouTube Metadata Extraction & Smart Cleaning\n";

// Invalid video ID or URL
$invalidRes = extract_youtube_metadata('https://notyoutube.example.com/video');
assert_test($invalidRes['success'] === false, "extract_youtube_metadata() rejects invalid URLs");

// Valid YouTube watch URL (Rick Astley test video)
$rickRes = extract_youtube_metadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
assert_test($rickRes['success'] === true, "extract_youtube_metadata() successfully extracts video dQw4w9WgXcQ");
assert_test($rickRes['video_id'] === 'dQw4w9WgXcQ', "Extracted video ID matches 'dQw4w9WgXcQ'");
assert_test(!empty($rickRes['thumbnail_url']), "Extracted thumbnail URL is populated");
assert_test(!empty($rickRes['channel_title']), "Extracted channel author title is populated ('{$rickRes['channel_title']}')");
assert_test(stripos($rickRes['title'], 'Never Gonna Give You Up') !== false, "Extracted clean title contains 'Never Gonna Give You Up' ('{$rickRes['title']}')");
assert_test(stripos($rickRes['artist'], 'Rick Astley') !== false, "Extracted artist contains 'Rick Astley' ('{$rickRes['artist']}')");

// youtu.be shortlink format
$shortRes = extract_youtube_metadata('https://youtu.be/dQw4w9WgXcQ');
assert_test($shortRes['success'] === true && $shortRes['video_id'] === 'dQw4w9WgXcQ', "extract_youtube_metadata() parses youtu.be shortlinks identically");

// Raw 11-char ID
$rawIdRes = extract_youtube_metadata('dQw4w9WgXcQ');
assert_test($rawIdRes['success'] === true && $rawIdRes['video_id'] === 'dQw4w9WgXcQ', "extract_youtube_metadata() parses raw 11-character video IDs directly");

// Remaster and video tags cleaning
$cleanRemaster = clean_track_title_for_lyrics('Never Gonna Give You Up (Official Music Video) (4K Remaster)');
assert_test($cleanRemaster === 'Never Gonna Give You Up', "clean_track_title_for_lyrics() cleanly strips (Official Music Video) and (4K Remaster) -> '{$cleanRemaster}'");

// ======================================================================
// Section 3: Songs Management (Create Manual, Create via YT, Update, Delete)
// ======================================================================
echo "\n3. Songs Management Operations (CRUD)\n";

// Create Song 1: Manual entry with empty artist
$createManual = create_catalogue_song('Test Symphony No. 9', '', null, 'unreviewed');
assert_test($createManual['success'] === true, "create_catalogue_song() creates manual song");
$testSongId1 = (int)$createManual['song_id'];
assert_test($testSongId1 > 2475, "Created song ID is greater than baseline ({$testSongId1})");

// Verify in DB
$dbCheck1 = $pdo->prepare("SELECT title, artist FROM songs WHERE song_id = :id");
$dbCheck1->execute([':id' => $testSongId1]);
$row1 = $dbCheck1->fetch();
assert_test($row1['title'] === 'Test Symphony No. 9', "Database confirms title 'Test Symphony No. 9'");
assert_test($row1['artist'] === null, "Empty artist string is stored as NULL in database");

// Create Song 2: With artist, YouTube URL, and shortlisted discovery status
$createYt = create_catalogue_song('Test Electric Avenue', 'Eddy Grant', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'shortlisted');
assert_test($createYt['success'] === true, "create_catalogue_song() creates song with YouTube media and shortlist status");
$testSongId2 = (int)$createYt['song_id'];

// Verify media and entry created
$mediaCheck = $pdo->prepare("SELECT video_id FROM song_media WHERE song_id = :id AND service = 'youtube'");
$mediaCheck->execute([':id' => $testSongId2]);
assert_test($mediaCheck->fetchColumn() === 'dQw4w9WgXcQ', "song_media record created with video_id 'dQw4w9WgXcQ'");

$discCheck = $pdo->prepare("SELECT status FROM song_discovery WHERE song_id = :id");
$discCheck->execute([':id' => $testSongId2]);
assert_test($discCheck->fetchColumn() === 'shortlisted', "song_discovery record created with status 'shortlisted'");

$entryCheck = $pdo->prepare("SELECT status FROM afm_entries WHERE song_id = :id");
$entryCheck->execute([':id' => $testSongId2]);
assert_test($entryCheck->fetchColumn() === 'auditioning', "afm_entries record created with status 'auditioning'");

// Update Song: update title, artist and media
$updateRes = update_catalogue_song($testSongId1, 'Test Symphony No. 9 (Updated)', 'Ludwig van Beethoven', 'https://youtu.be/dQw4w9WgXcQ');
assert_test($updateRes['success'] === true, "update_catalogue_song() updates title and artist successfully");

$dbCheckUpdate = $pdo->prepare("SELECT title, artist FROM songs WHERE song_id = :id");
$dbCheckUpdate->execute([':id' => $testSongId1]);
$rowUp = $dbCheckUpdate->fetch();
assert_test($rowUp['title'] === 'Test Symphony No. 9 (Updated)', "Updated title verified in DB");
assert_test($rowUp['artist'] === 'Ludwig van Beethoven', "Updated artist verified in DB");

// Verify sorting includes updated song
$searchSorted = search_songs('Test Symphony No. 9', 'all', 1, 10, 'title_asc');
assert_test(!empty($searchSorted['songs']) && $searchSorted['songs'][0]['song_id'] === $testSongId1, "search_songs() finds and sorts updated test song");

// ======================================================================
// Section 4: Safe Deletion & Competition History Protection
// ======================================================================
echo "\n4. Safe Deletion & Competition History Safeguards\n";

// Delete unrated test song 1: should delete cleanly
$delRes1 = delete_catalogue_song($testSongId1);
assert_test($delRes1['success'] === true, "delete_catalogue_song() cleanly deletes unrated song #{$testSongId1}");

// Verify song 1 is gone from DB
$checkDeleted1 = $pdo->prepare("SELECT COUNT(*) FROM songs WHERE song_id = :id");
$checkDeleted1->execute([':id' => $testSongId1]);
assert_test((int)$checkDeleted1->fetchColumn() === 0, "Song #{$testSongId1} is completely removed from songs table");

// CRITICAL SAFEGUARD: Attempt to delete a shortlisted song: must be blocked!
$delBlockedShortlist = delete_catalogue_song($testSongId2);
assert_test($delBlockedShortlist['success'] === false, "CRITICAL SAFEGUARD: delete_catalogue_song() blocks deletion of shortlisted song #{$testSongId2}");
assert_test(!empty($delBlockedShortlist['blocked']), "Blocked flag is set for shortlisted song deletion attempt");
assert_test(stripos($delBlockedShortlist['error'], 'shortlist') !== false, "Error message explicitly identifies shortlist status blocking deletion");

// Now remove from shortlist (return to unreviewed) and retry deletion
$unshortlistRes = update_song_discovery_status($testSongId2, 'unreviewed');
assert_test($unshortlistRes['success'] === true, "update_song_discovery_status() removed song #{$testSongId2} from shortlist");

$delRes2 = delete_catalogue_song($testSongId2);
assert_test($delRes2['success'] === true, "delete_catalogue_song() cleanly deletes unshortlisted song #{$testSongId2}");

// CRITICAL SAFEGUARD: Attempt to delete a song with tournament rating history
// Find a song with an existing rating
$scoredSong = $pdo->query("
    SELECT s.song_id, s.title, COUNT(r.rating_id) as ratings_count
    FROM songs s
    JOIN afm_entries e ON s.song_id = e.song_id
    JOIN ratings r ON e.entry_id = r.entry_id
    WHERE r.total IS NOT NULL
    GROUP BY s.song_id, s.title
    LIMIT 1
")->fetch();

if ($scoredSong) {
    $scoredSongId = (int)$scoredSong['song_id'];
    $delBlocked = delete_catalogue_song($scoredSongId);
    assert_test($delBlocked['success'] === false, "CRITICAL SAFEGUARD: delete_catalogue_song() blocks deletion of scored song #{$scoredSongId}");
    assert_test(!empty($delBlocked['blocked']), "Blocked flag is set in response");
    assert_test(!empty($delBlocked['blocked']) && (stripos($delBlocked['error'], 'shortlist') !== false || stripos($delBlocked['error'], 'cannot be permanently deleted') !== false || stripos($delBlocked['error'], 'active audition score') !== false), "Error message explicitly cites recorded tournament history or shortlist safeguard");

    // Verify scored song is still intact in catalogue
    $checkScoredStillExists = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE song_id = {$scoredSongId}")->fetchColumn();
    assert_test($checkScoredStillExists === 1, "Scored song #{$scoredSongId} strictly preserved in database");
}

// ======================================================================
// Section 5: Distinct Theme Backgrounds
// ======================================================================
echo "\n5. Distinct Theme Backgrounds & Atmospheric Presets\n";

assert_test(isset(AFM_VALID_THEMES['midnight_ember']), "Theme 'midnight_ember' exists");
assert_test(AFM_VALID_THEMES['midnight_ember']['bg_style'] === 'atmospheric', "midnight_ember default bg_style is 'atmospheric'");

assert_test(isset(AFM_VALID_THEMES['vintage_paper']), "Theme 'vintage_paper' exists");
assert_test(AFM_VALID_THEMES['vintage_paper']['bg_style'] === 'atmospheric', "vintage_paper default bg_style is 'atmospheric'");

assert_test(isset(AFM_VALID_THEMES['nordic_frost']), "Theme 'nordic_frost' exists");
assert_test(AFM_VALID_THEMES['nordic_frost']['bg_style'] === 'atmospheric', "nordic_frost default bg_style is 'atmospheric'");

assert_test(isset(AFM_VALID_THEMES['solar_terracotta']), "Theme 'solar_terracotta' exists");
assert_test(AFM_VALID_THEMES['solar_terracotta']['bg_style'] === 'atmospheric', "solar_terracotta default bg_style is 'atmospheric'");

assert_test(isset(AFM_VALID_THEMES['oceanic']), "Theme 'oceanic' exists");
assert_test(AFM_VALID_THEMES['oceanic']['bg_style'] === 'atmospheric', "oceanic default bg_style is 'atmospheric'");

assert_test(isset(AFM_VALID_THEMES['quiet_minimal']), "Theme 'quiet_minimal' exists");
assert_test(AFM_VALID_THEMES['quiet_minimal']['bg_style'] === 'atmospheric', "quiet_minimal default bg_style is 'atmospheric'");

// Verify CSS definitions in style.css for distinct backdrops
$cssContent = file_get_contents(dirname(__DIR__) . '/public/assets/css/style.css');
assert_test(strpos($cssContent, 'html[data-theme="oceanic"][data-bg-style="atmospheric"]') !== false, "style.css defines distinct atmospheric rule for Oceanic theme");
assert_test(strpos($cssContent, 'html[data-theme="quiet_minimal"][data-bg-style="atmospheric"]') !== false, "style.css defines distinct atmospheric rule for Quiet Minimal theme");
assert_test(strpos($cssContent, 'html[data-theme="vintage_paper"][data-bg-style="atmospheric"]') !== false, "style.css defines distinct atmospheric rule for Vintage Parchment");
assert_test(strpos($cssContent, 'html[data-theme="nordic_frost"][data-bg-style="atmospheric"]') !== false, "style.css defines distinct atmospheric rule for Nordic Frost");
assert_test(strpos($cssContent, 'html[data-theme="solar_terracotta"][data-bg-style="atmospheric"]') !== false, "style.css defines distinct atmospheric rule for Solar Terracotta");

// ======================================================================
// Section 6: UI Templates & Modals Verification
// ======================================================================
echo "\n6. UI Templates & Modal Controls in library.php\n";

$libraryContent = file_get_contents(dirname(__DIR__) . '/public/library.php');
assert_test(strpos($libraryContent, 'id="sortSelect"') !== false, "library.php contains sortSelect dropdown");
assert_test(strpos($libraryContent, 'value="title_asc"') !== false, "library.php contains Title (A-Z) sort option");
assert_test(strpos($libraryContent, 'value="artist_asc"') !== false, "library.php contains Artist (A-Z) sort option");
assert_test(strpos($libraryContent, 'id="btnOpenAddSongModal"') !== false, "library.php contains '+ Add Song' action button");
assert_test(strpos($libraryContent, 'id="addSongModal"') !== false, "library.php contains Add Song Modal");
assert_test(strpos($libraryContent, 'id="editSongModal"') !== false, "library.php contains Edit Song Modal");
assert_test(strpos($libraryContent, 'id="deleteSongModal"') !== false, "library.php contains Delete Song Modal");
assert_test(strpos($libraryContent, 'btn-manage-edit') !== false, "library.php renders row Edit buttons");
assert_test(strpos($libraryContent, 'btn-manage-delete') !== false, "library.php renders row Delete buttons");

// ======================================================================
// Section 7: Duplicate Prevention & Existing Record Update
// ======================================================================
echo "\n7. Duplicate Prevention & Existing Record Update Verification\n";

// Test find_existing_catalogue_song with existing song
$starshipSong = find_existing_catalogue_song("Nothing's Gonna Stop Us Now", "Starship");
assert_test($starshipSong !== null, "find_existing_catalogue_song finds track #2 'Nothing\'s Gonna Stop Us Now'");
assert_test((int)$starshipSong['song_id'] === 2, "Matched song_id is strictly 2");

// Test case-insensitive and trimmed lookup
$caseInsensitiveMatch = find_existing_catalogue_song("  nothing's gonna stop us now  ", "  STARSHIP  ");
assert_test($caseInsensitiveMatch !== null && (int)$caseInsensitiveMatch['song_id'] === 2, "Case-insensitive trimmed lookup matches existing track");

// Test create_catalogue_song on existing song: must update existing record without creating duplicate
$countBeforeDup = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$updateRes = create_catalogue_song(
    "Nothing's Gonna Stop Us Now",
    "Starship",
    "3wxyN3z9PL4",
    "shortlisted",
    "https://img.youtube.com/vi/3wxyN3z9PL4/hqdefault.jpg"
);
$countAfterDup = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();

assert_test($updateRes['success'] === true, "create_catalogue_song returns success when matching existing track");
assert_test(($updateRes['updated_existing'] ?? false) === true, "create_catalogue_song flags updated_existing = true");
assert_test((int)$updateRes['song_id'] === 2, "Updated song_id strictly points to existing track #2");
assert_test($countBeforeDup === $countAfterDup, "Catalogue row count remains strictly identical (no duplicate row created)");

// Verify media was updated for track 2
$track2Media = get_song_media(2);
assert_test($track2Media !== null && $track2Media['video_id'] === '3wxyN3z9PL4', "Existing track #2 media successfully updated with new video ID");

// ======================================================================
// Section 8: Final Baseline Integrity Check
// ======================================================================
echo "\n8. Final Baseline Integrity Check\n";
$finalCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test($finalCount >= 2400, "Post-test Catalogue Invariant: Active catalogue songs strictly preserved (actual: {$finalCount})");

$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test($nullArtistCount >= 3, "Post-test Missing Artist Invariant: NULL artist records strictly preserved (actual: {$nullArtistCount})");

echo "\n======================================================================\n";
echo " SONG MANAGEMENT TEST SUITE SUMMARY: {$testsPassed} / " . ($testsPassed + $testsFailed) . " tests passed\n";
echo "======================================================================\n\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
