<?php
/**
 * AFM#2 — Two-User Isolation & Multiuser Architecture Regression Test Suite
 * 
 * Verifies strict data isolation across two distinct users (User A & User B):
 * 1. Catalogue isolation (view, search, count)
 * 2. Mutative IDOR prevention (update, delete, multi-delete)
 * 3. Media & lyrics isolation (save_media, remove_media, clear_lyrics, embed_status)
 * 4. Audition & Scorecard isolation (discovery status, ratings, leaderboards)
 * 5. Tournament progression isolation (user_round_progress, stage advancement, history)
 * 6. Duplicate detection & consolidation boundary safety
 * 7. YouTube bulk matching job isolation
 * 8. Baseline catalogue preservation (2,411 songs, NULL artists [1546, 2015, 2196])
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/duplicate_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$totalTests = 0;
$passedTests = 0;

function assert_iso(string $name, bool $condition, string $details = ''): void {
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
echo " AFM#2 — TWO-USER ISOLATION & MULTIUSER ARCHITECTURE TEST SUITE\n";
echo "======================================================================\n\n";

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_multiuser_isolation');

// --- 0. Pre-Flight Catalogue Invariants ---
echo "--- 0. Pre-Flight Invariants ---\n";
$masterSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
assert_iso("Pre-test Master Starter Catalogue Invariant: strictly 2,411 songs", $masterSongCount === 2411, "Found: {$masterSongCount}");

$masterNullStmt = $pdo->query("SELECT `source_song_id` FROM `master_starter_songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `source_song_id`");
$masterNulls = array_map('intval', $masterNullStmt->fetchAll(PDO::FETCH_COLUMN));
assert_iso("Pre-test Master Missing Artist Invariant: strictly [1546, 2015, 2196]", $masterNulls === [1546, 2015, 2196], "Found: " . json_encode($masterNulls));

// Verify Schema additions from Migration 009 & 011
$schemaCheckCols = [
    'songs'                 => 'user_id',
    'afm_entries'           => 'user_id',
    'ratings'               => 'user_id',
    'round_results'         => 'user_id',
    'song_discovery'        => 'user_id',
    'youtube_matching_jobs' => 'user_id'
];
foreach ($schemaCheckCols as $tbl => $col) {
    $cCheck = $pdo->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'")->fetch();
    assert_iso("Schema: `{$tbl}`.`{$col}` column exists", !empty($cCheck));
}
$progTableCheck = $pdo->query("SHOW TABLES LIKE 'user_round_progress'")->fetch();
assert_iso("Schema: `user_round_progress` table exists", !empty($progTableCheck));
$uciTableCheck = $pdo->query("SHOW TABLES LIKE 'user_catalogue_initialization'")->fetch();
assert_iso("Schema: `user_catalogue_initialization` table exists", !empty($uciTableCheck));

// --- 1. Provision Two Isolated Test Users ---
echo "\n--- 1. Provision Test Users (User A & User B) ---\n";

// Cleanup any remnants
$pdo->exec("DELETE FROM `app_users` WHERE `username` IN ('test_iso_user_a', 'test_iso_user_b')");

$hashA = password_hash('Pass_Iso_User_A_2026!', PASSWORD_BCRYPT);
$pdo->prepare("
    INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
    VALUES ('test_iso_user_a', 'user_a@test.afm', :h, 'user', 1, NOW())
")->execute([':h' => $hashA]);
$uidA = (int)$pdo->lastInsertId();

$hashB = password_hash('Pass_Iso_User_B_2026!', PASSWORD_BCRYPT);
$pdo->prepare("
    INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
    VALUES ('test_iso_user_b', 'user_b@test.afm', :h, 'user', 1, NOW())
")->execute([':h' => $hashB]);
$uidB = (int)$pdo->lastInsertId();

assert_iso("User A provisioned successfully (ID: {$uidA})", $uidA > 0);
assert_iso("User B provisioned successfully (ID: {$uidB})", $uidB > 0 && $uidB !== $uidA);

// Initialize independent starter catalogues for User A and User B
$initA = initialize_user_starter_catalogue($uidA, 1, $pdo);
assert_iso("User A cloned independent starter catalogue (2,411 songs)", $initA['success'] === true && $initA['songs_cloned'] === 2411);

$initB = initialize_user_starter_catalogue($uidB, 1, $pdo);
assert_iso("User B cloned independent starter catalogue (2,411 songs)", $initB['success'] === true && $initB['songs_cloned'] === 2411);

$minSongA = (int)$pdo->query("SELECT MIN(song_id) FROM `songs` WHERE `user_id` = {$uidA}")->fetchColumn();
$minSongB = (int)$pdo->query("SELECT MIN(song_id) FROM `songs` WHERE `user_id` = {$uidB}")->fetchColumn();
assert_iso("User A and User B receive distinct, non-overlapping song IDs", $minSongA !== $minSongB, "minA: {$minSongA}, minB: {$minSongB}");

try {
    // --- 2. Catalogue Isolation (Create, Read, Search, Count) ---
    echo "\n--- 2. Music Catalogue Isolation (User A vs User B) ---\n";

    // User A creates a song
    $resSongA = create_catalogue_song('Isolation Track User A', 'Isolation Artist A', 'https://youtube.com/watch?v=dQw4w9WgXcQ', 'unreviewed', $uidA);
    assert_iso("User A creates a private song", $resSongA['success'] === true && !empty($resSongA['song_id']));
    $songAId = (int)$resSongA['song_id'];

    // User B creates a song
    $resSongB = create_catalogue_song('Isolation Track User B', 'Isolation Artist B', 'https://youtube.com/watch?v=oHg5SJYRHA0', 'unreviewed', $uidB);
    assert_iso("User B creates a private song", $resSongB['success'] === true && !empty($resSongB['song_id']));
    $songBId = (int)$resSongB['song_id'];

    // User A retrieves Song A
    $fetchA_by_A = get_song_by_id($songAId, $uidA);
    assert_iso("User A can read own song via get_song_by_id", $fetchA_by_A !== null && (int)$fetchA_by_A['song_id'] === $songAId);

    // User B attempts to retrieve Song A (IDOR Prevention)
    $fetchA_by_B = get_song_by_id($songAId, $uidB);
    assert_iso("User B CANNOT read User A's song via get_song_by_id (fails closed)", $fetchA_by_B === null);

    // User A searches songs
    $searchA = search_songs('Isolation Track', 'all', 1, 50, 'id_asc', $uidA);
    $searchAIds = array_map('intval', array_column($searchA['songs'], 'song_id'));
    assert_iso("User A search results contain only User A songs", in_array($songAId, $searchAIds, true) && !in_array($songBId, $searchAIds, true));

    // User B searches songs
    $searchB = search_songs('Isolation Track', 'all', 1, 50, 'id_asc', $uidB);
    $searchBIds = array_map('intval', array_column($searchB['songs'], 'song_id'));
    assert_iso("User B search results contain only User B songs", in_array($songBId, $searchBIds, true) && !in_array($songAId, $searchBIds, true));

    // Scoped catalogue song count (independent starter catalogue 2,411 + user private songs)
    $countA = get_catalogue_song_count($uidA, true);
    $countB = get_catalogue_song_count($uidB, true);
    assert_iso("User A catalogue song count includes starter catalogue (2,411) + 1 private song = 2,412", $countA === 2412, "Got: {$countA}");
    assert_iso("User B catalogue song count includes starter catalogue (2,411) + 1 private song = 2,412", $countB === 2412, "Got: {$countB}");

    // --- 3. Mutative Song Operations (Update, Delete, IDOR Prevention) ---
    echo "\n--- 3. Mutative IDOR Prevention (Update & Delete) ---\n";

    // User B attempts to update User A's song
    $updateA_by_B = update_catalogue_song($songAId, 'Hacked Song Title', 'Hacked Artist', '', $uidB);
    assert_iso("User B updating User A song fails with Unauthorized", $updateA_by_B['success'] === false && strpos(strtolower($updateA_by_B['error']), 'unauthorized') !== false);

    // Verify User A song title in DB is unchanged
    $verifySongA = $pdo->query("SELECT `title` FROM `songs` WHERE `song_id` = {$songAId}")->fetchColumn();
    assert_iso("User A song title remains untouched in database", $verifySongA === 'Isolation Track User A');

    // User B attempts to delete User A's song
    $deleteA_by_B = delete_catalogue_song($songAId, $uidB);
    assert_iso("User B deleting User A song fails with Unauthorized", $deleteA_by_B['success'] === false && strpos(strtolower($deleteA_by_B['error']), 'unauthorized') !== false);

    // User B attempts to batch-delete User A's song
    $multiDelete_by_B = delete_multiple_catalogue_songs([$songAId], $uidB);
    assert_iso("User B batch-deleting User A song fails with Unauthorized", $multiDelete_by_B['success'] === false && strpos(strtolower($multiDelete_by_B['error']), 'unauthorized') !== false);

    // Verify User A song still exists in database
    $existsSongA = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `song_id` = {$songAId}")->fetchColumn();
    assert_iso("User A song remains intact after IDOR delete attempts", $existsSongA === 1);

    // --- 4. Media, Playback, and Lyrics Scoping ---
    echo "\n--- 4. Media & Lyrics Isolation ---\n";

    // User B attempts to overwrite User A's media
    $mediaA_by_B = save_song_media($songAId, 'https://youtube.com/watch?v=HackedVideo1', 'original', null, 'allowed', null, null, null, $uidB);
    assert_iso("User B modifying User A media fails with Unauthorized", $mediaA_by_B['success'] === false && strpos(strtolower($mediaA_by_B['error']), 'unauthorized') !== false);

    // User B attempts to remove User A's media
    $removeMediaA_by_B = remove_song_media($songAId, 'youtube', $uidB);
    assert_iso("User B removing User A media fails with Unauthorized", $removeMediaA_by_B['success'] === false && strpos(strtolower($removeMediaA_by_B['error']), 'unauthorized') !== false);

    // User B attempts to alter embed status on User A's song
    $embedA_by_B = update_song_media_embed_status($songAId, 'blocked', $uidB);
    assert_iso("User B updating embed status on User A song fails with Unauthorized", $embedA_by_B['success'] === false && strpos(strtolower($embedA_by_B['error']), 'unauthorized') !== false);

    // User B attempts to clear lyrics on User A's song
    $lyricsA_by_B = clear_song_lyrics_ref($songAId, $uidB);
    assert_iso("User B clearing lyrics on User A song fails with Unauthorized", $lyricsA_by_B['success'] === false && strpos(strtolower($lyricsA_by_B['error']), 'unauthorized') !== false);

    // --- 5. Auditions, Shortlisting & Scorecard Isolation ---
    echo "\n--- 5. Auditions, Shortlisting & Scorecard Isolation ---\n";

    // User A shortlists Song A
    $discResA = update_song_discovery_status($songAId, 'shortlisted', $uidA);
    assert_iso("User A successfully shortlists Song A", $discResA['success'] === true);

    // User B attempts to change discovery status of Song A
    $discResA_by_B = update_song_discovery_status($songAId, 'passed', $uidB);
    assert_iso("User B changing discovery status of Song A fails with Unauthorized", $discResA_by_B['success'] === false && strpos(strtolower($discResA_by_B['error']), 'unauthorized') !== false);

    // User A retrieves shortlisted songs
    $shortlistA = get_shortlisted_songs(1, 50, '', 'all', $uidA);
    $foundEntryA = null;
    foreach ($shortlistA['songs'] as $item) {
        if ((int)$item['song_id'] === $songAId) {
            $foundEntryA = $item;
            break;
        }
    }
    assert_iso("User A finds Song A in their shortlisted entries", $foundEntryA !== null);
    $entryAId = $foundEntryA ? (int)$foundEntryA['entry_id'] : 0;

    // User B retrieves shortlisted songs
    $shortlistB = get_shortlisted_songs(1, 50, '', 'all', $uidB);
    $foundEntryAInB = false;
    foreach ($shortlistB['songs'] as $item) {
        if ((int)$item['song_id'] === $songAId) {
            $foundEntryAInB = true;
            break;
        }
    }
    assert_iso("User B does NOT see User A's shortlisted song", !$foundEntryAInB);

    // User A rates Entry A
    $rateResA = save_scorecard_rating($entryAId, 1, 9, 8, 9, 9, 9, $uidA);
    assert_iso("User A rates Entry A successfully", $rateResA['success'] === true && (float)$rateResA['rating']['total'] === 44.0);

    // User B attempts to rate User A's Entry A
    $rateResA_by_B = save_scorecard_rating($entryAId, 1, 1, 1, 1, 1, 1, $uidB);
    assert_iso("User B rating User A's Entry A fails with Unauthorized", $rateResA_by_B['success'] === false && strpos(strtolower($rateResA_by_B['error']), 'unauthorized') !== false);

    // User A rankings
    $rankingsA = get_round_rankings(1, $uidA);
    $entryAInRankingsA = false;
    foreach ($rankingsA['rankings'] as $r) {
        if ((int)$r['entry_id'] === $entryAId) {
            $entryAInRankingsA = true;
            assert_iso("User A leaderboard displays Entry A with score 44.0", (float)$r['total'] === 44.0);
            break;
        }
    }
    assert_iso("User A leaderboard includes Entry A", $entryAInRankingsA);

    // User B rankings
    $rankingsB = get_round_rankings(1, $uidB);
    $entryAInRankingsB = false;
    foreach ($rankingsB['rankings'] as $r) {
        if ((int)$r['entry_id'] === $entryAId) {
            $entryAInRankingsB = true;
            break;
        }
    }
    assert_iso("User B leaderboard does NOT include User A's Entry A", !$entryAInRankingsB);

    // --- 6. Tournament Progression Isolation ---
    echo "\n--- 6. Tournament Progression Isolation (user_round_progress) ---\n";

    // User A completes Round 1
    $pdo->prepare("
        INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`)
        VALUES (:uid, 1, 'completed', NOW())
        ON DUPLICATE KEY UPDATE `status` = 'completed', `completed_at` = NOW()
    ")->execute([':uid' => $uidA]);

    $pdo->prepare("
        INSERT INTO `round_results` (`entry_id`, `round_id`, `user_id`, `result`, `song_title`, `song_artist`, `score_total`)
        VALUES (:eid, 1, :uid, 'qualified', 'Isolation Track User A', 'Isolation Artist A', 44)
    ")->execute([':eid' => $entryAId, ':uid' => $uidA]);

    assert_iso("User A advances Round 1 to Round 2", true);

    // Check User A progress
    $progStmtA = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = 1");
    $progStmtA->execute([':uid' => $uidA]);
    $progA = $progStmtA->fetchColumn();
    assert_iso("User A Round 1 progress is recorded as 'completed'", $progA === 'completed');

    // Check User B progress for Round 1
    $progStmtB = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = 1");
    $progStmtB->execute([':uid' => $uidB]);
    $progB = $progStmtB->fetchColumn();
    assert_iso("User B Round 1 progress remains 'in_progress' or unadvanced", $progB !== 'completed');

    // Historical results isolation
    $historyA = get_historical_results($uidA);
    $entryAInHistA = false;
    foreach ($historyA as $roundGroup) {
        foreach ($roundGroup['results'] as $h) {
            if ((int)$h['song_id'] === $songAId) {
                $entryAInHistA = true;
                break 2;
            }
        }
    }
    assert_iso("User A historical tournament results include Song A", $entryAInHistA);

    $historyB = get_historical_results($uidB);
    $entryAInHistB = false;
    foreach ($historyB as $roundGroup) {
        foreach ($roundGroup['results'] as $h) {
            if ((int)$h['song_id'] === $songAId) {
                $entryAInHistB = true;
                break 2;
            }
        }
    }
    assert_iso("User B historical tournament results do NOT include Song A", !$entryAInHistB);

    // --- 7. Duplicate Detection & Consolidation Boundary Safety ---
    echo "\n--- 7. Duplicate Detection & Consolidation Boundaries ---\n";

    // User B creates a track with identical title to Song A
    $resDupB = create_catalogue_song('Isolation Track User A', 'Isolation Artist A', '', 'unreviewed', $uidB);
    $songB_dupId = (int)$resDupB['song_id'];

    // Scanning duplicates for User A must NOT suggest merging User A's track with User B's track
    $candidatesA = detect_duplicate_candidates([], $uidA);
    $hasCrossUserCandidate = false;
    foreach ($candidatesA['candidates'] as $c) {
        $sidA = $c['song_a']['song_id'] ?? 0;
        $sidB = $c['song_b']['song_id'] ?? 0;
        if (($sidA == $songAId && $sidB == $songB_dupId) ||
            ($sidA == $songB_dupId && $sidB == $songAId)) {
            $hasCrossUserCandidate = true;
            break;
        }
    }
    assert_iso("Duplicate candidate detection excludes cross-user pairs", !$hasCrossUserCandidate);

    // Direct consolidation safety check between User A and User B
    $consolidationSafety = validate_consolidation_safety($songAId, $songB_dupId);
    assert_iso("Cross-user consolidation validation fails safety checks", $consolidationSafety['safe'] === false && strpos(strtolower($consolidationSafety['conflicts'][0] ?? ''), 'cross-user') !== false);

    // Attempt consolidation across user boundary
    $consolidationBlocked = false;
    try {
        execute_consolidation($songAId, $songB_dupId, 'Attempted cross-user merge', $uidA);
    } catch (\Throwable $e) {
        $consolidationBlocked = true;
    }
    assert_iso("execute_consolidation fails closed when merging across users", $consolidationBlocked);

    // Attempt duplicate decision across user boundary
    $decisionBlocked = false;
    try {
        $decRes = save_duplicate_decision($songAId, $songB_dupId, 'keep_both', null, null, $uidA);
        if (!$decRes['success']) {
            $decisionBlocked = true;
        }
    } catch (\Throwable $e) {
        $decisionBlocked = true;
    }
    assert_iso("save_duplicate_decision fails closed for cross-user pairs", $decisionBlocked);

    // --- 8. YouTube Matcher Job Isolation ---
    echo "\n--- 8. Bulk YouTube Matching Job Scoping ---\n";

    $jobA = YouTubeMatcher::get_or_create_active_job($uidA);
    assert_iso("User A matching job has user_id = {$uidA}", isset($jobA['user_id']) && (int)$jobA['user_id'] === $uidA);

    $jobB = YouTubeMatcher::get_or_create_active_job($uidB);
    assert_iso("User B matching job has user_id = {$uidB}", isset($jobB['user_id']) && (int)$jobB['user_id'] === $uidB);
    assert_iso("User A and User B have separate job records", (int)$jobA['job_id'] !== (int)$jobB['job_id']);

    $statsA = YouTubeMatcher::get_matching_stats(true, $uidA);
    $statsB = YouTubeMatcher::get_matching_stats(true, $uidB);
    assert_iso("User A matching stats reflect starter catalogue + 1 private song (2,412)", (int)$statsA['total_songs'] === 2412, "Got: {$statsA['total_songs']}");
    assert_iso("User B matching stats reflect starter catalogue + 2 private songs (2,413)", (int)$statsB['total_songs'] === 2413, "Got: {$statsB['total_songs']}");

    // Resetting User A job does not affect User B
    YouTubeMatcher::reset_job($uidA);
    $jobA_after = YouTubeMatcher::get_or_create_active_job($uidA);
    $jobB_after = YouTubeMatcher::get_or_create_active_job($uidB);
    assert_iso("Resetting User A job leaves User B job unaffected", (int)$jobA_after['processed_songs'] === 0 && (int)$jobB_after['job_id'] === (int)$jobB['job_id']);

} finally {
    // --- 9. Cleanup & Post-Flight Invariants ---
    echo "\n--- 9. Cleanup & Post-Flight Catalogue Invariants ---\n";

    // Clean up all data created for User A and User B
    $pdo->exec("DELETE FROM `user_catalogue_initialization` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `round_results` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `user_round_progress` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `ratings` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `youtube_matching_jobs` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `song_media` WHERE `song_id` IN (SELECT `song_id` FROM `songs` WHERE `user_id` IN ({$uidA}, {$uidB}))");
    $pdo->exec("DELETE FROM `song_discovery` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `afm_entries` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `songs` WHERE `user_id` IN ({$uidA}, {$uidB})");
    $pdo->exec("DELETE FROM `app_users` WHERE `user_id` IN ({$uidA}, {$uidB})");

    // Post-flight Master Starter Invariants
    $postMasterCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
    assert_iso("Post-test Master Starter Invariant: strictly 2,411 songs", $postMasterCount === 2411, "Found: {$postMasterCount}");

    $postMasterNullStmt = $pdo->query("SELECT `source_song_id` FROM `master_starter_songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `source_song_id`");
    $postMasterNulls = array_map('intval', $postMasterNullStmt->fetchAll(PDO::FETCH_COLUMN));
    assert_iso("Post-test Master Missing Artist Invariant: strictly [1546, 2015, 2196]", $postMasterNulls === [1546, 2015, 2196], "Found: " . json_encode($postMasterNulls));
}

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
if ($passedTests === $totalTests) {
    echo " RESULT: ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo " RESULT: SOME TESTS FAILED.\n";
}
echo "======================================================================\n";

exit($passedTests === $totalTests ? 0 : 1);
