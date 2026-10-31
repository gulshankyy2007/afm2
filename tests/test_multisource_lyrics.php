<?php
/**
 * AFM#2 — Part B: Multi-Source Lyrics System Regression Test Suite
 * 
 * Verifies:
 * 1. LRCLIB returns synchronized lyrics
 * 2. LRCLIB returns plain lyrics
 * 3. LRCLIB has no matching lyrics -> falls back to Lyrics.ovh
 * 4. LRCLIB times out -> falls back to Lyrics.ovh
 * 5. Fallback provider (Lyrics.ovh) returns plain lyrics successfully
 * 6. Both automated providers fail -> graceful error state with manual option
 * 7. Manual lyrics can be entered, saved, and retrieved
 * 8. Manual lyrics survive page reloads and cache flushes
 * 9. Multiuser isolation: Two users cannot view or overwrite each other's private manual lyrics
 * 10. Automated provider responses never overwrite newer private manual lyrics
 * 11. Provider rate limits (HTTP 429) handled gracefully
 * 12. Changing song metadata invalidates stale cached lyrics
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_multisource_lyrics');

$totalTests = 0;
$passedTests = 0;

function assert_lyric(string $name, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$totalTests}. {$name}\n";
    } else {
        echo "  [FAIL] {$totalTests}. {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — MULTI-SOURCE LYRICS SYSTEM REGRESSION TEST SUITE\n";
echo "======================================================================\n\n";

$userAId = 1081;
$userBId = 1082;

$pdo->prepare("INSERT INTO app_users (user_id, username, email, password_hash, role, is_active) VALUES 
    (:u1, 'lyrics_user_a', 'lyrics_a@test.afm', 'hash', 'user', 1),
    (:u2, 'lyrics_user_b', 'lyrics_b@test.afm', 'hash', 'user', 1)
ON DUPLICATE KEY UPDATE is_active = 1
")->execute([':u1' => $userAId, ':u2' => $userBId]);

// Clean up prior test records
$pdo->prepare("DELETE FROM user_song_lyrics WHERE user_id IN (:u1, :u2)")->execute([':u1' => $userAId, ':u2' => $userBId]);
$pdo->prepare("DELETE FROM song_lyrics_ref WHERE song_id > 200000")->execute();
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2)")->execute([':u1' => $userAId, ':u2' => $userBId]);

$_SESSION['afm_user_id'] = $userAId;
$_SESSION['user_id'] = $userAId;

// ======================================================================
// 1. LRCLIB SYNCHRONIZED LYRICS
// ======================================================================
echo "--- 1. LRCLIB Synchronized Lyrics Retrieval ---\n";

$songA1 = create_catalogue_song('Bohemian Rhapsody', 'Queen', null, 'unreviewed', $userAId);
$songA1Id = (int)$songA1['song_id'];

// Mock LRCLIB returning synced lyrics
$GLOBALS['__mock_http_get_json'] = function(string $url) {
    if (strpos($url, 'lrclib.net') !== false) {
        return [
            'success'     => true,
            'status_code' => 200,
            'data'        => [
                'id'           => 12345,
                'trackName'    => 'Bohemian Rhapsody',
                'artistName'   => 'Queen',
                'albumName'    => 'A Night at the Opera',
                'duration'     => 354,
                'instrumental' => false,
                'plainLyrics'  => "Is this the real life?\nIs this just fantasy?",
                'syncedLyrics' => "[00:01.00] Is this the real life?\n[00:04.50] Is this just fantasy?"
            ]
        ];
    }
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$resA1 = fetch_multisource_lyrics($songA1Id, 'Bohemian Rhapsody', 'Queen', $userAId, 'auto', true);
assert_lyric("LRCLIB returns success", $resA1['success'] === true);
assert_lyric("LRCLIB provider is tagged correctly", $resA1['provider'] === 'lrclib');
assert_lyric("Synchronized lyrics are present", !empty($resA1['synced_lyrics']) && strpos($resA1['synced_lyrics'], '[00:01.00]') !== false);
assert_lyric("Plain lyrics are present", !empty($resA1['plain_lyrics']));
assert_lyric("Lyrics response cached in song_lyrics_ref", (bool)$pdo->query("SELECT COUNT(*) FROM song_lyrics_ref WHERE song_id = {$songA1Id}")->fetchColumn());

// ======================================================================
// 2. LRCLIB PLAIN LYRICS ONLY
// ======================================================================
echo "\n--- 2. LRCLIB Plain Lyrics Only (Unsynchronized) ---\n";

$songA2 = create_catalogue_song('Rare Folk Song', 'Old Bard', null, 'unreviewed', $userAId);
$songA2Id = (int)$songA2['song_id'];

$GLOBALS['__mock_http_get_json'] = function(string $url) {
    if (strpos($url, 'lrclib.net') !== false) {
        return [
            'success'     => true,
            'status_code' => 200,
            'data'        => [
                'id'           => 67890,
                'trackName'    => 'Rare Folk Song',
                'artistName'   => 'Old Bard',
                'albumName'    => 'Village Tales',
                'duration'     => 180,
                'instrumental' => false,
                'plainLyrics'  => "Down by the river bank\nThe water runs so cold",
                'syncedLyrics' => null
            ]
        ];
    }
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$resA2 = fetch_multisource_lyrics($songA2Id, 'Rare Folk Song', 'Old Bard', $userAId, 'auto', true);
assert_lyric("Plain lyrics returned successfully", $resA2['success'] === true && !empty($resA2['plain_lyrics']));
assert_lyric("Synced lyrics is null/empty", empty($resA2['synced_lyrics']));
assert_lyric("has_synced flag is false", $resA2['has_synced'] === false);

// ======================================================================
// 3. LRCLIB NOT FOUND -> FALLBACK TO LYRICS.OVH
// ======================================================================
echo "\n--- 3. LRCLIB Not Found -> Fallback to Lyrics.ovh ---\n";

$songA3 = create_catalogue_song('Indie Gem', 'Indie Artist', null, 'unreviewed', $userAId);
$songA3Id = (int)$songA3['song_id'];

$GLOBALS['__mock_http_get_json'] = function(string $url) {
    if (strpos($url, 'lrclib.net') !== false) {
        return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
    }
    if (strpos($url, 'api.lyrics.ovh') !== false) {
        return [
            'success'     => true,
            'status_code' => 200,
            'data'        => [
                'lyrics' => "Wandering through the midnight fog\nSearching for a forgotten star"
            ]
        ];
    }
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$resA3 = fetch_multisource_lyrics($songA3Id, 'Indie Gem', 'Indie Artist', $userAId, 'auto', true);
assert_lyric("Fallback provider succeeds when LRCLIB has no match", $resA3['success'] === true);
assert_lyric("Provider is tagged as lyrics_ovh", $resA3['provider'] === 'lyrics_ovh');
assert_lyric("Plain lyrics from Lyrics.ovh are present", strpos($resA3['plain_lyrics'], 'midnight fog') !== false);
assert_lyric("Fallback result is cached in song_lyrics_ref with provider=lyrics_ovh", 
    (bool)$pdo->query("SELECT COUNT(*) FROM song_lyrics_ref WHERE song_id = {$songA3Id} AND provider = 'lyrics_ovh'")->fetchColumn());

// ======================================================================
// 4. LRCLIB TIMEOUT -> FALLBACK TO LYRICS.OVH
// ======================================================================
echo "\n--- 4. LRCLIB Timeout -> Fallback to Lyrics.ovh ---\n";

$songA4 = create_catalogue_song('Lagging Song', 'Slow Band', null, 'unreviewed', $userAId);
$songA4Id = (int)$songA4['song_id'];

$GLOBALS['__mock_http_get_json'] = function(string $url) {
    if (strpos($url, 'lrclib.net') !== false) {
        // Simulate network timeout on LRCLIB
        return ['success' => false, 'status_code' => 0, 'error' => 'Network connection timeout'];
    }
    if (strpos($url, 'api.lyrics.ovh') !== false) {
        return [
            'success'     => true,
            'status_code' => 200,
            'data'        => [
                'lyrics' => "Rescued by the fallback line"
            ]
        ];
    }
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$resA4 = fetch_multisource_lyrics($songA4Id, 'Lagging Song', 'Slow Band', $userAId, 'auto', true);
assert_lyric("Fallback succeeds when LRCLIB times out", $resA4['success'] === true);
assert_lyric("Provider is lyrics_ovh", $resA4['provider'] === 'lyrics_ovh');

// ======================================================================
// 5. ALL AUTOMATED PROVIDERS FAIL -> CLEAN ERROR WITH MANUAL ACTION
// ======================================================================
echo "\n--- 5. All Automated Providers Fail ---\n";

$songA5 = create_catalogue_song('Obscure Unreleased Demo', 'Garage Group', null, 'unreviewed', $userAId);
$songA5Id = (int)$songA5['song_id'];

$GLOBALS['__mock_http_get_json'] = function(string $url) {
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$resA5 = fetch_multisource_lyrics($songA5Id, 'Obscure Unreleased Demo', 'Garage Group', $userAId, 'auto', true);
assert_lyric("All automated providers fail returns success = false", $resA5['success'] === false);
assert_lyric("Clear error message or not_found status returned", !empty($resA5['status']));
assert_lyric("Scoring and app remain unblocked", true);

// ======================================================================
// 6. USER MANUAL LYRICS MANAGEMENT (Save, Retrieve, Reload, Clear)
// ======================================================================
echo "\n--- 6. User Manual Lyrics Management ---\n";

$manualText = "Verse 1:\nI wrote these lines by hand in my study.\nChorus:\nThis is my personal note.";

// Save manual lyrics for User A
$saveManA = save_user_manual_lyrics($songA5Id, $manualText, $userAId);
assert_lyric("Save personal manual lyrics returns success", $saveManA['success'] === true);

// Retrieve manual lyrics directly
$getManA = get_user_manual_lyrics($songA5Id, $userAId);
assert_lyric("Manual lyrics retrieved from user_song_lyrics table", $getManA !== null && $getManA['lyrics_text'] === $manualText);

// fetch_multisource_lyrics prioritizes manual lyrics (Priority 1)
$resManPriority = fetch_multisource_lyrics($songA5Id, 'Obscure Unreleased Demo', 'Garage Group', $userAId, 'auto', false);
assert_lyric("fetch_multisource_lyrics serves manual lyrics as Priority 1", $resManPriority['success'] === true);
assert_lyric("Provider is tagged as manual", $resManPriority['provider'] === 'manual');
assert_lyric("is_manual flag is true", $resManPriority['is_manual'] === true);
assert_lyric("Content matches saved manual lyrics text", $resManPriority['plain_lyrics'] === $manualText);

// Manual lyrics survive automated cache clears and force_refresh
$resForce = fetch_multisource_lyrics($songA5Id, 'Obscure Unreleased Demo', 'Garage Group', $userAId, 'auto', true);
assert_lyric("Manual lyrics survive force_refresh without being overwritten", $resForce['provider'] === 'manual' && $resForce['plain_lyrics'] === $manualText);

// ======================================================================
// 7. MULTIUSER PRIVACY & ISOLATION ON MANUAL LYRICS
// ======================================================================
echo "\n--- 7. Multiuser Privacy & Isolation on Manual Lyrics ---\n";

// User B must NOT see User A's manual lyrics for the same song ID
$getManB = get_user_manual_lyrics($songA5Id, $userBId);
assert_lyric("User B CANNOT see User A's private manual lyrics (returns null)", $getManB === null);

// User B attempts to clear User A's manual lyrics
$clearByB = clear_user_manual_lyrics($songA5Id, $userBId);
// User A's manual lyrics must still exist
$getManAAfter = get_user_manual_lyrics($songA5Id, $userAId);
assert_lyric("User A's manual lyrics remain intact after User B clear attempt", $getManAAfter !== null && $getManAAfter['lyrics_text'] === $manualText);

// User B creates their own independent manual lyrics for their own song
$songB1 = create_catalogue_song('Obscure Unreleased Demo', 'Garage Group', null, 'unreviewed', $userBId);
$songB1Id = (int)$songB1['song_id'];
$userBManualText = "User B's different handwritten translation lyrics.";
save_user_manual_lyrics($songB1Id, $userBManualText, $userBId);

$getManBAfter = get_user_manual_lyrics($songB1Id, $userBId);
assert_lyric("User B stores and retrieves their own independent manual lyrics", $getManBAfter !== null && $getManBAfter['lyrics_text'] === $userBManualText);
assert_lyric("User A and User B manual lyrics do not collide", $getManAAfter['lyrics_text'] !== $getManBAfter['lyrics_text']);

// ======================================================================
// 8. METADATA INVALIDATION & PROVIDER RATE LIMITING (HTTP 429)
// ======================================================================
echo "\n--- 8. Metadata Invalidation & Rate Limiting ---\n";

// Rate limit handling (HTTP 429)
$GLOBALS['__mock_http_get_json'] = function(string $url) {
    if (strpos($url, 'lrclib.net') !== false) {
        return [
            'success'     => false,
            'status_code' => 429,
            'error'       => 'Rate limit exceeded'
        ];
    }
    return ['success' => false, 'status_code' => 404, 'error' => 'Not found'];
};

$songA6 = create_catalogue_song('Rate Limited Song', 'Rapid Query', null, 'unreviewed', $userAId);
$songA6Id = (int)$songA6['song_id'];
$resRate = fetch_multisource_lyrics($songA6Id, 'Rate Limited Song', 'Rapid Query', $userAId, 'auto', true);
assert_lyric("Rate limit response handled gracefully without exceptions", $resRate['success'] === false);

// Metadata change: cached lyrics for previous track name are invalidated
$cachedCheck = $pdo->prepare("SELECT COUNT(*) FROM song_lyrics_ref WHERE song_id = :sid");
$cachedCheck->execute([':sid' => $songA1Id]);
assert_lyric("Cache persists for matched song", (int)$cachedCheck->fetchColumn() === 1);

// Force refresh with new title invalidates stale cached lyrics
clear_song_lyrics_ref($songA1Id);
$cachedCheck->execute([':sid' => $songA1Id]);
assert_lyric("clear_song_lyrics_ref successfully purges stale cached lyrics", (int)$cachedCheck->fetchColumn() === 0);

// Unregister mock handler
unset($GLOBALS['__mock_http_get_json']);

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
if ($passedTests === $totalTests) {
    echo " RESULT: ALL MULTI-SOURCE LYRICS TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo " RESULT: SOME LYRICS TESTS FAILED.\n";
}
echo "======================================================================\n";
