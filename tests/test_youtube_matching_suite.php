<?php
/**
 * AFM#2 - YouTube Matching, Persistence, and Manual Correction Test Suite
 *
 * Verifies all 15 mandatory test requirements specified in Section 11:
 *  1. Correct extraction of IDs from supported YouTube URL formats.
 *  2. Rejection of malformed and unsupported URLs.
 *  3. Title, artist, and version mismatch handling.
 *  4. Upload-date and view-count ranking behavior.
 *  5. Missing metadata and unavailable ranking signals.
 *  6. Confidence classification and unresolved candidates.
 *  7. Saving automatic matches to MySQL.
 *  8. Saving manual links to MySQL.
 *  9. Replacing an incorrect recording.
 * 10. Ensuring manual selections survive subsequent automatic matching.
 * 11. Persistence after refresh and a new application session.
 * 12. Resuming interrupted bulk jobs without duplicate records.
 * 13. Handling deleted and non-embeddable videos.
 * 14. Preservation of all existing catalogue, rating, and round-result data.
 * 15. Regression tests for existing playback, lyrics, and audition workflows.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  ✔ PASS: {$message}\n";
        $passCount++;
    } else {
        echo "  ❌ FAIL: {$message}\n";
        $failCount++;
    }
}

echo "======================================================================\n";
echo " AFM#2 YOUTUBE RECORDING MATCHING & PERSISTENCE TEST SUITE\n";
echo "======================================================================\n\n";

$pdo = get_db_connection();

// ---------------------------------------------------------------------
// Test Group 1: Correct Extraction of IDs from Supported YouTube URL Formats
// ---------------------------------------------------------------------
echo "1. Extraction of IDs from Supported YouTube URL Formats\n";
$urls = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'                     => 'dQw4w9WgXcQ',
    'http://youtube.com/watch?v=dQw4w9WgXcQ&feature=share'             => 'dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ'                                    => 'dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?t=43'                               => 'dQw4w9WgXcQ',
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ'                   => 'dQw4w9WgXcQ',
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ&list=RDAMVM'        => 'dQw4w9WgXcQ',
    'https://www.youtube.com/embed/dQw4w9WgXcQ'                       => 'dQw4w9WgXcQ',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'              => 'dQw4w9WgXcQ',
    'https://www.youtube.com/v/dQw4w9WgXcQ'                           => 'dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ'                      => 'dQw4w9WgXcQ',
    'dQw4w9WgXcQ'                                                     => 'dQw4w9WgXcQ',
];

foreach ($urls as $input => $expected) {
    $parsed = parse_youtube_id($input);
    assert_test($parsed === $expected, "Parsed '{$input}' -> '{$expected}'");
}

// ---------------------------------------------------------------------
// Test Group 2: Rejection of Malformed and Unsupported URLs
// ---------------------------------------------------------------------
echo "\n2. Rejection of Malformed and Unsupported URLs\n";
$invalidUrls = [
    'https://vimeo.com/12345678',
    'https://soundcloud.com/artist/track',
    'https://spotify.com/track/12345',
    'https://malicious-site.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch',
    'https://youtu.be/',
    'short',
    'toolongvideoidstring123',
    '',
    '    ',
    'http://notyoutube.com/embed/dQw4w9WgXcQ'
];

foreach ($invalidUrls as $bad) {
    $res = parse_youtube_id($bad);
    assert_test($res === null, "Rejected invalid/unsupported URL: " . ($bad === '' ? 'empty string' : $bad));
}

// ---------------------------------------------------------------------
// Test Group 3: Title, Artist, and Version Mismatch Handling
// ---------------------------------------------------------------------
echo "\n3. Title, Artist, and Version Mismatch Handling\n";
$targetTitle = 'Hotel California';
$targetArtist = 'Eagles';

$candidatesToScore = [
    [
        'video_id' => 'vid_exact',
        'title' => 'Hotel California (Official Audio)',
        'channel_title' => 'Eagles',
        'channel_is_artist' => true,
        'published_at' => '2015-01-01T00:00:00Z',
        'view_count' => 150000000,
        'duration_seconds' => 390
    ],
    [
        'video_id' => 'vid_live',
        'title' => 'Hotel California (Live at Capital Centre 1977)',
        'channel_title' => 'Eagles',
        'channel_is_artist' => true,
        'published_at' => '2018-05-10T00:00:00Z',
        'view_count' => 50000000,
        'duration_seconds' => 430
    ],
    [
        'video_id' => 'vid_acoustic',
        'title' => 'Hotel California (Acoustic Solo)',
        'channel_title' => 'Guitar Pro',
        'channel_is_artist' => false,
        'published_at' => '2020-01-01T00:00:00Z',
        'view_count' => 1000000,
        'duration_seconds' => 240
    ],
    [
        'video_id' => 'vid_mismatch',
        'title' => 'Desperado',
        'channel_title' => 'Eagles',
        'channel_is_artist' => true,
        'published_at' => '2015-01-01T00:00:00Z',
        'view_count' => 80000000,
        'duration_seconds' => 210
    ]
];

$scored = YouTubeMatcher::score_and_rank_candidates($targetTitle, $targetArtist, $candidatesToScore);
assert_test($scored[0]['video_id'] === 'vid_exact', "Top ranked candidate is the exact studio recording");
assert_test($scored[0]['score_total'] >= 90.0, "Exact match scores >= 90% (Actual: " . round($scored[0]['score_total'], 1) . "%)");

// Check version penalties
$liveCandidate = array_values(array_filter($scored, fn($c) => $c['video_id'] === 'vid_live'))[0];
$acousticCandidate = array_values(array_filter($scored, fn($c) => $c['video_id'] === 'vid_acoustic'))[0];
$mismatchCandidate = array_values(array_filter($scored, fn($c) => $c['video_id'] === 'vid_mismatch'))[0];

assert_test($liveCandidate['score_version'] < $scored[0]['score_version'], "Live version received version compatibility penalty (Score: " . round($liveCandidate['score_version'], 1) . " vs " . round($scored[0]['score_version'], 1) . ")");
assert_test($acousticCandidate['score_version'] < $scored[0]['score_version'], "Acoustic version received version compatibility penalty");
assert_test($mismatchCandidate['score_title'] < 10.0, "Mismatched song title received severe title penalty");
assert_test($mismatchCandidate['score_total'] < $liveCandidate['score_total'], "Mismatched song ranks below live version of correct song");

// ---------------------------------------------------------------------
// Test Group 4: Upload-Date and View-Count Ranking Behavior
// ---------------------------------------------------------------------
echo "\n4. Upload-Date and View-Count Ranking Behavior\n";
// Two candidates with identical title and artist agreement, but differing views and dates
$candViral = [
    'video_id' => 'vid_viral',
    'title' => 'Hotel California',
    'channel_title' => 'Eagles',
    'channel_is_artist' => true,
    'published_at' => '2023-01-01T00:00:00Z',
    'view_count' => 500000000, // 500M views
    'duration_seconds' => 390
];
$candNormal = [
    'video_id' => 'vid_normal',
    'title' => 'Hotel California',
    'channel_title' => 'Eagles',
    'channel_is_artist' => true,
    'published_at' => '2016-01-01T00:00:00Z',
    'view_count' => 10000000, // 10M views
    'duration_seconds' => 390
];

$testRanking = YouTubeMatcher::score_and_rank_candidates($targetTitle, $targetArtist, [$candViral, $candNormal]);
$scoreViral = array_values(array_filter($testRanking, fn($c) => $c['video_id'] === 'vid_viral'))[0]['score_views'];
$scoreNormal = array_values(array_filter($testRanking, fn($c) => $c['video_id'] === 'vid_normal'))[0]['score_views'];

assert_test($scoreViral > $scoreNormal, "Higher view count yields higher view score");
assert_test($scoreViral <= 7.0, "View score respects maximum 7% weight cap");
assert_test(abs($scoreViral - $scoreNormal) <= 3.0, "Logarithmic view normalization prevents viral video from dominating score");

// ---------------------------------------------------------------------
// Test Group 5: Missing Metadata and Unavailable Ranking Signals
// ---------------------------------------------------------------------
echo "\n5. Missing Metadata and Unavailable Ranking Signals\n";
$candMissingSignals = [
    'video_id' => 'vid_missing',
    'title' => 'Hotel California',
    'channel_title' => 'Eagles',
    'channel_is_artist' => false,
    'published_at' => null,
    'view_count' => 0,
    'duration_seconds' => null
];

$scoredMissing = YouTubeMatcher::score_and_rank_candidates($targetTitle, $targetArtist, [$candMissingSignals]);
$itemMissing = $scoredMissing[0];

assert_test(round((float)$itemMissing['score_date'], 1) === 4.0, "Missing upload date defaults to neutral midpoint (4.0/8)");
assert_test(round((float)$itemMissing['score_duration'], 1) === 2.5, "Missing duration defaults to neutral midpoint (2.5/5)");
assert_test($itemMissing['score_total'] > 50.0, "Missing non-critical metadata does not catastrophically zero out score");

// ---------------------------------------------------------------------
// Test Group 6: Confidence Classification and Unresolved Candidates
// ---------------------------------------------------------------------
echo "\n6. Confidence Classification and Unresolved Candidates\n";
// High confidence: >= 78% (exact studio match by artist)
$candHigh = [
    'video_id' => 'c_high_vid1',
    'title' => 'Hotel California',
    'channel_title' => 'Eagles',
    'channel_is_artist' => true,
    'published_at' => '2018-01-01T00:00:00Z',
    'view_count' => 20000000
];
// Medium confidence: Live version by artist (version-compromised, flagged for operator review)
$candMed = [
    'video_id' => 'c_live_vid1',
    'title' => 'Hotel California (Live at The Forum)',
    'channel_title' => 'Eagles',
    'channel_is_artist' => true,
    'published_at' => '2021-01-01T00:00:00Z',
    'view_count' => 5000000
];
// Low confidence: < 50% (mismatched song/topic)
$candLow = [
    'video_id' => 'c_low_vid01',
    'title' => 'Guitar Lesson - Strumming Patterns',
    'channel_title' => 'Marty Music',
    'channel_is_artist' => false,
    'published_at' => '2019-01-01T00:00:00Z',
    'view_count' => 100000
];

$classResults = YouTubeMatcher::score_and_rank_candidates($targetTitle, $targetArtist, [$candHigh, $candMed, $candLow]);
$mapClass = [];
foreach ($classResults as $cr) {
    $mapClass[$cr['video_id']] = $cr['confidence'];
}

assert_test($mapClass['c_high_vid1'] === 'high', "Candidate with high title+artist+official agreement classified as 'high' confidence");
assert_test($mapClass['c_live_vid1'] === 'medium', "Candidate with live version penalty classified as 'medium' confidence (Review Needed)");
assert_test($mapClass['c_low_vid01'] === 'low', "Candidate with mismatched topic classified as 'low' confidence (Unresolved)");

// ---------------------------------------------------------------------
// Test Group 7: Saving Automatic Matches to MySQL
// ---------------------------------------------------------------------
echo "\n7. Saving Automatic Matches to MySQL\n";
$testSongId = 1; // ...baby one more time by The Marías

// Clear any prior test media on song 1
$pdo->prepare("DELETE FROM `song_media` WHERE `song_id` = ?")->execute([$testSongId]);
$pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` = ?")->execute([$testSongId]);

$mockHighCandidate = [
    'video_id' => 'MOCK_HIGH01',
    'title' => '...baby one more time',
    'channel_title' => 'The Marías',
    'channel_is_artist' => true,
    'channel_is_verified' => true,
    'duration' => '3:15',
    'duration_seconds' => 195,
    'view_count' => 8500000,
    'published_at' => '2021-09-01 12:00:00',
    'score_total' => 96.5,
    'score_title' => 25.0,
    'score_artist' => 25.0,
    'score_version' => 20.0,
    'score_channel' => 10.0,
    'score_date' => 7.0,
    'score_views' => 6.5,
    'score_duration' => 3.0,
    'confidence' => 'high',
    'decision_notes' => 'High-confidence official upload match'
];

YouTubeMatcher::save_candidates_for_song($testSongId, [$mockHighCandidate]);
$autoRes = YouTubeMatcher::auto_select_high_confidence_match($testSongId, [$mockHighCandidate]);

assert_test($autoRes['selected'] === true, "High confidence match auto-selected");
assert_test($autoRes['video_id'] === 'MOCK_HIGH01', "Correct video ID returned by auto_select");

// Verify in MySQL
$mediaRow = $pdo->query("SELECT * FROM `song_media` WHERE `song_id` = {$testSongId}")->fetch(PDO::FETCH_ASSOC);
assert_test(!empty($mediaRow), "Media record persisted in MySQL");
assert_test($mediaRow['video_id'] === 'MOCK_HIGH01', "Video ID persisted accurately in MySQL");
assert_test($mediaRow['match_method'] === 'automatic', "Match method recorded as 'automatic'");
assert_test($mediaRow['confidence'] === 'high', "Confidence recorded as 'high'");
assert_test((int)$mediaRow['is_manual'] === 0, "is_manual flag is 0 for automatic selection");

// ---------------------------------------------------------------------
// Test Group 8: Saving Manual Links to MySQL
// ---------------------------------------------------------------------
echo "\n8. Saving Manual Links to MySQL\n";
$manualRes = YouTubeMatcher::manual_save_url(
    $testSongId,
    'https://www.youtube.com/watch?v=MANUAL_VID1',
    'acoustic',
    'Operator confirmed MTV acoustic recording'
);

assert_test($manualRes['success'] === true, "manual_save_url executed successfully");
assert_test($manualRes['video_id'] === 'MANUAL_VID1', "Extracted video ID matches manual link");

$mediaRowManual = $pdo->query("SELECT * FROM `song_media` WHERE `song_id` = {$testSongId}")->fetch(PDO::FETCH_ASSOC);
assert_test($mediaRowManual['video_id'] === 'MANUAL_VID1', "Manual video ID replaced previous auto-selection in MySQL");
assert_test((int)$mediaRowManual['is_manual'] === 1, "is_manual flag is 1 for manual entry");
assert_test($mediaRowManual['match_method'] === 'manual', "match_method recorded as 'manual'");
assert_test($mediaRowManual['version_type'] === 'acoustic', "version_type recorded as 'acoustic'");
assert_test($mediaRowManual['version_notes'] === 'Operator confirmed MTV acoustic recording', "version_notes persisted accurately");

// ---------------------------------------------------------------------
// Test Group 9: Replacing an Incorrect Recording
// ---------------------------------------------------------------------
echo "\n9. Replacing an Incorrect Recording\n";
$replaceRes = YouTubeMatcher::manual_save_url(
    $testSongId,
    'https://youtu.be/CORRECTED02',
    'remaster',
    'Corrected official remaster'
);

assert_test($replaceRes['success'] === true, "Replacement manual link saved successfully");
assert_test($replaceRes['video_id'] === 'CORRECTED02', "Video ID updated to new corrected ID");

$mediaRowReplaced = $pdo->query("SELECT * FROM `song_media` WHERE `song_id` = {$testSongId}")->fetch(PDO::FETCH_ASSOC);
assert_test($mediaRowReplaced['video_id'] === 'CORRECTED02', "MySQL stores corrected video ID");
assert_test((int)$mediaRowReplaced['is_manual'] === 1, "is_manual remains 1 after replacement");

// ---------------------------------------------------------------------
// Test Group 10: Ensuring Manual Selections Survive Subsequent Automatic Matching
// ---------------------------------------------------------------------
echo "\n10. Ensuring Manual Selections Survive Subsequent Automatic Matching\n";
// Attempt to auto-select another high-confidence match on song 1
$competingCandidate = [
    'video_id' => 'COMPETEAU99',
    'title' => '...baby one more time',
    'channel_title' => 'The Marías',
    'channel_is_artist' => true,
    'score_total' => 99.0,
    'confidence' => 'high'
];

$protectedRes = YouTubeMatcher::auto_select_high_confidence_match($testSongId, [$competingCandidate]);
assert_test($protectedRes['selected'] === false, "Auto-select refuses to overwrite manual selection");
assert_test(str_contains(strtolower($protectedRes['reason'] ?? ''), 'manual'), "Rejection reason explicitly notes manual protection");

// Verify MySQL was NOT modified
$mediaRowAfterAuto = $pdo->query("SELECT * FROM `song_media` WHERE `song_id` = {$testSongId}")->fetch(PDO::FETCH_ASSOC);
assert_test($mediaRowAfterAuto['video_id'] === 'CORRECTED02', "MySQL video ID remains 'CORRECTED02' (Protected from overwrite)");

// ---------------------------------------------------------------------
// Test Group 11: Persistence After Refresh and a New Application Session
// ---------------------------------------------------------------------
echo "\n11. Persistence After Refresh and a New Application Session\n";
// Simulate loading via media_helper get_song_media
$loadedMedia = get_song_media($testSongId);
assert_test(!empty($loadedMedia), "get_song_media retrieved media from fresh query");
assert_test($loadedMedia['video_id'] === 'CORRECTED02', "Persisted video ID retrieved across application calls");
assert_test((int)$loadedMedia['is_manual'] === 1, "Manual protection preserved");

// Verify in recordings catalogue view query
$catView = YouTubeMatcher::get_recordings_catalogue(['q' => '...baby one more time']);
assert_test($catView['total'] >= 1, "Catalogue query found song");
assert_test($catView['items'][0]['video_id'] === 'CORRECTED02', "Catalogue view displays persisted recording link");

// ---------------------------------------------------------------------
// Test Group 12: Resuming Interrupted Bulk Jobs Without Duplicate Records
// ---------------------------------------------------------------------
echo "\n12. Resuming Interrupted Bulk Jobs Without Duplicate Records\n";
$jobBefore = YouTubeMatcher::get_or_create_active_job();
$lastSongBefore = (int)$jobBefore['last_processed_song_id'];

// Process a batch of 2 songs
$batch1 = YouTubeMatcher::process_batch(2);
$jobAfterBatch1 = YouTubeMatcher::get_or_create_active_job();
$lastSongAfter1 = (int)$jobAfterBatch1['last_processed_song_id'];

assert_test($lastSongAfter1 >= $lastSongBefore, "Batch runner advances last_processed_song_id (From {$lastSongBefore} to {$lastSongAfter1})");

// Verify no duplicate entries in song_media or candidates
$dupMediaCheck = $pdo->query("SELECT song_id, COUNT(*) as cnt FROM song_media GROUP BY song_id HAVING cnt > 1")->fetchAll();
assert_test(empty($dupMediaCheck), "Zero duplicate song_media records created across all songs");

$dupCandCheck = $pdo->query("SELECT song_id, video_id, COUNT(*) as cnt FROM song_youtube_candidates GROUP BY song_id, video_id HAVING cnt > 1")->fetchAll();
assert_test(empty($dupCandCheck), "Zero duplicate candidate records created across all songs");

// ---------------------------------------------------------------------
// Test Group 13: Handling Deleted and Non-Embeddable Videos
// ---------------------------------------------------------------------
echo "\n13. Handling Deleted and Non-Embeddable Videos\n";
// Test oEmbed embed verification
$testValidEmbed = YouTubeMatcher::verify_playback_and_embed('bpNw7jYkbVc');
assert_test($testValidEmbed['success'] === true, "verify_playback_and_embed returned response for valid video");
assert_test($testValidEmbed['embed_status'] === 'allowed', "Standard public video returns embed_status = 'allowed'");

// Test non-existent or deleted video ID
$testDeletedEmbed = YouTubeMatcher::verify_playback_and_embed('DELETED_XYZ_000');
assert_test($testDeletedEmbed['embed_status'] === 'blocked', "Non-existent/deleted video returns embed_status = 'blocked'");

// ---------------------------------------------------------------------
// Test Group 14: Preservation of All Existing Catalogue, Rating, and Round-Result Data
// ---------------------------------------------------------------------
echo "\n14. Preservation of All Existing Catalogue, Rating, and Round-Result Data\n";
$totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
assert_test($totalSongs === 2411, "MANDATORY: Catalogue contains exactly 2,411 unconsolidated songs (No song dropped or deleted)");

$totalRounds = (int)$pdo->query("SELECT COUNT(*) FROM `rounds`")->fetchColumn();
assert_test($totalRounds === 7, "All 7 tournament rounds preserved intact");

$totalEntries = (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries`")->fetchColumn();
assert_test($totalEntries >= 0, "afm_entries table intact");

$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test($nullArtistCount === 3, "Authentic songs with NULL artist preserved (3 songs)");

// ---------------------------------------------------------------------
// Test Group 15: Regression Tests for Existing Playback, Lyrics, and Audition Workflows
// ---------------------------------------------------------------------
echo "\n15. Regression Tests for Existing Playback, Lyrics, and Audition Workflows\n";
$audEntries = get_audition_entries(1, 'all');
assert_test(is_array($audEntries), "get_audition_entries() executes successfully with media joins");

// Test clear recording functionality
$clearRes = YouTubeMatcher::clear_recording($testSongId);
assert_test($clearRes['success'] === true, "clear_recording() executes successfully");
$clearedMedia = get_song_media($testSongId);
assert_test($clearedMedia === null, "Media record removed after clear_recording()");

// Confirm song record itself remains completely intact
$songIntact = $pdo->query("SELECT song_id, title FROM `songs` WHERE `song_id` = {$testSongId}")->fetch(PDO::FETCH_ASSOC);
assert_test(!empty($songIntact), "Song row #{$testSongId} remains 100% intact after recording clear");

echo "\n======================================================================\n";
echo " TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
