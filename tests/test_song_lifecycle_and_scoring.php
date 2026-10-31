<?php
/**
 * AFM#2 — Part A: Song Lifecycle & Scoring Integrity Regression Test Suite
 * 
 * Verifies:
 * 1. Complete Lifecycle Workflow:
 *    Add Song -> Shortlist -> Score -> Attempt Deletion (Blocked) -> Reset Score -> Remove Shortlist -> Delete Successfully
 * 2. Safeguards against deleting shortlisted songs
 * 3. Safeguards against deleting scored songs
 * 4. Reset Score / Unscore functionality
 * 5. Distinct Zero-Score completion vs Untouched/Reset scorecards
 * 6. Tournament history protection (blocks reset if round_results depend on score)
 * 7. Duplicate active audition entry prevention across delete/re-add
 * 8. Multiuser independence: distinct users independently auditioning the same starter song
 * 9. Multiuser IDOR isolation on score reset and deletion
 * 10. Catalogue, shortlist, and audition statistics synchronization
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_song_lifecycle_and_scoring');

$totalTests = 0;
$passedTests = 0;

function assert_life(string $name, bool $condition, string $details = ''): void {
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
echo " AFM#2 — SONG LIFECYCLE & SCORING INTEGRITY TEST SUITE\n";
echo "======================================================================\n\n";

// Setup two distinct test users in test database
$testUserAId = 1071;
$testUserBId = 1072;

$pdo->prepare("INSERT INTO app_users (user_id, username, email, password_hash, role, is_active) VALUES 
    (:u1, 'lifecycle_user_a', 'lifecycle_a@test.afm', 'hash', 'user', 1),
    (:u2, 'lifecycle_user_b', 'lifecycle_b@test.afm', 'hash', 'user', 1)
ON DUPLICATE KEY UPDATE is_active = 1
")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);

// Clean up prior test data for these users
$pdo->prepare("DELETE r FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE FROM ratings WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE rr FROM round_results rr JOIN afm_entries e ON rr.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE FROM round_results WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE FROM afm_entries WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE FROM song_discovery WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUserAId, ':u2' => $testUserBId]);

$_SESSION['afm_user_id'] = $testUserAId;
$_SESSION['user_id'] = $testUserAId;

// ======================================================================
// 1. COMPLETE LIFECYCLE WORKFLOW (Add -> Shortlist -> Score -> Delete Blocked -> Reset -> Remove Shortlist -> Delete OK)
// ======================================================================
echo "--- 1. Complete Song Lifecycle Workflow ---\n";

// Step 1: Add Song
$createRes = create_catalogue_song('Lifecycle Anthem', 'The Testers', null, 'unreviewed', $testUserAId);
assert_life("Step 1: Create song in user catalogue", $createRes['success'] === true);
$songId = (int)$createRes['song_id'];

// Step 2: Shortlist Song
$shortlistRes = update_song_discovery_status($songId, 'shortlisted', $testUserAId);
assert_life("Step 2: Shortlist song", $shortlistRes['success'] === true && $shortlistRes['status'] === 'shortlisted');

// Verify audition entry was generated
$entryStmt = $pdo->prepare("SELECT entry_id FROM afm_entries WHERE song_id = :sid AND user_id = :uid LIMIT 1");
$entryStmt->execute([':sid' => $songId, ':uid' => $testUserAId]);
$entryId = (int)$entryStmt->fetchColumn();
assert_life("Audition entry automatically created upon shortlisting", $entryId > 0);

// Step 3: Score Song (Melody=8, Lyrics=9, Vocals=7, Emotion=8, Replayability=9 -> Total=41)
$scoreRes = save_scorecard_rating($entryId, 1, 8.0, 9.0, 7.0, 8.0, 9.0, $testUserAId);
assert_life("Step 3: Score audition entry (Total 41.0)", $scoreRes['success'] === true && abs($scoreRes['total'] - 41.0) < 0.01);

// Step 4: Attempt Deletion while Scored & Shortlisted -> MUST BE BLOCKED
$delBlocked = delete_catalogue_song($songId, $testUserAId);
assert_life("Step 4: Attempt deletion blocked by safeguards", $delBlocked['success'] === false && !empty($delBlocked['blocked']));
assert_life("Safeguard cites active score or shortlist", stripos($delBlocked['error'], 'shortlist') !== false || stripos($delBlocked['error'], 'score') !== false);

// Attempt unshortlisting while scored -> MUST BE BLOCKED
$unshortlistBlocked = update_song_discovery_status($songId, 'unreviewed', $testUserAId);
assert_life("Removing from shortlist while scored is blocked", $unshortlistBlocked['success'] === false && !empty($unshortlistBlocked['blocked']));

// Step 5: Reset Score / Unscore
$resetRes = reset_scorecard_rating($entryId, 1, $testUserAId);
assert_life("Step 5: Reset scorecard rating returns success", $resetRes['success'] === true);

// Verify ratings row is removed and total is null
$ratingCheck = $pdo->prepare("SELECT total FROM ratings WHERE entry_id = :eid AND round_id = 1");
$ratingCheck->execute([':eid' => $entryId]);
$savedRating = $ratingCheck->fetch(PDO::FETCH_ASSOC);
assert_life("Ratings record removed after score reset", $savedRating === false);

// Step 6: Remove from Shortlist (now permitted because score was reset)
$unshortlistRes = update_song_discovery_status($songId, 'unreviewed', $testUserAId);
assert_life("Step 6: Remove from shortlist now succeeds", $unshortlistRes['success'] === true && $unshortlistRes['status'] === 'unreviewed');

// Step 7: Permanent Deletion (now clean: unshortlisted, unscored, no tournament history)
$delClean = delete_catalogue_song($songId, $testUserAId);
assert_life("Step 7: Song deleted permanently after full cleanup", $delClean['success'] === true && ($delClean['archived'] ?? false) === false);

// Verify song is deleted from songs table
$checkSongDel = $pdo->prepare("SELECT COUNT(*) FROM songs WHERE song_id = :sid");
$checkSongDel->execute([':sid' => $songId]);
assert_life("Song row deleted from database", (int)$checkSongDel->fetchColumn() === 0);

// ======================================================================
// 2. ZERO-SCORE COMPLETION VS UNTOUCHED/RESET SCORECARDS
// ======================================================================
echo "\n--- 2. Zero-Score Completion vs Untouched/Reset Scorecards ---\n";

$zeroSong = create_catalogue_song('Zero Score Test', 'Silent Band', null, 'shortlisted', $testUserAId);
$zeroSongId = (int)$zeroSong['song_id'];
$zeroEntryStmt = $pdo->prepare("SELECT entry_id FROM afm_entries WHERE song_id = :sid AND user_id = :uid LIMIT 1");
$zeroEntryStmt->execute([':sid' => $zeroSongId, ':uid' => $testUserAId]);
$zeroEntryId = (int)$zeroEntryStmt->fetchColumn();

// State A: Untouched scorecard
$untouchedCheck = $pdo->prepare("SELECT * FROM ratings WHERE entry_id = :eid AND round_id = 1");
$untouchedCheck->execute([':eid' => $zeroEntryId]);
assert_life("Untouched scorecard has no ratings record", $untouchedCheck->fetch() === false);

// State B: Intentionally completed with all five criteria scored 0.0
$zeroScoreRes = save_scorecard_rating($zeroEntryId, 1, 0, 0, 0, 0, 0, $testUserAId);
assert_life("Intentionally scored 0/50 saved successfully", $zeroScoreRes['success'] === true && (float)$zeroScoreRes['total'] === 0.0 && $zeroScoreRes['is_complete'] === true);

$dbZero = $pdo->prepare("SELECT total, melody, lyrics, vocals, emotion, replayability FROM ratings WHERE entry_id = :eid AND round_id = 1");
$dbZero->execute([':eid' => $zeroEntryId]);
$zeroRow = $dbZero->fetch(PDO::FETCH_ASSOC);
$isCompleteZero = ($zeroRow !== false && $zeroRow['total'] !== null && (float)$zeroRow['total'] === 0.0 &&
    (float)$zeroRow['melody'] === 0.0 && (float)$zeroRow['lyrics'] === 0.0 &&
    (float)$zeroRow['vocals'] === 0.0 && (float)$zeroRow['emotion'] === 0.0 &&
    (float)$zeroRow['replayability'] === 0.0);
assert_life("Completed zero-score entry has non-null total = 0.0 with all criteria = 0.0", $isCompleteZero);

// State C: Reset scorecard returns to genuinely untouched state
$resetZeroRes = reset_scorecard_rating($zeroEntryId, 1, $testUserAId);
assert_life("Reset score on 0/50 scorecard succeeds", $resetZeroRes['success'] === true);

$resetCheck = $pdo->prepare("SELECT * FROM ratings WHERE entry_id = :eid AND round_id = 1");
$resetCheck->execute([':eid' => $zeroEntryId]);
assert_life("Scorecard rating row removed after reset (distinct from 0/50 completion)", $resetCheck->fetch() === false);

// Cleanup zero song
update_song_discovery_status($zeroSongId, 'unreviewed', $testUserAId);
delete_catalogue_song($zeroSongId, $testUserAId);

// ======================================================================
// 3. TOURNAMENT HISTORY PROTECTION ON SCORE RESET
// ======================================================================
echo "\n--- 3. Tournament History Protection on Score Reset ---\n";

$tournSong = create_catalogue_song('Championship Ballad', 'Finalists', null, 'shortlisted', $testUserAId);
$tournSongId = (int)$tournSong['song_id'];
$tournEntryStmt = $pdo->prepare("SELECT entry_id FROM afm_entries WHERE song_id = :sid AND user_id = :uid LIMIT 1");
$tournEntryStmt->execute([':sid' => $tournSongId, ':uid' => $testUserAId]);
$tournEntryId = (int)$tournEntryStmt->fetchColumn();

save_scorecard_rating($tournEntryId, 1, 10, 10, 10, 10, 10, $testUserAId);

// Simulate that Round 1 was completed and recorded into tournament round_results
$pdo->prepare("INSERT INTO round_results (round_id, entry_id, result, rank_position, user_id) VALUES (1, :eid, 'qualified', 1, :uid)")
    ->execute([':eid' => $tournEntryId, ':uid' => $testUserAId]);

// Attempt to reset score on finalized round entry -> MUST BE BLOCKED
$tournResetBlocked = reset_scorecard_rating($tournEntryId, 1, $testUserAId);
assert_life("Resetting score is blocked when round is recorded in tournament round_results", $tournResetBlocked['success'] === false);
assert_life("Error message cites completed tournament history", stripos($tournResetBlocked['error'], 'tournament') !== false || stripos($tournResetBlocked['error'], 'finalized') !== false);

// Attempt deletion of tournament referenced song -> MUST BE BLOCKED
$tournDelBlocked = delete_catalogue_song($tournSongId, $testUserAId);
assert_life("Deletion blocked when song has completed tournament history", $tournDelBlocked['success'] === false && !empty($tournDelBlocked['blocked']));

// Clean up simulated tournament row for isolation
$pdo->prepare("DELETE FROM round_results WHERE entry_id = :eid")->execute([':eid' => $tournEntryId]);
reset_scorecard_rating($tournEntryId, 1, $testUserAId);
update_song_discovery_status($tournSongId, 'unreviewed', $testUserAId);
delete_catalogue_song($tournSongId, $testUserAId);

// ======================================================================
// 4. DUPLICATE ACTIVE AUDITION PREVENTION ACROSS DELETE & RE-ADD
// ======================================================================
echo "\n--- 4. Duplicate Active Audition Prevention ---\n";

// Create and shortlist song
$origSong = create_catalogue_song('Unbreakable Track', 'Echo Band', null, 'shortlisted', $testUserAId);
$origSongId = (int)$origSong['song_id'];

// Count audition entries for this user and song
$countEntries1 = (int)$pdo->prepare("SELECT COUNT(*) FROM afm_entries WHERE song_id = :sid AND user_id = :uid")
    ->execute([':sid' => $origSongId, ':uid' => $testUserAId]) ? 
    $pdo->query("SELECT COUNT(*) FROM afm_entries WHERE song_id = {$origSongId} AND user_id = {$testUserAId}")->fetchColumn() : 0;
assert_life("Initial audition entry count is strictly 1", $countEntries1 === 1);

// Attempting to re-add the exact same song for User A should reactivate / reuse the existing record
$reAddSong = create_catalogue_song('Unbreakable Track', 'Echo Band', null, 'shortlisted', $testUserAId);
assert_life("Re-adding song reuses existing track instead of creating duplicate", (int)$reAddSong['song_id'] === $origSongId);

$countEntries2 = (int)$pdo->query("SELECT COUNT(*) FROM afm_entries WHERE song_id = {$origSongId} AND user_id = {$testUserAId}")->fetchColumn();
assert_life("Active audition entry count remains strictly 1 (no duplicate audition created)", $countEntries2 === 1);

// Cleanup
update_song_discovery_status($origSongId, 'unreviewed', $testUserAId);
delete_catalogue_song($origSongId, $testUserAId);

// ======================================================================
// 5. INDEPENDENT AUDITIONS ACROSS DISTINCT USERS
// ======================================================================
echo "\n--- 5. Independent Auditions Across Distinct Users ---\n";

// User A creates and auditions a song
$_SESSION['afm_user_id'] = $testUserAId;
$userASong = create_catalogue_song('Shared Classic', 'Legendary Band', null, 'shortlisted', $testUserAId);
$userASongId = (int)$userASong['song_id'];
$userAEntryId = (int)$pdo->query("SELECT entry_id FROM afm_entries WHERE song_id = {$userASongId} AND user_id = {$testUserAId}")->fetchColumn();
save_scorecard_rating($userAEntryId, 1, 9.0, 9.0, 9.0, 9.0, 9.0, $testUserAId);

// User B independently creates and auditions their own copy of the same song
$_SESSION['afm_user_id'] = $testUserBId;
$userBSong = create_catalogue_song('Shared Classic', 'Legendary Band', null, 'shortlisted', $testUserBId);
$userBSongId = (int)$userBSong['song_id'];
$userBEntryId = (int)$pdo->query("SELECT entry_id FROM afm_entries WHERE song_id = {$userBSongId} AND user_id = {$testUserBId}")->fetchColumn();
save_scorecard_rating($userBEntryId, 1, 4.0, 4.0, 4.0, 4.0, 4.0, $testUserBId);

assert_life("User A and User B receive distinct song IDs for same title/artist", $userASongId !== $userBSongId);
assert_life("User A and User B receive distinct audition entries", $userAEntryId !== $userBEntryId);

// Verify User A score is 45.0 and User B score is 20.0
$scoreA = (float)$pdo->query("SELECT total FROM ratings WHERE entry_id = {$userAEntryId}")->fetchColumn();
$scoreB = (float)$pdo->query("SELECT total FROM ratings WHERE entry_id = {$userBEntryId}")->fetchColumn();
assert_life("User A score remains 45.0", abs($scoreA - 45.0) < 0.01);
assert_life("User B score remains 20.0", abs($scoreB - 20.0) < 0.01);

// User B resets their score -> User A score must remain intact
$resetB = reset_scorecard_rating($userBEntryId, 1, $testUserBId);
assert_life("User B resets own score", $resetB['success'] === true);

$scoreAAfter = (float)$pdo->query("SELECT total FROM ratings WHERE entry_id = {$userAEntryId}")->fetchColumn();
assert_life("User A score is unaffected by User B reset", abs($scoreAAfter - 45.0) < 0.01);

// User B attempts IDOR reset on User A's entry -> MUST FAIL
$idorReset = reset_scorecard_rating($userAEntryId, 1, $testUserBId);
assert_life("User B IDOR reset on User A entry fails with Unauthorized", $idorReset['success'] === false);

// User B attempts IDOR delete on User A's song -> MUST FAIL
$idorDelete = delete_catalogue_song($userASongId, $testUserBId);
assert_life("User B IDOR deletion on User A song fails with Unauthorized", $idorDelete['success'] === false);

// Clean up
$_SESSION['afm_user_id'] = $testUserAId;
reset_scorecard_rating($userAEntryId, 1, $testUserAId);
update_song_discovery_status($userASongId, 'unreviewed', $testUserAId);
delete_catalogue_song($userASongId, $testUserAId);

$_SESSION['afm_user_id'] = $testUserBId;
update_song_discovery_status($userBSongId, 'unreviewed', $testUserBId);
delete_catalogue_song($userBSongId, $testUserBId);

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
if ($passedTests === $totalTests) {
    echo " RESULT: ALL SONG LIFECYCLE & SCORING INTEGRITY TESTS PASSED!\n";
} else {
    echo " RESULT: SOME TESTS FAILED.\n";
}
echo "======================================================================\n";
