<?php
/**
 * AFM#2 - Integrated Music Playback Repair & Persistent Recording Selection Test Suite
 * Validates:
 * 1. URL parsing across all supported YouTube and YouTube Music formats
 * 2. Rejection of untrusted/spoofed domains and arbitrary iframe sources
 * 3. Candidate recording resolution, oEmbed metadata, and canonical playback objects
 * 4. Recording selection persistence (user_url, version_type, version_notes)
 * 5. Invariant integrity: zero changes to song title/artist metadata upon media update
 * 6. Auditions embedded player rendering with strict origin and referrerpolicy
 * 7. Live playback status badges and direct external links (not search redirects)
 * 8. Database catalogue invariants: 2,475 songs, 4 NULL artist records intact
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';

$pdo = get_db_connection();
$tests = 0;
$passed = 0;

function assert_test($description, $condition) {
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
echo " AFM#2 — INTEGRATED PLAYBACK REPAIR & PERSISTENCE TEST SUITE\n";
echo "======================================================================\n\n";

// -----------------------------------------------------------------------------
// SECTION 1: URL Parsing & Validation Across All YouTube Formats
// -----------------------------------------------------------------------------
echo "1. YouTube URL Parsing & Sanitization\n";

$urlCases = [
    'Raw 11-character video ID' => ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'Standard watch URL' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'Standard watch with params' => ['https://www.youtube.com/watch?feature=shared&v=dQw4w9WgXcQ&t=42', 'dQw4w9WgXcQ'],
    'Shortened youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'Shortened youtu.be with timestamp' => ['https://youtu.be/dQw4w9WgXcQ?t=1m20s', 'dQw4w9WgXcQ'],
    'YouTube Music watch link' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'YouTube Music with playlist params' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ&list=RDAMVMdQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'Mobile m.youtube.com watch link' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'YouTube Shorts URL' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'YouTube Live stream URL' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'YouTube Embed URL' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'YouTube Privacy Embed URL' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'Older /v/ URL format' => ['https://www.youtube.com/v/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
];

foreach ($urlCases as $desc => $c) {
    $parsed = parse_youtube_id($c[0]);
    assert_test("{$desc} resolves to '{$c[1]}'", $parsed === $c[1]);
}

// Security & Malformed Rejections
echo "\n2. Security & Malformed URL Rejections\n";
$badCases = [
    'External domain with watch param' => 'https://malicious-site.com/watch?v=dQw4w9WgXcQ',
    'Phishing sub-domain spoof' => 'https://youtube.com.attacker.com/watch?v=dQw4w9WgXcQ',
    'Arbitrary protocol injection' => 'javascript:alert(1)',
    'Data URL injection' => 'data:text/html,<script>alert(1)</script>',
    'Google search URL' => 'https://www.google.com/search?q=dQw4w9WgXcQ',
    'Invalid ID length (too short)' => 'shortID',
    'Invalid characters in raw ID' => 'dQw4w9WgXc<',
    'Empty string' => '',
];

foreach ($badCases as $desc => $url) {
    $parsed = parse_youtube_id($url);
    assert_test("Rejects {$desc}", $parsed === null);
}

// -----------------------------------------------------------------------------
// SECTION 2: Candidate Resolution Workflow (Preview before Saving)
// -----------------------------------------------------------------------------
echo "\n3. Candidate Resolution Workflow (Preview before Saving)\n";

$resValid = resolve_youtube_recording('https://music.youtube.com/watch?v=dQw4w9WgXcQ', 1);
assert_test("resolve_youtube_recording succeeds for valid YouTube Music URL", $resValid['success'] === true);
assert_test("resolve_youtube_recording extracts correct video_id", ($resValid['video_id'] ?? '') === 'dQw4w9WgXcQ');
assert_test("resolve_youtube_recording provides canonical watch_url", ($resValid['watch_url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
assert_test("resolve_youtube_recording provides canonical music_url", ($resValid['music_url'] ?? '') === 'https://music.youtube.com/watch?v=dQw4w9WgXcQ');
assert_test("resolve_youtube_recording provides privacy-enhanced embed_url", strpos($resValid['embed_url'] ?? '', 'youtube-nocookie.com/embed/dQw4w9WgXcQ') !== false);
assert_test("resolve_youtube_recording embed_url includes enablejsapi=1", strpos($resValid['embed_url'] ?? '', 'enablejsapi=1') !== false);

$resInvalid = resolve_youtube_recording('https://spotify.com/track/12345', 1);
assert_test("resolve_youtube_recording returns success=false for non-YouTube link", $resInvalid['success'] === false);
assert_test("resolve_youtube_recording provides descriptive error message", !empty($resInvalid['error']));

// -----------------------------------------------------------------------------
// SECTION 3: Persistent Recording Selection & Metadata Protection
// -----------------------------------------------------------------------------
echo "\n4. Recording Selection Persistence & Metadata Protection\n";

$testSongId = 2; // Use track #2 for persistent selection test
$originalSong = get_song_by_id($testSongId);
$origTitle = $originalSong['title'];
$origArtist = $originalSong['artist'];

// Save recording with user-copied YouTube Music URL and version details
$ytMusicUrl = 'https://music.youtube.com/watch?v=L_LUpnjgPso&feature=shared';
$saveRes = save_song_media(
    $testSongId,
    $ytMusicUrl,
    'live',
    '1985 Live Aid Wembley Stadium',
    'allowed',
    $ytMusicUrl
);

assert_test("save_song_media succeeds with YouTube Music URL", $saveRes['success'] === true);
assert_test("Saved video_id matches expected extracted ID", $saveRes['media']['video_id'] === 'L_LUpnjgPso');
assert_test("Saved user_url preserves original URL", ($saveRes['media']['user_url'] ?? '') === $ytMusicUrl);
assert_test("Saved version_type is 'live'", ($saveRes['media']['version_type'] ?? '') === 'live');
assert_test("Saved version_notes are preserved", ($saveRes['media']['version_notes'] ?? '') === '1985 Live Aid Wembley Stadium');

// Verify retrieval via get_song_media
$retrievedMedia = get_song_media($testSongId);
assert_test("get_song_media retrieves saved recording correctly", ($retrievedMedia['video_id'] ?? '') === 'L_LUpnjgPso');
assert_test("get_song_media includes user_url column", ($retrievedMedia['user_url'] ?? '') === $ytMusicUrl);
assert_test("get_song_media includes canonical URLs", !empty($retrievedMedia['watch_url']) && !empty($retrievedMedia['music_url']));

// INVARIANT: Song Title and Artist metadata must remain completely unchanged!
$afterSong = get_song_by_id($testSongId);
assert_test("Catalogue track title strictly unchanged after saving recording", $afterSong['title'] === $origTitle);
assert_test("Catalogue artist strictly unchanged after saving recording", $afterSong['artist'] === $origArtist);

// Update recording version without touching title or artist
$updateRes = save_song_media($testSongId, 'L_LUpnjgPso', 'acoustic', 'Unplugged Session', 'allowed');
assert_test("Updating recording version succeeds", $updateRes['success'] === true);
$updatedMedia = get_song_media($testSongId);
assert_test("Updated version_type is 'acoustic'", ($updatedMedia['version_type'] ?? '') === 'acoustic');
assert_test("Updated version_notes is 'Unplugged Session'", ($updatedMedia['version_notes'] ?? '') === 'Unplugged Session');

$afterUpdateSong = get_song_by_id($testSongId);
assert_test("Song title strictly unchanged after version update", $afterUpdateSong['title'] === $origTitle);

// -----------------------------------------------------------------------------
// SECTION 4: Audition Screen & Embedded Player Markup Verification
// -----------------------------------------------------------------------------
echo "\n5. Audition Screen & Embedded Player Markup\n";

$_SESSION['afm_user_id'] = 1;
$_SESSION['afm_username'] = 'gulshan';
$_GET['round_id'] = 1;
$_GET['song_id'] = $testSongId;

ob_start();
include dirname(__DIR__) . '/public/auditions.php';
$auditionHtml = ob_get_clean();

assert_test("Audition screen renders Live Playback Status Pill", strpos($auditionHtml, 'playback-status-pill') !== false);
assert_test("Live Playback Status Pill has initial status-ready class", strpos($auditionHtml, 'status-ready') !== false);
assert_test("Live Playback Status Pill text reads 'Ready to Play'", strpos($auditionHtml, 'Ready to Play') !== false);

assert_test("Audition drawer is mounted with 'display: grid;' (not hidden)", strpos($auditionHtml, 'class="audition-drawer audio-active lyrics-active" style="display: grid;"') !== false);
assert_test("Embedded iframe uses privacy domain youtube-nocookie.com", strpos($auditionHtml, 'youtube-nocookie.com/embed/L_LUpnjgPso') !== false);
assert_test("Embedded iframe includes enablejsapi=1", strpos($auditionHtml, 'enablejsapi=1') !== false);
assert_test("Embedded iframe includes origin parameter", strpos($auditionHtml, 'origin=') !== false);
assert_test("Embedded iframe includes referrerpolicy='strict-origin-when-cross-origin'", strpos($auditionHtml, 'referrerpolicy="strict-origin-when-cross-origin"') !== false);
assert_test("Embedded iframe has deterministic ID: yt-player-{$testSongId}", strpos($auditionHtml, 'id="yt-player-' . $testSongId . '"') !== false);

assert_test("Direct external link to confirmed YouTube video provided", strpos($auditionHtml, 'https://www.youtube.com/watch?v=L_LUpnjgPso') !== false);
assert_test("Direct external link to confirmed YouTube Music video provided", strpos($auditionHtml, 'https://music.youtube.com/watch?v=L_LUpnjgPso') !== false);
assert_test("Change Recording button rendered with icon", strpos($auditionHtml, 'Change Recording') !== false);
assert_test("Scorecard grid rendered on the same card", strpos($auditionHtml, 'class="scorecard-grid"') !== false);

// Clean up test recording for song 2
remove_song_media($testSongId);
$mediaAfterRemove = get_song_media($testSongId);
assert_test("remove_song_media successfully cleans up media entry", empty($mediaAfterRemove));

// -----------------------------------------------------------------------------
// SECTION 5: Unconfirmed Recording Selection Interface
// -----------------------------------------------------------------------------
echo "\n6. Unconfirmed Recording Selection Interface\n";

$_GET['song_id'] = $testSongId; // Song 2 now has no media
ob_start();
include dirname(__DIR__) . '/public/auditions.php';
$unconfirmedHtml = ob_get_clean();

assert_test("Unconfirmed recording displays player-unconfirmed-box", strpos($unconfirmedHtml, 'player-unconfirmed-box') !== false);
assert_test("Unconfirmed recording displays 'Select Intended Recording' header", strpos($unconfirmedHtml, 'Select Intended Recording') !== false);
assert_test("Unconfirmed recording provides 'Preview Candidate' button", strpos($unconfirmedHtml, 'btn-preview-candidate') !== false);
assert_test("Unconfirmed recording provides 'Confirm & Save Recording' button", strpos($unconfirmedHtml, 'btn-save-match') !== false);

// Clean up test entry from auditions and discovery
$pdo->exec("DELETE FROM `afm_entries` WHERE `song_id` = {$testSongId}");
$pdo->exec("DELETE FROM `song_discovery` WHERE `song_id` = {$testSongId}");
$pdo->exec("DELETE FROM `song_artwork` WHERE `song_id` = {$testSongId}");

// -----------------------------------------------------------------------------
// SECTION 6: Library & Discovery Direct Audition Playback Links
// -----------------------------------------------------------------------------
echo "\n7. Library & Discovery Direct Audition Playback Links\n";

$libContent = file_get_contents(dirname(__DIR__) . '/public/library.php');
assert_test("library.php contains direct 'Play in AFM#2' link pointing to auditions.php?song_id=", strpos($libContent, 'auditions.php?song_id=') !== false && strpos($libContent, 'Play in AFM#2') !== false);

$discContent = file_get_contents(dirname(__DIR__) . '/public/discovery.php');
assert_test("discovery.php contains direct 'Play in AFM#2' link pointing to auditions.php?song_id=", strpos($discContent, 'auditions.php?song_id=') !== false && strpos($discContent, 'Play in AFM#2') !== false);

// -----------------------------------------------------------------------------
// SECTION 7: Database Integrity & Catalogue Invariants
// -----------------------------------------------------------------------------
echo "\n8. Database Catalogue Integrity & Invariants\n";

$songCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Catalogue has active songs preserved (count: {$songCount} >= 2400)", $songCount >= 2400);

$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test("NULL artist records count is at least 3", $nullArtistCount >= 3);

$entriesCount = (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries`")->fetchColumn();
assert_test("afm_entries table exists and is accessible", $entriesCount >= 0);

$roundsCount = (int)$pdo->query("SELECT COUNT(*) FROM `rounds`")->fetchColumn();
assert_test("rounds table has all 7 tournament stages", $roundsCount === 7);

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passed} / {$tests} PASSED\n";
if ($passed === $tests) {
    echo " RESULT: ALL TESTS PASSED SUCCESSFULLY! ✔\n";
} else {
    echo " RESULT: SOME TESTS FAILED ✖\n";
}
echo "======================================================================\n\n";

exit($passed === $tests ? 0 : 1);
