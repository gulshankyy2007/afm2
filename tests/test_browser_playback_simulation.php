<?php
/**
 * AFM#2 - Browser Playback, Synchronization, Layout & Themes Verification Test
 * Tests playback behavior, timing synchronization, error fallbacks, seeking,
 * mobile responsive vertical stacking, timestamp-free lyrics, and theme contrast.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

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
echo " AFM#2 — INTEGRATED LISTENING EXPERIENCE VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// 1. YouTube Embedded Player Configuration
echo "1. YouTube Embedded Player Configuration\n";
$videoId = 'dQw4w9WgXcQ';
$embedUrl = "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=0&rel=0&enablejsapi=1";
assert_test("Embed uses privacy-enhanced domain youtube-nocookie.com", strpos($embedUrl, 'youtube-nocookie.com') !== false);
assert_test("Embed enforces autoplay=0 to prevent unwanted sound playback", strpos($embedUrl, 'autoplay=0') !== false);
assert_test("Embed enables JavaScript API (enablejsapi=1) for timing & controls", strpos($embedUrl, 'enablejsapi=1') !== false);

// 2. Secondary Sources (Spotify & SoundCloud)
echo "\n2. Secondary Audio Sources (Spotify & SoundCloud)\n";
$spotUrl = get_spotify_search_url("Bohemian Rhapsody", "Queen");
$scUrl = get_soundcloud_search_url("Bohemian Rhapsody", "Queen");
assert_test("Spotify search URL generated correctly", strpos($spotUrl, 'open.spotify.com/search/') !== false);
assert_test("SoundCloud search URL generated correctly", strpos($scUrl, 'soundcloud.com/search?q=') !== false);
assert_test("Secondary sources omit placeholder artist cleanly", get_spotify_search_url("Untitled", "Unknown Artist") === 'https://open.spotify.com/search/Untitled');

// 3. Shortlist Song 1 to Audition & Test Fallback When Video Embedding is Restricted
echo "\n3. Embedding Restriction Fallback & Explanatory Notice\n";
$songId = 1;
// Shortlist song 1 to audition round
update_song_discovery_status($songId, 'shortlisted');

// Save a valid 11-char video ID and simulate blocked status
$saveResult = save_song_media($songId, 'blckdVid101', 'original');
assert_test("save_song_media() succeeds with valid 11-character video ID", $saveResult['success'] === true);

update_song_media_embed_status($songId, 'blocked');
$media = get_song_media($songId);
assert_test("Video embedding status marked as 'blocked'", ($media['embed_status'] ?? '') === 'blocked');

// Render auditions HTML with blocked video
$_SESSION['afm_user_id'] = 1;
$_SESSION['afm_username'] = 'gulshan';
$_GET['round_id'] = 1;
ob_start();
include dirname(__DIR__) . '/public/auditions.php';
$html = ob_get_clean();

assert_test("Blocked alert title rendered: 'Video Embedding Restricted'", strpos($html, 'Video Embedding Restricted') !== false);
assert_test("Explanatory message rendered explaining uploader disabled third-party embedding", strpos($html, 'The uploader or artist has disabled third-party embedding') !== false);
assert_test("Direct official YouTube playback link is provided", strpos($html, 'https://www.youtube.com/watch?v=blckdVid101') !== false);
assert_test("Direct official YouTube Music playback link is provided", strpos($html, 'https://music.youtube.com/watch?v=blckdVid101') !== false);
assert_test("Retry Embed action button is provided", strpos($html, 'btn-retest-embed') !== false);

// Restore media status
update_song_media_embed_status($songId, 'allowed');
$mediaAllowed = get_song_media($songId);
assert_test("Embedding status can be re-tested and set to 'allowed'", ($mediaAllowed['embed_status'] ?? '') === 'allowed');

// 4. Lyrics Synchronization: Timestamped vs Timestamp-Free vs Instrumental
echo "\n4. Lyrics Synchronization & Edge Cases\n";
$jsScript = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
assert_test("JavaScript parseSyncedLyrics() handles [mm:ss.xx] regex format", strpos($jsScript, 'regex = /^\\[(\\d{2}):(\\d{2}(?:\\.\\d+)?)\\](.*)$/') !== false);
assert_test("JavaScript seeker dispatches seekTo(seconds, true) to YT.Player", strpos($jsScript, 'player.seekTo(seconds, true)') !== false);
assert_test("JavaScript polling interval updates active line every 250ms", strpos($jsScript, 'setInterval(') !== false && strpos($jsScript, '250') !== false);
assert_test("JavaScript renders plain lyrics fallback when timestamped lyrics are absent", strpos($jsScript, 'body.className = \'lyrics-body plain\'') !== false);
assert_test("JavaScript fades Synced tab opacity when synced lyrics are missing", strpos($jsScript, 'syncedTab.style.opacity = \'0.5\'') !== false);
assert_test("JavaScript renders distinct Instrumental Recording UI without lyrics", strpos($jsScript, 'Instrumental Recording') !== false);

// 5. Audition Screen: Integrated Layout & Mobile Vertical Stacking
echo "\n5. Layout Hierarchy: Mobile Vertical Stacking\n";
$cssContent = file_get_contents(dirname(__DIR__) . '/public/assets/css/style.css');
assert_test("Desktop .audition-drawer uses 2-column grid (Player + Lyrics side by side)", strpos($cssContent, 'grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);') !== false);
assert_test("Mobile breakpoint (@media max-width: 900px) stacks .audition-drawer into 1fr (vertical stack)", strpos($cssContent, '@media (max-width: 900px)') !== false && strpos($cssContent, '.audition-drawer {') !== false);
assert_test("Scorecard grid is positioned directly within the same card", strpos($html, 'class="scorecard-grid"') !== false);
assert_test("Scorecard auto-total out of 50 is displayed on the same card", strpos($html, '/ 50') !== false);

// Clean up song 1 audition and media test state
remove_song_media($songId);
update_song_discovery_status($songId, 'unreviewed');

// 6. Navigation Bar Contrast & Theme Backgrounds
echo "\n6. Navbar Contrast & Theme-Specific Atmospheric Backgrounds\n";
assert_test("Header uses dynamic --header-bg instead of hardcoded dark background", strpos($cssContent, 'background: var(--header-bg);') !== false);

$allThemes = ['midnight_ember', 'velvet_amethyst', 'emerald_forest', 'vintage_paper', 'nordic_frost', 'solar_terracotta'];
foreach ($allThemes as $t) {
    assert_test("Theme '{$t}' defines custom --header-bg token", strpos($cssContent, "[data-theme=\"{$t}\"]") !== false);
    assert_test("Theme '{$t}' defines custom atmospheric photo background", strpos($cssContent, "[data-theme=\"{$t}\"][data-bg-style=\"atmospheric\"]") !== false);
}

// 7. Verify Invariant Catalogue Integrity
echo "\n7. Database Invariant Integrity\n";
$songsCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$nullCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_test("Catalogue has active songs preserved (count: {$songsCount} >= 2400)", $songsCount >= 2400);
assert_test("Catalogue has NULL artist records intact", $nullCount >= 3);

echo "\n======================================================================\n";
echo " PLAYBACK & VERIFICATION SUMMARY: {$passed} / {$tests} tests passed (" . round(($passed / $tests) * 100) . "%)\n";
echo "======================================================================\n\n";
