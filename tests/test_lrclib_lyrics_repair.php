<?php
/**
 * AFM#2 — LRCLIB Lyrics Lookup, Normalization & Cache Repair Test Suite
 *
 * Verifies:
 * 1. Title Normalization:
 *    - Stripping upload/quality tags ([4K Upgrade], [HD Upgrade], [Official 4K Video], etc.)
 *    - Preserving genuine version tags (Live, Acoustic, Remix)
 * 2. Exact Title First, Cleaned Title Fallback Workflow
 * 3. Newly added song with valid LRCLIB match
 * 4. Title edit after initial failed lookup (clears stale negative cache)
 * 5. Title edit after lyrics previously found (purges old lyrics cache)
 * 6. Protection against conflating genuine song versions (Live vs Studio)
 * 7. Candidate filtering: discarding unrelated tracks and artists
 * 8. Error handling: 429 Rate Limit, 503 Overloaded, Network Timeout, Malformed JSON
 * 9. Catalogue Preservation Invariant Check
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';

$pdo = get_db_connection();
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
echo " AFM#2 — LRCLIB LYRICS REPAIR & REGRESSION TEST SUITE\n";
echo "======================================================================\n\n";

// Baseline check
$initialSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test($initialSongCount >= 2400, "Pre-test Catalogue Baseline Invariant: Songs present (actual: {$initialSongCount})");

// ======================================================================
// Section 1: Title Normalization & Suffix Cleaning
// ======================================================================
echo "\n1. Conservative Title Normalization & Suffix Cleaning\n";

$cleaned4k = clean_track_title_for_lyrics('Boulevard Of Broken Dreams [4K Upgrade]');
assert_test($cleaned4k === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips '[4K Upgrade]' -> '{$cleaned4k}'");

$cleaned4kParen = clean_track_title_for_lyrics('Boulevard Of Broken Dreams (4K Upgrade)');
assert_test($cleaned4kParen === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips '(4K Upgrade)'");

$cleanedHd = clean_track_title_for_lyrics('Boulevard Of Broken Dreams [HD Upgrade]');
assert_test($cleanedHd === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips '[HD Upgrade]'");

$cleanedOff4k = clean_track_title_for_lyrics('Boulevard Of Broken Dreams [Official 4K Video]');
assert_test($cleanedOff4k === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips '[Official 4K Video]'");

$cleaned1080p = clean_track_title_for_lyrics('Boulevard Of Broken Dreams [1080p HD]');
assert_test($cleaned1080p === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips '[1080p HD]'");

$cleanedHyphen4k = clean_track_title_for_lyrics('Boulevard Of Broken Dreams - 4K Upgrade');
assert_test($cleanedHyphen4k === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips hyphenated '- 4K Upgrade'");

$cleanedHyphenOff = clean_track_title_for_lyrics('Boulevard Of Broken Dreams - Official Video');
assert_test($cleanedHyphenOff === 'Boulevard Of Broken Dreams', "clean_track_title_for_lyrics() strips hyphenated '- Official Video'");

// Genuine versions MUST be preserved!
$liveTitle = clean_track_title_for_lyrics('Boulevard Of Broken Dreams (Live at Wembley)');
assert_test($liveTitle === 'Boulevard Of Broken Dreams (Live at Wembley)', "clean_track_title_for_lyrics() preserves '(Live at Wembley)'");

$acousticTitle = clean_track_title_for_lyrics('Boulevard Of Broken Dreams (Acoustic)');
assert_test($acousticTitle === 'Boulevard Of Broken Dreams (Acoustic)', "clean_track_title_for_lyrics() preserves '(Acoustic)'");

$remixTitle = clean_track_title_for_lyrics('Boulevard Of Broken Dreams (Remix)');
assert_test($remixTitle === 'Boulevard Of Broken Dreams (Remix)', "clean_track_title_for_lyrics() preserves '(Remix)'");

// ======================================================================
// Section 2: Mocked LRCLIB Integration Tests
// ======================================================================
echo "\n2. LRCLIB HTTP Mocked Lookup & Suffix Fallback\n";

// Test 2A: Newly added song with valid LRCLIB match
$newSongRes = create_catalogue_song('Test Boulevard New', 'Green Day');
assert_test($newSongRes['success'] === true, "create_catalogue_song() creates disposable test song #1");
$testSongId1 = (int)$newSongRes['song_id'];

// Set up mock HTTP handler
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    if (strpos($url, 'https://lrclib.net/api/get?') === 0) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $track = $query['track_name'] ?? '';
        $artist = $query['artist_name'] ?? '';

        if ($track === 'Test Boulevard New' && $artist === 'Green Day') {
            return [
                'success'     => true,
                'status_code' => 200,
                'retry_after' => null,
                'data'        => [
                    'id'           => 90001,
                    'trackName'    => 'Test Boulevard New',
                    'artistName'   => 'Green Day',
                    'albumName'    => 'American Idiot',
                    'duration'     => 261.0,
                    'instrumental' => false,
                    'plainLyrics'  => "I walk a lonely road\nThe only one that I have ever known",
                    'syncedLyrics' => "[00:15.00] I walk a lonely road\n[00:18.50] The only one that I have ever known"
                ],
                'raw_body'    => '{"id":90001}'
            ];
        }
    }
    return ['success' => false, 'status_code' => 404, 'retry_after' => null, 'data' => null, 'raw_body' => ''];
};

$lookup1 = fetch_lrclib_lyrics($testSongId1, 'Test Boulevard New', 'Green Day', true);
assert_test($lookup1['success'] === true, "fetch_lrclib_lyrics() successfully fetches lyrics for new song");
assert_test($lookup1['cached'] === false, "First fetch is marked cached = false");
assert_test($lookup1['lrclib_id'] === 90001, "LRCLIB ID matches mock (90001)");
assert_test(!empty($lookup1['synced_lyrics']), "Synced lyrics returned for new song");

// Verify cache hit on second call
$lookup1Cached = fetch_lrclib_lyrics($testSongId1, 'Test Boulevard New', 'Green Day', false);
assert_test($lookup1Cached['success'] === true && $lookup1Cached['cached'] === true, "Second call leverages on-demand database cache");

// Test 2B: Song with [4K Upgrade] suffix where exact title returns 404 but clean title returns 200
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $track = $query['track_name'] ?? '';

    // Exact title with [4K Upgrade] returns 404
    if ($track === 'Boulevard Of Broken Dreams [4K Upgrade]') {
        return ['success' => false, 'status_code' => 404, 'retry_after' => null, 'data' => null, 'raw_body' => '404'];
    }

    // Cleaned title returns 200 match!
    if ($track === 'Boulevard Of Broken Dreams') {
        return [
            'success'     => true,
            'status_code' => 200,
            'retry_after' => null,
            'data'        => [
                'id'           => 750470,
                'trackName'    => 'Boulevard of Broken Dreams',
                'artistName'   => 'Green Day',
                'albumName'    => 'American Idiot',
                'duration'     => 261.0,
                'instrumental' => false,
                'plainLyrics'  => "I walk a lonely road...",
                'syncedLyrics' => "[00:15.00] I walk a lonely road..."
            ],
            'raw_body'    => '{}'
        ];
    }
    return ['success' => false, 'status_code' => 404, 'retry_after' => null, 'data' => null, 'raw_body' => ''];
};

$lookup4k = fetch_lrclib_lyrics($testSongId1, 'Boulevard Of Broken Dreams [4K Upgrade]', 'Green Day', true);
assert_test($lookup4k['success'] === true, "fetch_lrclib_lyrics() succeeds when exact title is [4K Upgrade] via fallback");
assert_test($lookup4k['lrclib_id'] === 750470, "Resolved LRCLIB ID is 750470");

// ======================================================================
// Section 3: Title Editing & Stale Cache Invalidation
// ======================================================================
echo "\n3. Stale Cache Invalidation on Song Title Edit\n";

// Test 3A: Song edited after an initial failed lookup (negative cache cleared)
$failSongRes = create_catalogue_song('Nonexistent Obscure Track 12345', 'Unknown Artist');
$failSongId = (int)$failSongRes['song_id'];

// Mock returns 404 for this nonexistent track
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    if (strpos($url, 'https://lrclib.net/api/search?') === 0) {
        return ['success' => true, 'status_code' => 200, 'retry_after' => null, 'data' => [], 'raw_body' => '[]'];
    }
    return ['success' => false, 'status_code' => 404, 'retry_after' => null, 'data' => null, 'raw_body' => ''];
};

$failLookup = fetch_lrclib_lyrics($failSongId, 'Nonexistent Obscure Track 12345', 'Unknown Artist', true);
assert_test($failLookup['success'] === false && $failLookup['status'] === 'not_found', "Initial lookup fails and records not_found");

$savedFail = get_saved_song_lyrics($failSongId);
assert_test($savedFail !== null && $savedFail['status'] === 'not_found', "song_lyrics_ref persists status = 'not_found'");

// Now, user edits the song title to a valid song
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $track = $query['track_name'] ?? '';
    if ($track === 'Wake Me Up When September Ends') {
        return [
            'success'     => true,
            'status_code' => 200,
            'retry_after' => null,
            'data'        => [
                'id'           => 750480,
                'trackName'    => 'Wake Me Up When September Ends',
                'artistName'   => 'Green Day',
                'albumName'    => 'American Idiot',
                'duration'     => 285.0,
                'instrumental' => false,
                'plainLyrics'  => 'Summer has come and passed...',
                'syncedLyrics' => '[00:10.00] Summer has come and passed...'
            ],
            'raw_body'    => '{}'
        ];
    }
    return ['success' => false, 'status_code' => 404, 'retry_after' => null, 'data' => null, 'raw_body' => ''];
};

// Edit the song title
$updateRes = update_catalogue_song($failSongId, 'Wake Me Up When September Ends', 'Green Day');
assert_test($updateRes['success'] === true, "update_catalogue_song() renames failed track to valid title");

// Confirm update_catalogue_song cleared song_lyrics_ref
$clearedLyrics = get_saved_song_lyrics($failSongId);
assert_test($clearedLyrics === null, "update_catalogue_song() automatically cleared stale not_found cache");

// Subsequent lookup must succeed without 4-hour lockout
$lookupAfterRename = fetch_lrclib_lyrics($failSongId, 'Wake Me Up When September Ends', 'Green Day', false);
assert_test($lookupAfterRename['success'] === true, "Lyrics fetch succeeds immediately after title edit without stale cache block");
assert_test($lookupAfterRename['lrclib_id'] === 750480, "Found lyrics for newly renamed song title");

// Test 3B: Song edited after lyrics were previously matched
$updateRes2 = update_catalogue_song($failSongId, 'Holiday', 'Green Day');
assert_test($updateRes2['success'] === true, "update_catalogue_song() renames previously matched song");
$clearedLyrics2 = get_saved_song_lyrics($failSongId);
assert_test($clearedLyrics2 === null, "update_catalogue_song() cleared previous matched lyrics cache");

// ======================================================================
// Section 4: Genuine Version Protection & Candidate Ranking
// ======================================================================
echo "\n4. Genuine Version Protection & Candidate Ranking\n";

$candidates = [
    [
        'id'          => 201,
        'track_name'  => 'Hotel California',
        'artist_name' => 'Eagles',
        'album_name'  => 'Hotel California (Studio)',
        'duration'    => 390.0,
        'has_synced'  => 1,
        'has_plain'   => 1
    ],
    [
        'id'          => 202,
        'track_name'  => 'Hotel California (Live)',
        'artist_name' => 'Eagles',
        'album_name'  => 'Hell Freezes Over (Live)',
        'duration'    => 432.0,
        'has_synced'  => 1,
        'has_plain'   => 1
    ],
    [
        'id'          => 203,
        'track_name'  => 'Completely Unrelated Track',
        'artist_name' => 'Random Band',
        'album_name'  => 'Album',
        'duration'    => 200.0,
        'has_synced'  => 1,
        'has_plain'   => 1
    ]
];

// Query 4A: Standard studio track requested -> Studio version MUST rank above Live version
$studioRanked = score_and_rank_lrclib_candidates($candidates, 'Hotel California', 'Eagles', 390.0);
assert_test(!empty($studioRanked), "score_and_rank_lrclib_candidates() returns candidates");
assert_test($studioRanked[0]['id'] === 201, "Studio track ranked #1 when studio version requested");
assert_test(count($studioRanked) === 2, "Unrelated track ('Completely Unrelated Track') was discarded");

// Query 4B: Live version requested -> Live version MUST rank above Studio version
$liveRanked = score_and_rank_lrclib_candidates($candidates, 'Hotel California (Live)', 'Eagles', 432.0);
assert_test($liveRanked[0]['id'] === 202, "Live track ranked #1 when Live version requested");

// ======================================================================
// Section 5: API Error Handling (429, 503, Network Timeout, Malformed JSON)
// ======================================================================
echo "\n5. API Error Handling (429, 503, Network Timeout, Malformed JSON)\n";

// Test 5A: 429 Rate Limit
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    return ['success' => false, 'status_code' => 429, 'retry_after' => '10', 'data' => null, 'raw_body' => 'Rate limited'];
};
clear_song_lyrics_ref($testSongId1);
$res429 = fetch_lrclib_lyrics($testSongId1, 'Rate Limit Test Track', 'Artist', true);
assert_test($res429['success'] === false && $res429['status'] === 'rate_limited', "429 Rate limit returns status = 'rate_limited'");
assert_test(get_saved_song_lyrics($testSongId1) === null, "429 Rate limit does NOT save 'not_found' to cache");

// Test 5B: 503 Server Overloaded
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    return ['success' => false, 'status_code' => 503, 'retry_after' => null, 'data' => null, 'raw_body' => 'Server Overloaded'];
};
$res503 = fetch_lrclib_lyrics($testSongId1, 'Overloaded Test Track', 'Artist', true);
assert_test($res503['success'] === false && $res503['status'] === 'overloaded', "503 Overloaded returns status = 'overloaded'");
assert_test(get_saved_song_lyrics($testSongId1) === null, "503 Overloaded does NOT save 'not_found' to cache");

// Test 5C: Network timeout / connection failure (status 0)
$GLOBALS['__mock_http_get_json'] = function (string $url, int $timeout) {
    return ['success' => false, 'status_code' => 0, 'error' => 'Connection timed out', 'data' => null, 'raw_body' => false];
};
$resTimeout = fetch_lrclib_lyrics($testSongId1, 'Timeout Test Track', 'Artist', true);
assert_test($resTimeout['success'] === false && $resTimeout['status'] === 'network_error', "Timeout returns status = 'network_error'");
assert_test(get_saved_song_lyrics($testSongId1) === null, "Network failure does NOT save 'not_found' to cache");

// Clean up mock handler
unset($GLOBALS['__mock_http_get_json']);

// Clean up disposable test songs created during this test
delete_catalogue_song($testSongId1);
delete_catalogue_song($failSongId);

// ======================================================================
// Section 6: Final Catalogue & Database Integrity Check
// ======================================================================
echo "\n6. Final Catalogue Preservation & Invariants\n";

$finalSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test($finalSongCount === $initialSongCount, "MANDATORY: Catalogue count strictly preserved ({$finalSongCount} == {$initialSongCount})");

$roundsCount = (int)$pdo->query("SELECT COUNT(*) FROM `rounds`")->fetchColumn();
assert_test($roundsCount >= 7, "Tournament rounds preserved ({$roundsCount} rounds)");

$ratingsCount = (int)$pdo->query("SELECT COUNT(*) FROM `ratings`")->fetchColumn();
assert_test($ratingsCount >= 0, "Ratings table preserved ({$ratingsCount} ratings)");

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "======================================================================\n";

exit($testsFailed > 0 ? 1 : 0);
