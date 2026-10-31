<?php
/**
 * AFM#2 — Stage Finalization, Historical Integrity & Administrative Corrections
 * Automated Test Suite
 * 
 * Verifies all 14 required scenarios from Section 7:
 * Scenario 1:  Formal Auditions can be edited before finalization.
 * Scenario 2:  Formal Auditions shortlist becomes immutable after finalization.
 * Scenario 3:  Songs participating in finalized Formal Auditions cannot be permanently deleted.
 * Scenario 4:  Completed Formal Auditions scorecards cannot be reset or modified.
 * Scenario 5:  Eliminated songs remain historically accessible but cannot enter later stages automatically.
 * Scenario 6:  Only officially advanced participants can be scored in subsequent stages.
 * Scenario 7:  Every finalized tournament stage becomes immutable.
 * Scenario 8:  Finalized participant lists and advancement order cannot be changed through ordinary application operations.
 * Scenario 9:  Subsequent stages contain exactly the confirmed participants.
 * Scenario 10: Ordinary reopening and undo functionality are unavailable.
 * Scenario 11: Exceptional administrative corrections require authorization and a reason.
 * Scenario 12: Exceptional corrections preserve original decisions and create separate audit records.
 * Scenario 13: Historical records remain consistent across all tournament stages.
 * Scenario 14: Existing authentication and multiuser isolation remain intact.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_stage_finalization_and_immutability');

$totalTests = 0;
$passedTests = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
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
echo " AFM#2 — STAGE FINALIZATION & HISTORICAL INTEGRITY VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// Setup test users:
// User 1101: Regular user
// User 1102: Regular user (for multiuser isolation checks)
// User 1103: Admin user (for administrative correction checks)
$userRegular1 = 1101;
$userRegular2 = 1102;
$userAdmin    = 1103;

$pdo->prepare("INSERT INTO app_users (user_id, username, email, password_hash, role, is_active) VALUES 
    (:u1, 'immut_user_1', 'immut_1@test.afm', 'hash', 'user', 1),
    (:u2, 'immut_user_2', 'immut_2@test.afm', 'hash', 'user', 1),
    (:u3, 'immut_admin',  'immut_admin@test.afm', 'hash', 'admin', 1)
ON DUPLICATE KEY UPDATE role = VALUES(role), is_active = 1
")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);

// Purge any prior test artifacts for these users
$pdo->prepare("DELETE r FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM ratings WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE rr FROM round_results rr JOIN afm_entries e ON rr.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM round_results WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM round_results_history WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM tournament_admin_corrections WHERE target_user_id IN (:u1, :u2, :u3) OR admin_user_id IN (:u4, :u5, :u6)")->execute([
    ':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin,
    ':u4' => $userRegular1, ':u5' => $userRegular2, ':u6' => $userAdmin,
]);
$pdo->prepare("DELETE FROM afm_entries WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM song_discovery WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM user_round_progress WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);

// Seed user 1101 round progress: Stage 1 in_progress, Stage 2..7 upcoming
$rounds = $pdo->query("SELECT round_id, sequence_no FROM rounds ORDER BY sequence_no ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rounds as $r) {
    $st = ((int)$r['sequence_no'] === 1) ? 'in_progress' : 'upcoming';
    $pdo->prepare("INSERT INTO user_round_progress (user_id, round_id, status) VALUES (:u, :r, :s)")
        ->execute([':u' => $userRegular1, ':r' => $r['round_id'], ':s' => $st]);
}

// -------------------------------------------------------------------------------------------------
echo "--- Scenario 1: Formal Auditions Editable Before Finalization ---\n";
// Create 205 songs for user 1101, all shortlisted (Formal Auditions requires >= 201 to advance 200)
$pdo->beginTransaction();
$songIds = [];
$entryIds = [];
for ($i = 1; $i <= 205; $i++) {
    $stmt = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES (:t, :a, :u)");
    $stmt->execute([':t' => "Tourney Track {$i}", ':a' => "Artist {$i}", ':u' => $userRegular1]);
    $sid = (int)$pdo->lastInsertId();
    $songIds[$i] = $sid;

    $pdo->prepare("INSERT INTO `song_discovery` (`user_id`, `song_id`, `status`, `reviewed_at`) VALUES (:u, :s, 'shortlisted', NOW())")
        ->execute([':u' => $userRegular1, ':s' => $sid]);

    $eStmt = $pdo->prepare("INSERT INTO `afm_entries` (`user_id`, `song_id`, `status`) VALUES (:u, :s, 'auditioning')");
    $eStmt->execute([':u' => $userRegular1, ':s' => $sid]);
    $entryIds[$i] = (int)$pdo->lastInsertId();
}
$pdo->commit();
assert_test("205 candidates created & shortlisted for Formal Auditions", count($songIds) === 205);

// Test unshortlisting and re-shortlisting before finalization on Song 205
$unshort = update_song_discovery_status($songIds[205], 'passed', $userRegular1);
assert_test("Song 205 unshortlisted to 'passed' before finalization", $unshort['success']);
$reshort = update_song_discovery_status($songIds[205], 'shortlisted', $userRegular1);
assert_test("Song 205 re-shortlisted before finalization", $reshort['success']);
$entryStmt = $pdo->prepare("SELECT entry_id FROM afm_entries WHERE song_id = :sid AND user_id = :uid");
$entryStmt->execute([':sid' => $songIds[205], ':uid' => $userRegular1]);
$entryIds[205] = (int)$entryStmt->fetchColumn();

// Score song 1, then reset it before finalization
$score1 = save_scorecard_rating($entryIds[1], 1, 9, 8, 9, 8, 9, $userRegular1);
assert_test("Song 1 scored before finalization", $score1['success'] && (int)$score1['total'] === 43);
$reset1 = reset_scorecard_rating($entryIds[1], 1, $userRegular1);
assert_test("Song 1 scorecard reset before finalization", $reset1['success']);

// Score all 205 songs fully across all 5 criteria in Stage 1
$pdo->beginTransaction();
$rateStmt = $pdo->prepare("
    INSERT INTO `ratings` (`entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) 
    VALUES (:eid, 1, :uid, :m, :l, :v, :e, :r)
    ON DUPLICATE KEY UPDATE `melody` = VALUES(`melody`), `lyrics` = VALUES(`lyrics`), `vocals` = VALUES(`vocals`), `emotion` = VALUES(`emotion`), `replayability` = VALUES(`replayability`)
");
for ($i = 1; $i <= 205; $i++) {
    $val = 6 + ($i % 5);
    $rateStmt->execute([
        ':eid' => $entryIds[$i], 
        ':uid' => $userRegular1, 
        ':m'   => $val, 
        ':l'   => $val, 
        ':v'   => $val, 
        ':e'   => $val, 
        ':r'   => $val
    ]);
}
$pdo->commit();

$scoredCount = (int)$pdo->query("SELECT COUNT(*) FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id = {$userRegular1} AND r.round_id = 1 AND r.total IS NOT NULL")->fetchColumn();
assert_test("All 205 entries fully scored in Stage 1", $scoredCount === 205);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 2: Formal Auditions Shortlist Becomes Immutable After Finalization ---\n";
// Finalize Stage 1 by advancing top 200 songs to Stage 2 (Songs 201 to 205 eliminated)
$advQualifiers = array_slice($entryIds, 0, 200);
$manualOrderMap = [];
foreach ($advQualifiers as $idx => $eid) {
    $manualOrderMap[$eid] = $idx + 1;
}
$advRes = commit_stage_advancement(1, $advQualifiers, $userRegular1, $manualOrderMap);
assert_test("Stage 1 advancement committed successfully", $advRes['success'] && ($advRes['qualifiers_count'] ?? 0) === 200, $advRes['error'] ?? '');

// Check Stage 1 status is completed
$stg1 = get_round_by_id(1, $userRegular1);
assert_test("Stage 1 status is 'completed'", $stg1['status'] === 'completed');

// Attempt to add new song directly to shortlisted discovery status
$blockNewShortlist = create_catalogue_song("Late Track", "Late Artist", null, 'shortlisted', $userRegular1);
assert_test("Adding new song to finalized shortlist is strictly blocked", !$blockNewShortlist['success'], $blockNewShortlist['error'] ?? '');

// Attempt to change discovery status of existing shortlisted song
$blockStatusChange = update_song_discovery_status($songIds[1], 'passed', $userRegular1);
assert_test("Modifying shortlisted song status after finalization is blocked", !$blockStatusChange['success'], $blockStatusChange['error'] ?? '');

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 3: Songs Participating in Finalized Formal Auditions Cannot Be Permanently Deleted ---\n";
// Attempt to delete qualified song 1
$delQualified = delete_catalogue_song($songIds[1], $userRegular1);
assert_test("Qualified participating song cannot be permanently deleted", !$delQualified['success'] && ($delQualified['blocked'] ?? false));

// Attempt to delete eliminated song 201
$delEliminated = delete_catalogue_song($songIds[201], $userRegular1);
assert_test("Eliminated participating song cannot be permanently deleted", !$delEliminated['success'] && ($delEliminated['blocked'] ?? false));

// Attempt bulk deletion containing participating songs
$bulkDel = delete_multiple_catalogue_songs([$songIds[1], $songIds[201]], $userRegular1);
assert_test("Bulk deletion of participating songs is blocked", !$bulkDel['success'] && ($bulkDel['blocked'] ?? false));

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 4: Completed Formal Auditions Scorecards Cannot Be Reset or Modified ---\n";
// Attempt to modify score for entry 1 in finalized Stage 1
$modScore = save_scorecard_rating($entryIds[1], 1, 10, 10, 10, 10, 10, $userRegular1);
assert_test("Score modification in finalized Stage 1 is blocked", !$modScore['success'] && ($modScore['blocked'] ?? false));

// Verify original score unchanged
$origTotal = (int)$pdo->query("SELECT total FROM ratings WHERE entry_id = {$entryIds[1]} AND round_id = 1")->fetchColumn();
assert_test("Original scorecard score remains unchanged", $origTotal !== 50);

// Attempt to reset score in finalized Stage 1
$resetScore = reset_scorecard_rating($entryIds[1], 1, $userRegular1);
assert_test("Scorecard reset in finalized Stage 1 is blocked", !$resetScore['success'] && ($resetScore['blocked'] ?? false));

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 5: Eliminated Songs Remain Historically Accessible But Ineligible for Later Stages ---\n";
// Check eliminated song 201 in round_results for Stage 1
$elimStmt = $pdo->prepare("SELECT result, rank_position FROM round_results WHERE entry_id = :eid AND round_id = 1 AND user_id = :u");
$elimStmt->execute([':eid' => $entryIds[201], ':u' => $userRegular1]);
$elimRow = $elimStmt->fetch(PDO::FETCH_ASSOC);
assert_test("Eliminated song 201 is historically recorded as 'eliminated'", $elimRow && $elimRow['result'] === 'eliminated');

// Check candidates for Stage 2: eliminated songs 201..205 must NOT be present
$qDataStage2 = get_stage_qualification_candidates(2, $userRegular1);
assert_test("Stage 2 qualification candidates loaded", $qDataStage2['success']);
$candIdsStage2 = array_column($qDataStage2['candidates'], 'entry_id');
assert_test("Eliminated song 201 is NOT in Stage 2 candidates", !in_array($entryIds[201], $candIdsStage2, true));
assert_test("Eliminated song 205 is NOT in Stage 2 candidates", !in_array($entryIds[205], $candIdsStage2, true));
assert_test("Stage 2 candidate count is exactly 200", count($candIdsStage2) === 200);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 6: Only Officially Advanced Participants Can Be Scored in Subsequent Stages ---\n";
// Attempt to score eliminated song 201 in Stage 2
$scoreElimInStage2 = save_scorecard_rating($entryIds[201], 2, 8, 8, 8, 8, 8, $userRegular1);
assert_test("Scoring eliminated participant in Stage 2 is blocked", !$scoreElimInStage2['success'] && ($scoreElimInStage2['blocked'] ?? false));

// Score an officially advanced participant (Entry 1) in Stage 2
$scoreAdvInStage2 = save_scorecard_rating($entryIds[1], 2, 8, 8, 8, 8, 8, $userRegular1);
assert_test("Scoring officially advanced participant in Stage 2 succeeds", $scoreAdvInStage2['success'] && (int)$scoreAdvInStage2['total'] === 40);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 7 & 8: Every Finalized Tournament Stage Becomes Immutable ---\n";
// Score all 200 participants in Stage 2
$pdo->beginTransaction();
for ($i = 1; $i <= 200; $i++) {
    $pdo->prepare("INSERT INTO `ratings` (`entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) 
        VALUES (:eid, 2, :uid, 7, 7, 7, 7, 7)
        ON DUPLICATE KEY UPDATE `melody` = 7, `lyrics` = 7, `vocals` = 7, `emotion` = 7, `replayability` = 7")
        ->execute([':eid' => $entryIds[$i], ':uid' => $userRegular1]);
}
$pdo->commit();

// Advance top 100 to Stage 3 (Top 100)
$stage2Qualifiers = array_slice($entryIds, 0, 100);
$manualOrderStage2 = [];
foreach ($stage2Qualifiers as $idx => $eid) {
    $manualOrderStage2[$eid] = $idx + 1;
}
$adv2 = commit_stage_advancement(2, $stage2Qualifiers, $userRegular1, $manualOrderStage2);
assert_test("Stage 2 advancement committed successfully", $adv2['success'] && ($adv2['qualifiers_count'] ?? 0) === 100, $adv2['error'] ?? '');

// Verify Stage 2 is completed and immutable
$stg2 = get_round_by_id(2, $userRegular1);
assert_test("Stage 2 status is 'completed'", $stg2['status'] === 'completed');

// Try saving or resetting score in Stage 2
$modStg2 = save_scorecard_rating($entryIds[1], 2, 10, 10, 10, 10, 10, $userRegular1);
assert_test("Modifying completed Stage 2 evaluation is blocked", !$modStg2['success'] && ($modStg2['blocked'] ?? false));
$resetStg2 = reset_scorecard_rating($entryIds[1], 2, $userRegular1);
assert_test("Resetting completed Stage 2 scorecard is blocked", !$resetStg2['success'] && ($resetStg2['blocked'] ?? false));

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 9: Subsequent Stages Contain Exactly Confirmed Participants ---\n";
$qDataStage3 = get_stage_qualification_candidates(3, $userRegular1);
assert_test("Stage 3 qualification candidates retrieved", $qDataStage3['success']);
$candIdsStage3 = array_column($qDataStage3['candidates'], 'entry_id');
assert_test("Stage 3 candidate count is exactly 100", count($candIdsStage3) === 100);
assert_test("Stage 3 candidates match Stage 2 qualifiers exactly", $candIdsStage3 === $stage2Qualifiers);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 10: Ordinary Reopening and Undo Functionality Are Unavailable ---\n";
// Attempt to reopen stage with regular user
$reopenAttempt = reopen_tournament_stage(2, $userRegular1);
assert_test("Ordinary user stage reopening is disabled and blocked", !$reopenAttempt['success'] && ($reopenAttempt['blocked'] ?? false));

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 11: Exceptional Administrative Corrections Require Authorization and Reason ---\n";
// Attempt administrative correction by regular non-admin user
$nonAdminCorrect = admin_correct_tournament_stage(2, "Fixing technical corruptions in scoring", $userRegular1, $userRegular1);
assert_test("Non-admin user cannot authorize exceptional correction", !$nonAdminCorrect['success'] && strpos($nonAdminCorrect['error'], 'Unauthorized') !== false);

// Attempt administrative correction by admin user but without a reason
$shortReasonCorrect = admin_correct_tournament_stage(2, "short", $userAdmin, $userRegular1);
assert_test("Admin correction requires documented reason >= 10 characters", !$shortReasonCorrect['success'] && strpos($shortReasonCorrect['error'], 'at least 10 characters') !== false);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 12 & 13: Exceptional Corrections Preserve Decisions, Audit Trail & Cross-Stage Consistency ---\n";
// Execute valid administrative correction by admin user
$adminReason = "Technical audit: Recalibrating Stage 2 tie-breaker criteria for entry dispute #492";
$adminCorrect = admin_correct_tournament_stage(2, $adminReason, $userAdmin, $userRegular1);
assert_test("Authorized administrative correction executes successfully", $adminCorrect['success']);
assert_test("Correction ID generated", !empty($adminCorrect['correction_id']));

// Verify record in tournament_admin_corrections
$corrAudit = $pdo->prepare("SELECT * FROM tournament_admin_corrections WHERE correction_id = :cid");
$corrAudit->execute([':cid' => $adminCorrect['correction_id']]);
$auditRow = $corrAudit->fetch(PDO::FETCH_ASSOC);
assert_test("Audit entry logged in tournament_admin_corrections", !empty($auditRow));
assert_test("Audit logs correct admin user", (int)$auditRow['admin_user_id'] === $userAdmin);
assert_test("Audit logs correct target user", (int)$auditRow['target_user_id'] === $userRegular1);
assert_test("Audit logs mandatory reason", $auditRow['reason'] === $adminReason);

// Verify round_results_history has archived records linked with correction_id
$histStmt = $pdo->prepare("SELECT COUNT(*) FROM round_results_history WHERE correction_id = :cid AND user_id = :uid");
$histStmt->execute([':cid' => $adminCorrect['correction_id'], ':uid' => $userRegular1]);
$archivedCount = (int)$histStmt->fetchColumn();
assert_test("Previous Stage 2 decisions archived in round_results_history", $archivedCount === 200);

// Verify target stage is unlocked back to in_progress, subsequent stages to upcoming
$stg2After = get_round_by_id(2, $userRegular1);
assert_test("Target Stage 2 status reset to 'in_progress'", $stg2After['status'] === 'in_progress');
$stg3After = get_round_by_id(3, $userRegular1);
assert_test("Subsequent Stage 3 status reset to 'upcoming'", $stg3After['status'] === 'upcoming');

// Verify existing criteria scores in ratings remain intact
$ratingsRemain = (int)$pdo->query("SELECT COUNT(*) FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id = {$userRegular1} AND r.round_id = 2")->fetchColumn();
assert_test("Criteria scores in ratings table safely preserved after admin correction", $ratingsRemain === 200);

// -------------------------------------------------------------------------------------------------
echo "\n--- Scenario 14: Existing Authentication and Multiuser Isolation Intact ---\n";
// Create song and entry for User Regular 2
$u2Song = create_catalogue_song("User 2 Track", "User 2 Artist", null, 'shortlisted', $userRegular2);
assert_test("User 2 song created", $u2Song['success']);
$u2EntryStmt = $pdo->prepare("SELECT entry_id FROM afm_entries WHERE song_id = :sid AND user_id = :uid");
$u2EntryStmt->execute([':sid' => $u2Song['song_id'], ':uid' => $userRegular2]);
$u2EntryId = (int)$u2EntryStmt->fetchColumn();

// User 1 cannot score User 2's entry
$crossScore = save_scorecard_rating($u2EntryId, 1, 8, 8, 8, 8, 8, $userRegular1);
assert_test("Cross-user scoring is strictly rejected", !$crossScore['success'] && strpos($crossScore['error'], 'Unauthorized') !== false);

// User 1 cannot reset User 2's score
$crossReset = reset_scorecard_rating($u2EntryId, 1, $userRegular1);
assert_test("Cross-user score reset is strictly rejected", !$crossReset['success'] && strpos($crossReset['error'], 'Unauthorized') !== false);

// User 1 cannot delete User 2's song
$crossDelete = delete_catalogue_song($u2Song['song_id'], $userRegular1);
assert_test("Cross-user song deletion is strictly rejected", !$crossDelete['success']);

// User 2 cannot access User 1's qualification candidates
$u2Cand = get_stage_qualification_candidates(1, $userRegular2);
$u2CandIds = array_column($u2Cand['candidates'], 'entry_id');
assert_test("User 2 does not see User 1's entries in qualification data", !in_array($entryIds[1], $u2CandIds, true));

// Clean up test artifacts
$pdo->prepare("DELETE r FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM ratings WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE rr FROM round_results rr JOIN afm_entries e ON rr.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM round_results WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM round_results_history WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM tournament_admin_corrections WHERE target_user_id IN (:u1, :u2, :u3) OR admin_user_id IN (:u4, :u5, :u6)")->execute([
    ':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin,
    ':u4' => $userRegular1, ':u5' => $userRegular2, ':u6' => $userAdmin,
]);
$pdo->prepare("DELETE FROM afm_entries WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM song_discovery WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM user_round_progress WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);
$pdo->prepare("DELETE FROM app_users WHERE user_id IN (:u1, :u2, :u3)")->execute([':u1' => $userRegular1, ':u2' => $userRegular2, ':u3' => $userAdmin]);

echo "\n======================================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} Assertions Passed\n";
echo "======================================================================\n";

if ($passedTests === $totalTests) {
    echo "✓ ALL 14 SCENARIOS VERIFIED SUCCESSFULLY.\n";
    exit(0);
} else {
    echo "✗ SOME TESTS FAILED.\n";
    exit(1);
}
