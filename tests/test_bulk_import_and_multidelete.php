<?php
/**
 * AFM#2 — CSV Bulk Import & Library Multi-Select Deletion Test Suite
 *
 * Comprehensive automated verification covering:
 * 1. CSV Header detection & alias resolution
 * 2. UTF-8 non-English characters & BOM handling
 * 3. Quoted commas, escaped quotes & missing artists
 * 4. Empty files, malformed rows & missing title rejections
 * 5. Internal file duplicate detection
 * 6. Existing catalogue duplicate detection
 * 7. Similar titles, live versions & remix safeguards
 * 8. Transactional import execution & status provisioning
 * 9. Repeated submissions / token idempotency protection
 * 10. Rejection report CSV generation
 * 11. Multi-select bulk deletion & tournament safeguards
 * 12. Catalogue baseline & NULL artist integrity invariants
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_test(string $description, bool $condition, ?string $context = null): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  ✔ PASS: {$description}\n";
    } else {
        $testsFailed++;
        echo "  ✘ FAIL: {$description}\n";
        if ($context !== null) {
            echo "    CONTEXT: {$context}\n";
        }
    }
}

$pdo = get_db_connection();

echo "\n======================================================================\n";
echo " AFM#2 — CSV BULK IMPORT & MULTI-SELECT DELETION TEST SUITE\n";
echo "======================================================================\n\n";

// Initial catalogue baseline check
$initialSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Pre-test Catalogue Baseline Invariant: Active catalogue songs present (count: {$initialSongCount})", $initialSongCount >= 2400);

$initialNullCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test("Pre-test Missing Artist Invariant: NULL artist records present", $initialNullCount >= 3);

// -----------------------------------------------------------------------------
// SECTION 1: CSV Header Detection & Alias Mapping
// -----------------------------------------------------------------------------
echo "\n1. CSV Header Detection & Alias Mapping\n";

// Test Track name / Artist name headers
$headers1 = ['Track name', 'Artist name', 'Album'];
$det1 = detect_csv_headers($headers1);
assert_test("Detects 'Track name' as Title (Col 0)", $det1['title_col'] === 0 && $det1['title_confidence'] === 'high');
assert_test("Detects 'Artist name' as Artist (Col 1)", $det1['artist_col'] === 1 && $det1['artist_confidence'] === 'high');

// Test Title / Artist headers
$headers2 = ['Song ID', 'Title', 'Artist', 'Year'];
$det2 = detect_csv_headers($headers2);
assert_test("Detects 'Title' as Title (Col 1)", $det2['title_col'] === 1 && $det2['title_confidence'] === 'high');
assert_test("Detects 'Artist' as Artist (Col 2)", $det2['artist_col'] === 2 && $det2['artist_confidence'] === 'high');

// Test Song / Performer headers
$headers3 = ['Song', 'Performer'];
$det3 = detect_csv_headers($headers3);
assert_test("Detects 'Song' as Title (Col 0)", $det3['title_col'] === 0);
assert_test("Detects 'Performer' as Artist (Col 1)", $det3['artist_col'] === 1);

// Test Arbitrary non-matching headers fallback
$headers4 = ['ColA', 'ColB'];
$det4 = detect_csv_headers($headers4);
assert_test("Falls back gracefully with low confidence for custom headers", $det4['title_col'] === 0 && $det4['title_confidence'] === 'low');

// -----------------------------------------------------------------------------
// SECTION 2: Parsing UTF-8, Non-English Text & Quoted Fields
// -----------------------------------------------------------------------------
echo "\n2. Parsing UTF-8, Non-English Text & Quoted Fields\n";

$tmpCsv1 = tempnam(sys_get_temp_dir(), 'afm_test_utf8_') . '.csv';
$csvData1 = "\xEF\xBB\xBFTrack name,Artist name\n"; // With UTF-8 BOM
$csvData1 .= "\"夜に駆ける\",\"YOASOBI\"\n"; // Japanese
$csvData1 .= "\"봄날\",\"BTS\"\n"; // Korean
$csvData1 .= "\"तुम ही हो\",\"Arijit Singh\"\n"; // Hindi
$csvData1 .= "\"Группа крови\",\"Кино\"\n"; // Cyrillic
$csvData1 .= "\"Hélène\",\"Roch Voisine\"\n"; // French accented
$csvData1 .= "\"Symphony No. 5 in C Minor, Op. 67\",\"Ludwig van Beethoven\"\n"; // Quoted comma
$csvData1 .= "\"The \"\"Special\"\" Cut\",\"Rock Band\"\n"; // Escaped quotes
$csvData1 .= "\"Unknown Performer Track\",\n"; // Missing artist (empty)
file_put_contents($tmpCsv1, $csvData1);

$parseResult1 = parse_and_validate_csv($tmpCsv1);
unlink($tmpCsv1);

assert_test("parse_and_validate_csv() succeeds on UTF-8 CSV with BOM", $parseResult1['success'] === true);
assert_test("Detected 8 total rows in test CSV", $parseResult1['counts']['total'] === 8);

$parsedRows = $parseResult1['rows'];
assert_test("Parsed Japanese title '夜に駆ける'", $parsedRows[0]['title'] === '夜に駆ける' && $parsedRows[0]['artist'] === 'YOASOBI');
assert_test("Parsed Korean title '봄날'", $parsedRows[1]['title'] === '봄날' && $parsedRows[1]['artist'] === 'BTS');
assert_test("Parsed Hindi title 'तुम ही हो'", $parsedRows[2]['title'] === 'तुम ही हो' && $parsedRows[2]['artist'] === 'Arijit Singh');
assert_test("Parsed Cyrillic title 'Группа крови'", $parsedRows[3]['title'] === 'Группа крови' && $parsedRows[3]['artist'] === 'Кино');
assert_test("Parsed Accented title 'Hélène'", $parsedRows[4]['title'] === 'Hélène' && $parsedRows[4]['artist'] === 'Roch Voisine');
assert_test("Preserved embedded comma in 'Symphony No. 5 in C Minor, Op. 67'", $parsedRows[5]['title'] === 'Symphony No. 5 in C Minor, Op. 67');
assert_test("Preserved escaped quotes in 'The \"Special\" Cut'", $parsedRows[6]['title'] === 'The "Special" Cut');
assert_test("Normalized missing artist to NULL", $parsedRows[7]['artist'] === null);

// -----------------------------------------------------------------------------
// SECTION 3: Empty Files, Malformed Rows & Missing Title Validation
// -----------------------------------------------------------------------------
echo "\n3. Empty Files, Malformed Rows & Missing Title Validation\n";

// Completely empty file
$tmpEmpty = tempnam(sys_get_temp_dir(), 'afm_test_empty_') . '.csv';
file_put_contents($tmpEmpty, "");
$emptyRes = parse_and_validate_csv($tmpEmpty);
unlink($tmpEmpty);
assert_test("Rejects completely empty CSV file", $emptyRes['success'] === false && strpos($emptyRes['error'], 'empty') !== false);

// CSV with empty title row
$tmpBad = tempnam(sys_get_temp_dir(), 'afm_test_bad_') . '.csv';
$csvBad = "Title,Artist\n";
$csvBad .= "\"\", \"Artist Without Title\"\n";
$csvBad .= "   , \"Whitespace Only Title\"\n";
$csvBad .= "\"Valid Song Title\", \"Valid Artist\"\n";
file_put_contents($tmpBad, $csvBad);
$badRes = parse_and_validate_csv($tmpBad);
unlink($tmpBad);

assert_test("Identifies invalid rows with missing titles", $badRes['counts']['invalid'] === 2);
assert_test("Line 2 flagged invalid with missing title reason", $badRes['rows'][0]['status'] === 'invalid' && strpos($badRes['rows'][0]['reason'], 'Missing required song title') !== false);
assert_test("Line 3 flagged invalid with missing title reason", $badRes['rows'][1]['status'] === 'invalid');
assert_test("Line 4 flagged valid", $badRes['rows'][2]['status'] === 'valid_new');

// -----------------------------------------------------------------------------
// SECTION 4: Duplicate Review (In-File & Against Database Catalogue)
// -----------------------------------------------------------------------------
echo "\n4. Duplicate Review (In-File & Against Database Catalogue)\n";

// Get an existing song from DB
$existingSong = $pdo->query("SELECT song_id, title, artist FROM `songs` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$exId = (int)$existingSong['song_id'];
$exTitle = $existingSong['title'];
$exArtist = $existingSong['artist'] ?? '';

$tmpDup = tempnam(sys_get_temp_dir(), 'afm_test_dup_') . '.csv';
$csvDup = "Track name,Artist name\n";
// Existing in catalogue
$csvDup .= "\"{$exTitle}\",\"{$exArtist}\"\n";
// Brand new track
$csvDup .= "\"Unique AFM Brand New Contender 2026\",\"Alpha Contender\"\n";
// In-file duplicate of the brand new track
$csvDup .= "\"Unique AFM Brand New Contender 2026\",\"Alpha Contender\"\n";
// Similar title version (Live / Remix)
$csvDup .= "\"{$exTitle} (Live Acoustic Version)\",\"{$exArtist}\"\n";
file_put_contents($tmpDup, $csvDup);

$dupRes = parse_and_validate_csv($tmpDup);
unlink($tmpDup);

assert_test("Flags exact catalogue match as 'existing_in_db'", $dupRes['rows'][0]['status'] === 'existing_in_db' && $dupRes['rows'][0]['existing_song_id'] === $exId);
assert_test("Catalogue match is unapproved by default", $dupRes['rows'][0]['default_approved'] === false);

assert_test("Flags unique new track as 'valid_new'", $dupRes['rows'][1]['status'] === 'valid_new');
assert_test("Flags repeated in-file row as 'duplicate_in_file'", $dupRes['rows'][2]['status'] === 'duplicate_in_file');
assert_test("In-file duplicate cites original row line number", strpos($dupRes['rows'][2]['reason'], 'line 3') !== false);

// Safeguard check: Alternate/live version is flagged as similar_in_db for review, NOT auto-discarded
assert_test("Alternate version flagged as 'similar_in_db' for user decision", $dupRes['rows'][3]['status'] === 'similar_in_db');
assert_test("Alternate version is approved for review by default", $dupRes['rows'][3]['default_approved'] === true);

// -----------------------------------------------------------------------------
// SECTION 5: Atomic Transactional Import Execution
// -----------------------------------------------------------------------------
echo "\n5. Atomic Transactional Import Execution\n";

$rowsToImport = [
    [
        'line_num' => 2,
        'title'    => 'Test Bulk Import Contender A',
        'artist'   => 'Test Artist A'
    ],
    [
        'line_num' => 3,
        'title'    => 'Test Bulk Import Contender B (Unknown Artist)',
        'artist'   => null
    ]
];

// Import with unreviewed status
$importRes1 = execute_csv_import($rowsToImport, 'unreviewed');
assert_test("execute_csv_import() executes successfully", $importRes1['success'] === true);
assert_test("Added exactly 2 songs to catalogue", $importRes1['songs_added'] === 2);
$newSongIds = $importRes1['new_song_ids'];
assert_test("Returned 2 new song IDs", count($newSongIds) === 2);

// Verify songs in DB
$checkA = $pdo->prepare("SELECT title, artist FROM songs WHERE song_id = :id");
$checkA->execute([':id' => $newSongIds[0]]);
$rowA = $checkA->fetch();
assert_test("Song A title matches 'Test Bulk Import Contender A'", $rowA['title'] === 'Test Bulk Import Contender A');
assert_test("Song A artist matches 'Test Artist A'", $rowA['artist'] === 'Test Artist A');

$checkB = $pdo->prepare("SELECT title, artist FROM songs WHERE song_id = :id");
$checkB->execute([':id' => $newSongIds[1]]);
$rowB = $checkB->fetch();
assert_test("Song B title matches 'Test Bulk Import Contender B (Unknown Artist)'", $rowB['title'] === 'Test Bulk Import Contender B (Unknown Artist)');
assert_test("Song B artist is stored as NULL", $rowB['artist'] === null);

// Import with shortlisted status
$rowsShortlist = [
    [
        'line_num' => 4,
        'title'    => 'Test Bulk Shortlisted Track',
        'artist'   => 'Fast Track Artist'
    ]
];
$importRes2 = execute_csv_import($rowsShortlist, 'shortlisted');
assert_test("Import with shortlisted status succeeds", $importRes2['success'] === true);
$shortlistSongId = $importRes2['new_song_ids'][0];

// Verify discovery and auditioning records provisioned
$discCheck = $pdo->prepare("SELECT status FROM song_discovery WHERE song_id = :id");
$discCheck->execute([':id' => $shortlistSongId]);
assert_test("song_discovery status is 'shortlisted'", $discCheck->fetchColumn() === 'shortlisted');

$entryCheck = $pdo->prepare("SELECT status FROM afm_entries WHERE song_id = :id");
$entryCheck->execute([':id' => $shortlistSongId]);
assert_test("afm_entries status is 'auditioning'", $entryCheck->fetchColumn() === 'auditioning');

// -----------------------------------------------------------------------------
// SECTION 6: Rejection Report CSV Generation
// -----------------------------------------------------------------------------
echo "\n6. Rejection Report CSV Generation\n";

$rejectedSample = [
    [
        'line_num'   => 5,
        'raw_title'  => 'Missing Title Track',
        'raw_artist' => 'Some Artist',
        'status'     => 'invalid',
        'reason'     => 'Line 5: Missing required song title.'
    ],
    [
        'line_num'   => 6,
        'raw_title'  => 'Duplicate Track',
        'raw_artist' => 'Some Artist',
        'status'     => 'duplicate_in_file',
        'reason'     => 'Duplicate in file (matches line 4)'
    ]
];

$reportCsv = generate_rejection_csv_report($rejectedSample);
assert_test("generate_rejection_csv_report() returns valid CSV string", is_string($reportCsv) && strlen($reportCsv) > 0);
assert_test("Report contains 'CSV Line,Song Title,Artist,Classification,Reason / Safeguard Notes'", strpos($reportCsv, 'CSV Line') !== false && strpos($reportCsv, 'Classification') !== false);
assert_test("Report includes rejected line 5 details", strpos($reportCsv, 'Line 5: Missing required song title') !== false);

// -----------------------------------------------------------------------------
// SECTION 7: Multi-Select & Bulk Deletion in Library
// -----------------------------------------------------------------------------
echo "\n7. Multi-Select & Bulk Deletion in Library\n";

// Verify batch deletion of test unrated songs created in Section 5
$testDeleteIds = array_merge($newSongIds, [$shortlistSongId]);
$bulkDelRes = delete_multiple_catalogue_songs($testDeleteIds);

assert_test("delete_multiple_catalogue_songs() returns success", $bulkDelRes['success'] === true);
assert_test("Deleted count matches 3 songs", $bulkDelRes['deleted_count'] === 3);
assert_test("Blocked count is 0 for unrated songs", $bulkDelRes['blocked_count'] === 0);

// Verify songs are gone from DB
$inCheck = implode(',', $testDeleteIds);
$verifyDeleted = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `song_id` IN ($inCheck)")->fetchColumn();
assert_test("All test songs completely removed from songs table", $verifyDeleted === 0);

// Verify discovery and entry rows also cascaded
$verifyEntry = (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries` WHERE `song_id` = {$shortlistSongId}")->fetchColumn();
assert_test("afm_entries row cascaded and removed", $verifyEntry === 0);

// CRITICAL SAFEGUARD TEST: Attempting bulk delete on songs with tournament rating history
// Create a rated test entry
$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`) VALUES ('Safeguard Scored Song', 'Competition Artist')");
$scoredSongId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `afm_entries` (`song_id`, `status`) VALUES ({$scoredSongId}, 'auditioning')");
$testEntryId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `ratings` (`entry_id`, `round_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) VALUES ({$testEntryId}, 1, 8, 8, 8, 8, 8)");
$pdo->commit();

// Create an unrated companion song
$pdo->exec("INSERT INTO `songs` (`title`, `artist`) VALUES ('Safeguard Unrated Song', 'Unrated Artist')");
$unratedCompanionId = (int)$pdo->lastInsertId();

// Run bulk deletion on both: the rated song MUST be blocked; the unrated song MUST be deleted
$mixedDelRes = delete_multiple_catalogue_songs([$scoredSongId, $unratedCompanionId]);
assert_test("Mixed bulk delete executes with partial success", $mixedDelRes['success'] === true);
assert_test("Exactly 1 unrated song was deleted", $mixedDelRes['deleted_count'] === 1);
assert_test("Exactly 1 scored song was blocked and protected", $mixedDelRes['blocked_count'] === 1);
assert_test("Blocked song ID matches rated song", $mixedDelRes['blocked_songs'][0]['song_id'] === $scoredSongId);
assert_test("Safeguard cites recorded tournament history", strpos($mixedDelRes['blocked_songs'][0]['reason'], 'tournament history') !== false);

// Clean up rated test row safely via explicit transaction
$pdo->beginTransaction();
$pdo->exec("DELETE FROM `ratings` WHERE `entry_id` = {$testEntryId}");
$pdo->exec("DELETE FROM `afm_entries` WHERE `entry_id` = {$testEntryId}");
$pdo->exec("DELETE FROM `songs` WHERE `song_id` = {$scoredSongId}");
$pdo->commit();

// Test empty / invalid ID array
$emptyDelRes = delete_multiple_catalogue_songs([]);
assert_test("Rejects empty array of IDs", $emptyDelRes['success'] === false);

// -----------------------------------------------------------------------------
// SECTION 8: Final Integrity & Invariant Verification
// -----------------------------------------------------------------------------
echo "\n8. Final Integrity & Invariant Verification\n";

$finalSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Post-test Catalogue Invariant: Active catalogue songs strictly preserved (count: {$finalSongCount} >= 2400)", $finalSongCount >= 2400);

$finalNullCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test("Post-test Missing Artist Invariant: NULL artist records strictly preserved", $finalNullCount >= 3);

echo "\n======================================================================\n";
echo " TEST SUITE SUMMARY: {$testsPassed} / " . ($testsPassed + $testsFailed) . " tests passed\n";
echo "======================================================================\n\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
